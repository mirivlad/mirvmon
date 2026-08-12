package health

import (
	"os"
	"path/filepath"
	"strings"
	"testing"
	"time"
)

func TestStoreWritesReadablePrivateStatusBesideQueue(t *testing.T) {
	queuePath := filepath.Join(t.TempDir(), "queue.json")
	store := New(queuePath)
	status := Status{
		AgentVersion:     "1.2.0",
		Commit:           "0123456789abcdef",
		StartedAt:        time.Date(2026, 8, 12, 12, 0, 0, 0, time.UTC),
		LastCollectionAt: time.Date(2026, 8, 12, 12, 1, 0, 0, time.UTC),
		LastDeliveryAt:   time.Date(2026, 8, 12, 12, 2, 0, 0, time.UTC),
		State:            "accepted",
		LastError:        "request failed: token=secret",
	}
	if err := store.Write(status); err != nil {
		t.Fatal(err)
	}
	loaded, err := store.Read()
	if err != nil {
		t.Fatal(err)
	}
	if loaded.State != "accepted" || loaded.AgentVersion != "1.2.0" {
		t.Fatalf("unexpected status: %#v", loaded)
	}
	if strings.Contains(loaded.LastError, "secret") {
		t.Fatalf("health error leaked secret: %q", loaded.LastError)
	}
	info, err := os.Stat(store.Path())
	if err != nil || info.Mode().Perm() != 0600 {
		t.Fatalf("health mode = %v, %v", info, err)
	}
}

func TestStoreClearRemovesStaleHealthFile(t *testing.T) {
	store := New(filepath.Join(t.TempDir(), "queue.json"))
	if err := store.Write(Status{State: "retrying"}); err != nil {
		t.Fatal(err)
	}
	if err := store.Clear(); err != nil {
		t.Fatal(err)
	}
	if _, err := store.Read(); !os.IsNotExist(err) {
		t.Fatalf("Read error = %v, want not exist", err)
	}
}
