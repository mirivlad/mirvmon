package queue

import (
	"bytes"
	"fmt"
	"os"
	"path/filepath"
	"strings"
	"testing"

	"github.com/mirivlad/mirvmon/agent/internal/protocol"
)

func TestQueueSurvivesRestartAndKeepsOldestFirst(t *testing.T) {
	path := filepath.Join(t.TempDir(), "queue.json")
	queue, err := Open(path, 2)
	if err != nil {
		t.Fatal(err)
	}
	for _, id := range []string{"one", "two", "three"} {
		if err := queue.Enqueue(envelopeBytes(id)); err != nil {
			t.Fatal(err)
		}
	}

	reloaded, err := Open(path, 2)
	if err != nil {
		t.Fatal(err)
	}
	if got := metadataID(t, reloaded.Peek()); got != "two" {
		t.Fatalf("got %s, want two", got)
	}
	if mode := fileMode(t, path); mode != 0600 {
		t.Fatalf("mode %o, want 600", mode)
	}
	if err := reloaded.Accept(); err != nil {
		t.Fatal(err)
	}
	if got := metadataID(t, reloaded.Peek()); got != "three" {
		t.Fatalf("got %s, want three", got)
	}
}

func TestCorruptQueueIsRenamedAndPermanentFailureIsRedacted(t *testing.T) {
	directory := t.TempDir()
	path := filepath.Join(directory, "queue.json")
	if err := os.WriteFile(path, []byte("{"), 0600); err != nil {
		t.Fatal(err)
	}
	queue, err := Open(path, 1000)
	if err != nil {
		t.Fatal(err)
	}
	if _, err := os.Stat(path + ".corrupt"); err != nil {
		t.Fatal(err)
	}
	if err := queue.Enqueue(envelopeBytes("bad")); err != nil {
		t.Fatal(err)
	}
	if err := queue.Reject("http_422"); err != nil {
		t.Fatal(err)
	}
	quarantine, err := os.ReadFile(filepath.Join(directory, "quarantine.json"))
	if err != nil {
		t.Fatal(err)
	}
	if bytes.Contains(quarantine, []byte(strings.Repeat("a", 64))) {
		t.Fatalf("token leaked to quarantine: %s", quarantine)
	}
	if !bytes.Contains(quarantine, []byte(`"reason":"http_422"`)) ||
		!bytes.Contains(quarantine, []byte(`"token":"[redacted]"`)) {
		t.Fatalf("unexpected quarantine: %s", quarantine)
	}
	if queue.Peek() != nil || queue.Len() != 0 {
		t.Fatal("rejected item remained queued")
	}
}

func TestOpenTrimsOversizedQueueAndRejectsInvalidLimits(t *testing.T) {
	path := filepath.Join(t.TempDir(), "queue.json")
	contents := fmt.Sprintf("[%s,%s,%s]", envelopeBytes("one"), envelopeBytes("two"), envelopeBytes("three"))
	if err := os.WriteFile(path, []byte(contents), 0600); err != nil {
		t.Fatal(err)
	}
	queue, err := Open(path, 2)
	if err != nil {
		t.Fatal(err)
	}
	if queue.Len() != 2 || metadataID(t, queue.Peek()) != "two" {
		t.Fatalf("queue was not trimmed to newest items: len=%d peek=%s", queue.Len(), metadataID(t, queue.Peek()))
	}
	if _, err := Open(path, 0); err == nil {
		t.Fatal("Open accepted zero queue limit")
	}
	if err := queue.Enqueue([]byte(`{"not":"an envelope"}`)); err == nil {
		t.Fatal("Enqueue accepted an envelope without metadata")
	}

	nullPath := filepath.Join(t.TempDir(), "null-queue.json")
	if err := os.WriteFile(nullPath, []byte("null"), 0600); err != nil {
		t.Fatal(err)
	}
	nullQueue, err := Open(nullPath, 2)
	if err != nil {
		t.Fatal(err)
	}
	if nullQueue.Len() != 0 {
		t.Fatal("null queue did not reset")
	}
	if _, err := os.Stat(nullPath + ".corrupt"); err != nil {
		t.Fatal("null queue was not preserved as corrupt")
	}
}

func envelopeBytes(id string) []byte {
	return []byte(fmt.Sprintf(`{"version":2,"sample_id":%q,"sample_time":"2026-08-12T12:00:00Z","token":%q,"metrics":{"cpu_load":1}}`, id, strings.Repeat("a", 64)))
}

func metadataID(t *testing.T, raw []byte) string {
	t.Helper()
	metadata, err := protocol.ParseMetadata(raw)
	if err != nil {
		t.Fatal(err)
	}
	return metadata.SampleID
}

func fileMode(t *testing.T, path string) os.FileMode {
	t.Helper()
	info, err := os.Stat(path)
	if err != nil {
		t.Fatal(err)
	}
	return info.Mode().Perm()
}
