package update

import (
	"errors"
	"path/filepath"
	"testing"
)

func TestStoreAcceptsCommandOnceAndRejectsConcurrentCommand(t *testing.T) {
	store := NewStore(filepath.Join(t.TempDir(), "queue.json"))
	command := Command{
		ID:            "20000000-0000-4000-8000-000000000001",
		TargetVersion: "v0.4.3",
		Artifact:      "linux-amd64",
		SHA256:        "aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa",
		Size:          100,
	}
	state, accepted, err := store.Accept(command)
	if err != nil || !accepted || state.State != StateAccepted {
		t.Fatalf("first accept: state=%#v accepted=%v err=%v", state, accepted, err)
	}
	state, accepted, err = store.Accept(command)
	if err != nil || accepted || state.Command.ID != command.ID {
		t.Fatalf("replay: state=%#v accepted=%v err=%v", state, accepted, err)
	}
	other := command
	other.ID = "20000000-0000-4000-8000-000000000002"
	if _, _, err := store.Accept(other); !errors.Is(err, ErrUpdateInProgress) {
		t.Fatalf("concurrent command: %v", err)
	}
}

func TestCommandAcceptsFourPartHotfixVersion(t *testing.T) {
	command := testCommand()
	command.TargetVersion = "v0.4.15.2"

	if err := command.Validate(); err != nil {
		t.Fatalf("hotfix version rejected: %v", err)
	}
}

func TestStoreAllowsNewCommandAfterTerminalFailure(t *testing.T) {
	store := NewStore(filepath.Join(t.TempDir(), "queue.json"))
	command := testCommand()
	if _, _, err := store.Accept(command); err != nil {
		t.Fatal(err)
	}
	if err := store.Advance(command.ID, StateFailed, "checksum_mismatch"); err != nil {
		t.Fatal(err)
	}
	command.ID = "20000000-0000-4000-8000-000000000003"
	if _, accepted, err := store.Accept(command); err != nil || !accepted {
		t.Fatalf("new command: accepted=%v err=%v", accepted, err)
	}
}

func testCommand() Command {
	return Command{
		ID:            "20000000-0000-4000-8000-000000000001",
		TargetVersion: "v0.4.3",
		Artifact:      "linux-amd64",
		SHA256:        "aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa",
		Size:          100,
	}
}
