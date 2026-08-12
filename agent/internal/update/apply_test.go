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
	if err := replaceExecutable(staged, installed, func() error { return nil }, nil, nil); err != nil {
		t.Fatal(err)
	}
	assertFileContents(t, installed, "new")
	assertFileContents(t, installed+".previous", "old")
}

func TestReplaceExecutableRollsBackWhenRestartFails(t *testing.T) {
	directory := t.TempDir()
	installed := filepath.Join(directory, "agent")
	staged := filepath.Join(directory, "staged")
	os.WriteFile(installed, []byte("old"), 0755)
	os.WriteFile(staged, []byte("new"), 0700)
	want := errors.New("restart failed")
	if err := replaceExecutable(staged, installed, func() error { return want }, nil, nil); !errors.Is(err, want) {
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
	err := replaceExecutable(staged, installed, func() error {
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
