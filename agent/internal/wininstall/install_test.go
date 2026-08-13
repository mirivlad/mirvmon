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
