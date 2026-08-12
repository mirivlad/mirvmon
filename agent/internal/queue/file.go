// Package queue provides bounded durable FIFO storage for envelopes that could
// not be delivered yet.
package queue

import (
	"bytes"
	"encoding/json"
	"errors"
	"fmt"
	"os"
	"path/filepath"
	"sync"

	"github.com/mirivlad/mirvmon/agent/internal/atomicfile"
	"github.com/mirivlad/mirvmon/agent/internal/protocol"
)

const maxQuarantineEntries = 100

// FileQueue is a process-safe, bounded oldest-first queue persisted as a JSON
// array of raw v2 envelopes.
type FileQueue struct {
	mu    sync.Mutex
	path  string
	limit int
	items []json.RawMessage
}

type quarantineEntry struct {
	Reason   string          `json:"reason"`
	Envelope json.RawMessage `json:"envelope"`
}

// Open restores a queue. Invalid persisted content is kept as .corrupt and a
// fresh empty queue is returned so delivery can resume safely.
func Open(path string, limit int) (*FileQueue, error) {
	if limit < 1 || limit > 10000 {
		return nil, errors.New("queue limit must be between 1 and 10000")
	}
	queue := &FileQueue{path: path, limit: limit}
	contents, err := os.ReadFile(path)
	if errors.Is(err, os.ErrNotExist) {
		return queue, nil
	}
	if err != nil {
		return nil, fmt.Errorf("read queue: %w", err)
	}
	if bytes.Equal(bytes.TrimSpace(contents), []byte("null")) ||
		json.Unmarshal(contents, &queue.items) != nil || !validItems(queue.items) {
		if err := renameCorrupt(path); err != nil {
			return nil, err
		}
		queue.items = nil
		return queue, nil
	}
	if err := os.Chmod(path, 0600); err != nil {
		return nil, fmt.Errorf("set queue mode: %w", err)
	}
	if len(queue.items) > queue.limit {
		queue.items = append([]json.RawMessage(nil), queue.items[len(queue.items)-queue.limit:]...)
		if err := queue.persistLocked(); err != nil {
			return nil, err
		}
	}
	return queue, nil
}

// Enqueue validates metadata before durably adding an envelope. The newest
// items are retained when the configured bound is exceeded.
func (queue *FileQueue) Enqueue(envelope []byte) error {
	if _, err := protocol.ParseMetadata(envelope); err != nil {
		return fmt.Errorf("invalid queue envelope: %w", err)
	}
	queue.mu.Lock()
	defer queue.mu.Unlock()
	queue.items = append(queue.items, append(json.RawMessage(nil), envelope...))
	if len(queue.items) > queue.limit {
		queue.items = append([]json.RawMessage(nil), queue.items[len(queue.items)-queue.limit:]...)
	}
	return queue.persistLocked()
}

// Peek returns a copy of the oldest envelope, or nil when the queue is empty.
func (queue *FileQueue) Peek() []byte {
	queue.mu.Lock()
	defer queue.mu.Unlock()
	if len(queue.items) == 0 {
		return nil
	}
	return append([]byte(nil), queue.items[0]...)
}

// Accept durably removes only the oldest envelope.
func (queue *FileQueue) Accept() error {
	queue.mu.Lock()
	defer queue.mu.Unlock()
	if len(queue.items) == 0 {
		return nil
	}
	queue.items = queue.items[1:]
	return queue.persistLocked()
}

// Reject redacts and records the oldest permanent failure before removing it
// from the delivery queue.
func (queue *FileQueue) Reject(reason string) error {
	if reason == "" {
		return errors.New("rejection reason is required")
	}
	queue.mu.Lock()
	defer queue.mu.Unlock()
	if len(queue.items) == 0 {
		return nil
	}
	redacted, err := protocol.RedactToken(queue.items[0])
	if err != nil {
		return fmt.Errorf("redact rejected envelope: %w", err)
	}
	if err := appendQuarantine(filepath.Join(filepath.Dir(queue.path), "quarantine.json"), quarantineEntry{
		Reason:   reason,
		Envelope: redacted,
	}); err != nil {
		return err
	}
	queue.items = queue.items[1:]
	return queue.persistLocked()
}

// Len returns the in-memory item count.
func (queue *FileQueue) Len() int {
	queue.mu.Lock()
	defer queue.mu.Unlock()
	return len(queue.items)
}

func (queue *FileQueue) persistLocked() error {
	contents, err := json.Marshal(queue.items)
	if err != nil {
		return fmt.Errorf("encode queue: %w", err)
	}
	if err := atomicfile.Write(queue.path, contents, 0600); err != nil {
		return fmt.Errorf("persist queue: %w", err)
	}
	return nil
}

func validItems(items []json.RawMessage) bool {
	for _, item := range items {
		if _, err := protocol.ParseMetadata(item); err != nil {
			return false
		}
	}
	return true
}

func renameCorrupt(path string) error {
	corruptPath := path + ".corrupt"
	if err := os.Rename(path, corruptPath); err != nil {
		return fmt.Errorf("preserve corrupt queue: %w", err)
	}
	if err := os.Chmod(corruptPath, 0600); err != nil {
		return fmt.Errorf("set corrupt queue mode: %w", err)
	}
	return nil
}

func appendQuarantine(path string, entry quarantineEntry) error {
	entries, err := loadQuarantine(path)
	if err != nil {
		return err
	}
	entries = append(entries, entry)
	if len(entries) > maxQuarantineEntries {
		entries = entries[len(entries)-maxQuarantineEntries:]
	}
	contents, err := json.Marshal(entries)
	if err != nil {
		return fmt.Errorf("encode quarantine: %w", err)
	}
	if err := atomicfile.Write(path, contents, 0600); err != nil {
		return fmt.Errorf("persist quarantine: %w", err)
	}
	return nil
}

func loadQuarantine(path string) ([]quarantineEntry, error) {
	contents, err := os.ReadFile(path)
	if errors.Is(err, os.ErrNotExist) {
		return nil, nil
	}
	if err != nil {
		return nil, fmt.Errorf("read quarantine: %w", err)
	}
	var entries []quarantineEntry
	if err := json.Unmarshal(contents, &entries); err != nil {
		return nil, fmt.Errorf("decode quarantine: %w", err)
	}
	return entries, nil
}
