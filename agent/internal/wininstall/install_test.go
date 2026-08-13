package wininstall

import (
	"context"
	"errors"
	"os"
	"path/filepath"
	"reflect"
	"strings"
	"testing"
)

func TestInstallPreflightsBeforeFreezeAndRepeatsMigrationAfterFreeze(t *testing.T) {
	directory := t.TempDir()
	request := fixtureRequest(t, directory)
	var events []string
	request.Activate = func(_ context.Context, _, output string) error {
		events = append(events, "activate")
		return os.WriteFile(output, []byte(validConfig(filepath.Join(request.StateDir, "queue.json"))), 0600)
	}
	request.Migrate = func(paths Paths) error {
		events = append(events, "migrate")
		if err := os.WriteFile(paths.StagedConfig, []byte(validConfig(filepath.Join(request.StateDir, "queue.json"))), 0600); err != nil {
			return err
		}
		return os.WriteFile(paths.StagedQueue, []byte("[]"), 0600)
	}
	request.Platform = &recordingPlatform{events: &events}

	if err := Install(context.Background(), request); err != nil {
		t.Fatal(err)
	}
	want := []string{
		"validate", "protect-stage", "activate", "migrate", "snapshot", "protect",
		"freeze", "migrate", "install", "service", "start", "verify", "delete-task",
	}
	if !reflect.DeepEqual(events, want) {
		t.Fatalf("events=%v want=%v", events, want)
	}
}

func TestInstallRollsBackWhenCommitFailsWithoutLeakingSecrets(t *testing.T) {
	directory := t.TempDir()
	request := fixtureRequest(t, directory)
	secret := strings.Repeat("a", 64)
	request.Activate = func(_ context.Context, _, output string) error {
		return os.WriteFile(output, []byte(validConfig(filepath.Join(request.StateDir, "queue.json"))), 0600)
	}
	request.Migrate = func(paths Paths) error {
		if err := os.WriteFile(paths.StagedConfig, []byte(validConfig(filepath.Join(request.StateDir, "queue.json"))), 0600); err != nil {
			return err
		}
		return os.WriteFile(paths.StagedQueue, []byte("[]"), 0600)
	}
	var events []string
	request.Platform = &recordingPlatform{events: &events, fail: "service", failure: errors.New("failed with " + secret)}

	err := Install(context.Background(), request)
	if err == nil {
		t.Fatal("Install succeeded")
	}
	if strings.Contains(err.Error(), secret) {
		t.Fatalf("secret leaked: %q", err)
	}
	if got := events[len(events)-1]; got != "rollback" {
		t.Fatalf("last event=%q events=%v", got, events)
	}
}

func TestInstallDoesNotRollbackBeforeExistingAgentIsFrozen(t *testing.T) {
	tests := []struct {
		name string
		fail string
	}{
		{name: "validate", fail: "validate"},
		{name: "protect stage", fail: "protect-stage"},
		{name: "snapshot", fail: "snapshot"},
		{name: "protect installed paths", fail: "protect"},
	}
	for _, test := range tests {
		t.Run(test.name, func(t *testing.T) {
			request := successfulRequest(t)
			var events []string
			request.Platform = &recordingPlatform{
				events: &events, fail: test.fail, failure: errors.New("failure"),
			}

			if err := Install(context.Background(), request); err == nil {
				t.Fatal("Install succeeded")
			}
			if slicesContain(events, "freeze") || slicesContain(events, "rollback") {
				t.Fatalf("existing runtime was touched before commit: %v", events)
			}
		})
	}
}

func TestInstallRollsBackEveryPlatformFailureAfterFreeze(t *testing.T) {
	for _, step := range []string{"freeze", "install", "service", "start", "verify", "delete-task"} {
		t.Run(step, func(t *testing.T) {
			request := successfulRequest(t)
			var events []string
			request.Platform = &recordingPlatform{
				events: &events, fail: step, failure: errors.New("failure"),
			}

			err := Install(context.Background(), request)
			if err == nil {
				t.Fatal("Install succeeded")
			}
			if got := events[len(events)-1]; got != "rollback" {
				t.Fatalf("last event=%q events=%v", got, events)
			}
		})
	}
}

func TestInstallRollsBackWhenQuiescedMigrationFails(t *testing.T) {
	request := successfulRequest(t)
	var events []string
	request.Platform = &recordingPlatform{events: &events}
	migrations := 0
	request.Migrate = func(paths Paths) error {
		migrations++
		if migrations == 2 {
			return errors.New("quiesced migration failed")
		}
		return writeStaged(paths, request.StateDir)
	}

	err := Install(context.Background(), request)
	if err == nil || err.Error() != "windows installation failed at migrate-commit" {
		t.Fatalf("error=%v", err)
	}
	if got := events[len(events)-1]; got != "rollback" {
		t.Fatalf("last event=%q events=%v", got, events)
	}
}

func TestInstallRejectsBuildIdentityBeforePlatformMutation(t *testing.T) {
	request := fixtureRequest(t, t.TempDir())
	request.CurrentArtifact = "windows-legacy-amd64"
	var events []string
	request.Platform = &recordingPlatform{events: &events}

	err := Install(context.Background(), request)
	if err == nil || err.Error() != "windows installation failed at arguments" {
		t.Fatalf("error=%v", err)
	}
	if len(events) != 0 {
		t.Fatalf("platform was called: %v", events)
	}
}

func TestSetSourcesPrefersNativeQueueAndFallsBackToLegacyQueue(t *testing.T) {
	directory := t.TempDir()
	request := fixtureRequest(t, directory)
	paths, err := request.makePaths()
	if err != nil {
		t.Fatal(err)
	}
	defer os.RemoveAll(paths.StageDir)
	if err := os.MkdirAll(request.StateDir, 0700); err != nil {
		t.Fatal(err)
	}
	if err := os.WriteFile(paths.LegacyQueue, []byte("legacy"), 0600); err != nil {
		t.Fatal(err)
	}

	request.setSources(&paths)
	if paths.SourceQueue != paths.LegacyQueue {
		t.Fatalf("source queue=%q want legacy=%q", paths.SourceQueue, paths.LegacyQueue)
	}
	if err := os.WriteFile(paths.InstalledQueue, []byte("native"), 0600); err != nil {
		t.Fatal(err)
	}
	request.setSources(&paths)
	if paths.SourceQueue != paths.InstalledQueue {
		t.Fatalf("source queue=%q want native=%q", paths.SourceQueue, paths.InstalledQueue)
	}
}

func successfulRequest(t *testing.T) Request {
	t.Helper()
	request := fixtureRequest(t, t.TempDir())
	request.Activate = func(_ context.Context, _, output string) error {
		return os.WriteFile(output, []byte(validConfig(filepath.Join(request.StateDir, "queue.json"))), 0600)
	}
	request.Migrate = func(paths Paths) error {
		return writeStaged(paths, request.StateDir)
	}
	return request
}

func writeStaged(paths Paths, stateDir string) error {
	if err := os.WriteFile(paths.StagedConfig, []byte(validConfig(filepath.Join(stateDir, "queue.json"))), 0600); err != nil {
		return err
	}
	return os.WriteFile(paths.StagedQueue, []byte("[]"), 0600)
}

func slicesContain(values []string, target string) bool {
	for _, value := range values {
		if value == target {
			return true
		}
	}
	return false
}

func fixtureRequest(t *testing.T, directory string) Request {
	t.Helper()
	self := filepath.Join(directory, "selected-agent.exe")
	bootstrap := filepath.Join(directory, "bootstrap.json")
	if err := os.WriteFile(self, []byte("agent fixture"), 0700); err != nil {
		t.Fatal(err)
	}
	if err := os.WriteFile(bootstrap, []byte("bootstrap fixture"), 0600); err != nil {
		t.Fatal(err)
	}
	return Request{
		BootstrapPath:    bootstrap,
		ExecutablePath:   self,
		ExpectedVersion:  "v0.4.6",
		ExpectedArtifact: "windows-amd64",
		ExpectedSHA256:   fileSHA256(t, self),
		ExpectedSize:     int64(len("agent fixture")),
		CurrentVersion:   "v0.4.6",
		CurrentArtifact:  "windows-amd64",
		InstallDir:       filepath.Join(directory, "install"),
		StateDir:         filepath.Join(directory, "state"),
	}
}

func validConfig(queuePath string) string {
	return `{"api_url":"https://monitor.example/api/v1/metrics","config_url":"https://monitor.example/api/v1/agent/config","token":"bbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbb","queue_path":"` + strings.ReplaceAll(queuePath, `\`, `\\`) + `","interval_seconds":60,"verify_tls":true,"enabled":true,"monitor_services":[],"queue_limit":1000}`
}

func fileSHA256(t *testing.T, path string) string {
	t.Helper()
	value, err := SHA256(path)
	if err != nil {
		t.Fatal(err)
	}
	return value
}

type recordingPlatform struct {
	events  *[]string
	fail    string
	failure error
}

func (platform *recordingPlatform) event(name string) error {
	*platform.events = append(*platform.events, name)
	if platform.fail == name {
		return platform.failure
	}
	return nil
}

func (platform *recordingPlatform) Validate(Request) error { return platform.event("validate") }
func (platform *recordingPlatform) ProtectStage(Paths) error {
	return platform.event("protect-stage")
}
func (platform *recordingPlatform) Snapshot(Paths) (Snapshot, error) {
	return Snapshot{}, platform.event("snapshot")
}
func (platform *recordingPlatform) Protect(Paths) error   { return platform.event("protect") }
func (platform *recordingPlatform) Freeze(Snapshot) error { return platform.event("freeze") }
func (platform *recordingPlatform) InstallFiles(Paths, Snapshot) error {
	return platform.event("install")
}
func (platform *recordingPlatform) ConfigureService(Paths, Snapshot) error {
	return platform.event("service")
}
func (platform *recordingPlatform) StartService() error  { return platform.event("start") }
func (platform *recordingPlatform) VerifyService() error { return platform.event("verify") }
func (platform *recordingPlatform) DeleteLegacyTask(Snapshot) error {
	return platform.event("delete-task")
}
func (platform *recordingPlatform) Rollback(Paths, Snapshot) error { return platform.event("rollback") }
