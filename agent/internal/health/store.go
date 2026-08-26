// Package health persists the agent's installer-visible, secret-safe status.
package health

import (
	"encoding/json"
	"fmt"
	"os"
	"path/filepath"
	"regexp"
	"time"

	"github.com/mirivlad/mirvmon/agent/internal/atomicfile"
)

var sensitiveErrorValue = regexp.MustCompile(`(?i)(token|authorization|password|secret)(?:\s*(?:=|:)\s*|\s+)[^\s]+`)

// Status is the durable status contract consumed by transactional installers.
type Status struct {
	AgentVersion     string    `json:"agent_version"`
	Commit           string    `json:"commit"`
	StartedAt        time.Time `json:"started_at"`
	LastCollectionAt time.Time `json:"last_collection_at,omitempty"`
	LastDeliveryAt   time.Time `json:"last_delivery_at,omitempty"`
	State            string    `json:"state"`
	LastError        string    `json:"last_error,omitempty"`
}

// Store writes health.json beside the durable queue.
type Store struct {
	path string
}

// New creates a health store next to queuePath.
func New(queuePath string) Store {
	return Store{path: filepath.Join(filepath.Dir(queuePath), "health.json")}
}

// StoreForPath opens the fixed status path for the privileged updater. The
// path is always derived locally from the queue/request directory.
func StoreForPath(path string) Store {
	return Store{path: path}
}

// Path returns the fixed health file path.
func (store Store) Path() string {
	return store.path
}

// Write atomically persists a sanitized status with owner-only permissions.
func (store Store) Write(status Status) error {
	status.LastError = sanitizeError(status.LastError)
	contents, err := json.Marshal(status)
	if err != nil {
		return fmt.Errorf("encode health status: %w", err)
	}
	if err := atomicfile.Write(store.path, contents, 0600); err != nil {
		return fmt.Errorf("write health status: %w", err)
	}
	return nil
}

// Read decodes the current health status.
func (store Store) Read() (Status, error) {
	contents, err := os.ReadFile(store.path)
	if err != nil {
		return Status{}, err
	}
	var status Status
	if err := json.Unmarshal(contents, &status); err != nil {
		return Status{}, fmt.Errorf("decode health status: %w", err)
	}
	return status, nil
}

// Clear removes stale pre-start health state. A missing file is already clear.
func (store Store) Clear() error {
	err := os.Remove(store.path)
	if err == nil || os.IsNotExist(err) {
		return nil
	}
	return fmt.Errorf("clear health status: %w", err)
}

func sanitizeError(value string) string {
	return sensitiveErrorValue.ReplaceAllString(value, "$1=[redacted]")
}
