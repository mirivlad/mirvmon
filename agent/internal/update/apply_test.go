package update

import (
	"errors"
	"os"
	"path/filepath"
	"runtime"
	"testing"
	"time"

	"github.com/mirivlad/mirvmon/agent/internal/health"
)

func TestReplaceExecutableKeepsBackupOnSuccess(t *testing.T) {
	directory := t.TempDir()
	installed := filepath.Join(directory, "agent")
	staged := filepath.Join(directory, "staged")
	os.WriteFile(installed, []byte("old"), 0755)
	os.WriteFile(staged, []byte("new"), 0700)
	if err := replaceExecutable(staged, installed, nil, func() error { return nil }, nil, nil); err != nil {
		t.Fatal(err)
	}
	assertFileContents(t, installed, "new")
	assertFileContents(t, installed+".previous", "old")
}

func TestReplaceExecutableStopsBeforeRenamingInstalledBinary(t *testing.T) {
	directory := t.TempDir()
	installed := filepath.Join(directory, "agent")
	staged := filepath.Join(directory, "staged")
	if err := os.WriteFile(installed, []byte("old"), 0755); err != nil {
		t.Fatal(err)
	}
	if err := os.WriteFile(staged, []byte("new"), 0700); err != nil {
		t.Fatal(err)
	}
	stopped := false
	if err := replaceExecutable(staged, installed, func() error {
		assertFileContents(t, installed, "old")
		if _, err := os.Stat(installed + ".previous"); !os.IsNotExist(err) {
			t.Fatalf("backup exists before service stop: %v", err)
		}
		stopped = true
		return nil
	}, func() error {
		if !stopped {
			t.Fatal("restart ran before service stop")
		}
		return nil
	}, nil, nil); err != nil {
		t.Fatal(err)
	}
	assertFileContents(t, installed, "new")
	assertFileContents(t, installed+".previous", "old")
}

func TestReplaceExecutableLeavesFilesUntouchedWhenStopFails(t *testing.T) {
	directory := t.TempDir()
	installed := filepath.Join(directory, "agent")
	staged := filepath.Join(directory, "staged")
	if err := os.WriteFile(installed, []byte("old"), 0755); err != nil {
		t.Fatal(err)
	}
	if err := os.WriteFile(staged, []byte("new"), 0700); err != nil {
		t.Fatal(err)
	}
	want := errors.New("stop failed")
	if err := replaceExecutable(staged, installed, func() error { return want }, func() error {
		t.Fatal("restart must not run when the old service cannot be stopped")
		return nil
	}, nil, nil); !errors.Is(err, want) {
		t.Fatalf("got %v", err)
	}
	assertFileContents(t, installed, "old")
	assertFileContents(t, staged, "new")
	if _, err := os.Stat(installed + ".previous"); !os.IsNotExist(err) {
		t.Fatalf("backup created after failed stop: %v", err)
	}
}

func TestReplaceExecutableRestartsInstalledAgentWhenRenameCannotBegin(t *testing.T) {
	directory := t.TempDir()
	installed := filepath.Join(directory, "missing-agent")
	staged := filepath.Join(directory, "staged")
	if err := os.WriteFile(staged, []byte("new"), 0700); err != nil {
		t.Fatal(err)
	}
	stops := 0
	restarts := 0
	err := replaceExecutable(staged, installed, func() error {
		stops++
		return nil
	}, func() error {
		restarts++
		return nil
	}, nil, nil)
	if err == nil {
		t.Fatal("missing installed executable was accepted")
	}
	if stops != 1 || restarts != 1 {
		t.Fatalf("stops=%d restarts=%d, want 1/1", stops, restarts)
	}
	assertFileContents(t, staged, "new")
}

func TestReplaceExecutableRollsBackWhenRestartFails(t *testing.T) {
	directory := t.TempDir()
	installed := filepath.Join(directory, "agent")
	staged := filepath.Join(directory, "staged")
	os.WriteFile(installed, []byte("old"), 0755)
	os.WriteFile(staged, []byte("new"), 0700)
	want := errors.New("restart failed")
	if err := replaceExecutable(staged, installed, nil, func() error { return want }, nil, nil); !errors.Is(err, want) {
		t.Fatalf("got %v", err)
	}
	assertFileContents(t, installed, "old")
}

func TestReplaceExecutableRollsBackWhenTargetHealthFails(t *testing.T) {
	directory := t.TempDir()
	installed := filepath.Join(directory, "agent")
	staged := filepath.Join(directory, "staged")
	os.WriteFile(installed, []byte("old"), 0755)
	os.WriteFile(staged, []byte("new"), 0700)
	restarts := 0
	want := errors.New("target unhealthy")
	err := replaceExecutable(staged, installed, nil, func() error {
		restarts++
		return nil
	}, func() error { return want }, nil)
	if !errors.Is(err, want) {
		t.Fatalf("got %v", err)
	}
	assertFileContents(t, installed, "old")
	if restarts != 2 {
		t.Fatalf("restarts=%d, want new attempt and rollback", restarts)
	}
}

func TestParseIdentityRequiresExactVersionArtifactAndPlatform(t *testing.T) {
	output := []byte("v0.4.3 commit " + runtime.GOOS + "/amd64 linux-amd64\n")
	if err := validateIdentity(output, "v0.4.3", "linux-amd64"); err != nil {
		t.Fatal(err)
	}
	for _, output := range [][]byte{
		[]byte("v0.4.4 commit " + runtime.GOOS + "/amd64 linux-amd64\n"),
		[]byte("v0.4.3 commit " + runtime.GOOS + "/amd64 windows-amd64\n"),
		[]byte("v0.4.3 commit linux/arm64 linux-amd64\n"),
		[]byte("not an identity\n"),
	} {
		if err := validateIdentity(output, "v0.4.3", "linux-amd64"); !errors.Is(err, ErrIdentityMismatch) {
			t.Fatalf("output %q: %v", output, err)
		}
	}
}

func TestAuthorizeRequiresExactServerCommand(t *testing.T) {
	command := testCommand()
	if err := authorize(command, &command); err != nil {
		t.Fatal(err)
	}
	if err := authorize(command, nil); !errors.Is(err, ErrUnauthorizedCommand) {
		t.Fatalf("nil command: %v", err)
	}
	changed := command
	changed.SHA256 = "bbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbb"
	if err := authorize(command, &changed); !errors.Is(err, ErrUnauthorizedCommand) {
		t.Fatalf("changed command: %v", err)
	}
}

func TestProtectCandidateCopiesIntoInstalledDirectory(t *testing.T) {
	directory := t.TempDir()
	stagedDirectory := filepath.Join(directory, "state")
	installedDirectory := filepath.Join(directory, "root-owned")
	if err := os.MkdirAll(stagedDirectory, 0700); err != nil {
		t.Fatal(err)
	}
	if err := os.MkdirAll(installedDirectory, 0700); err != nil {
		t.Fatal(err)
	}
	staged := filepath.Join(stagedDirectory, "update-staged")
	installed := filepath.Join(installedDirectory, "agent")
	if err := os.WriteFile(staged, []byte("trusted"), 0700); err != nil {
		t.Fatal(err)
	}
	candidate, err := protectCandidate(staged, installed)
	if err != nil {
		t.Fatal(err)
	}
	if filepath.Dir(candidate) != installedDirectory {
		t.Fatalf("candidate path=%s", candidate)
	}
	if err := os.WriteFile(staged, []byte("changed"), 0700); err != nil {
		t.Fatal(err)
	}
	assertFileContents(t, candidate, "trusted")
}

func TestWaitForTargetHealthRejectsOldVersionAndAcceptsTarget(t *testing.T) {
	store := health.New(filepath.Join(t.TempDir(), "queue.json"))
	if err := store.Write(health.Status{AgentVersion: "v0.4.2", State: "accepted"}); err != nil {
		t.Fatal(err)
	}
	if waitForTargetHealth(store.Path(), "v0.4.3", 20*time.Millisecond) == nil {
		t.Fatal("old version was accepted")
	}
	if err := store.Write(health.Status{AgentVersion: "v0.4.3", State: "accepted"}); err != nil {
		t.Fatal(err)
	}
	if err := waitForTargetHealth(store.Path(), "v0.4.3", time.Second); err != nil {
		t.Fatal(err)
	}
}

func assertFileContents(t *testing.T, path, want string) {
	t.Helper()
	contents, err := os.ReadFile(path)
	if err != nil || string(contents) != want {
		t.Fatalf("%s=%q err=%v", path, contents, err)
	}
}
