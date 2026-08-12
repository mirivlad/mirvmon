//go:build !windows

package update

import (
	"os"
	"path/filepath"
	"syscall"
	"testing"
)

func TestAdvanceOwnedPreservesQueueOwnerAndPrivateMode(t *testing.T) {
	directory := t.TempDir()
	queuePath := filepath.Join(directory, "queue.json")
	if err := os.WriteFile(queuePath, []byte("[]"), 0600); err != nil {
		t.Fatal(err)
	}
	store := NewStore(queuePath)
	command := testCommand()
	if _, _, err := store.Accept(command); err != nil {
		t.Fatal(err)
	}
	if os.Geteuid() == 0 {
		if err := os.Chown(queuePath, 12345, 12345); err != nil {
			t.Fatal(err)
		}
	}
	if err := advanceOwned(store, queuePath, command.ID, StateDownloading, ""); err != nil {
		t.Fatal(err)
	}
	queueInfo, err := os.Stat(queuePath)
	if err != nil {
		t.Fatal(err)
	}
	stateInfo, err := os.Stat(store.Path())
	if err != nil {
		t.Fatal(err)
	}
	queueOwner := queueInfo.Sys().(*syscall.Stat_t)
	stateOwner := stateInfo.Sys().(*syscall.Stat_t)
	if queueOwner.Uid != stateOwner.Uid || queueOwner.Gid != stateOwner.Gid {
		t.Fatalf("queue owner=%d:%d state owner=%d:%d", queueOwner.Uid, queueOwner.Gid, stateOwner.Uid, stateOwner.Gid)
	}
	if stateInfo.Mode().Perm() != 0600 {
		t.Fatalf("state mode=%o", stateInfo.Mode().Perm())
	}
}
