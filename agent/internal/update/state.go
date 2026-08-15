// Package update implements the native agent's bounded, durable self-update
// state machine without accepting executable instructions from the server.
package update

import (
	"encoding/json"
	"errors"
	"fmt"
	"os"
	"path/filepath"
	"regexp"

	"github.com/mirivlad/mirvmon/agent/internal/atomicfile"
)

const (
	StateAccepted        = "accepted"
	StateDownloading     = "downloading"
	StateInstalling      = "installing"
	StateAwaitingRestart = "awaiting_restart"
	StateSucceeded       = "succeeded"
	StateFailed          = "failed"
)

var (
	ErrInvalidCommand   = errors.New("invalid update command")
	ErrUpdateInProgress = errors.New("another update is in progress")
	uuidPattern         = regexp.MustCompile(`^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$`)
	versionPattern      = regexp.MustCompile(`^v(?:0|[1-9][0-9]*)\.(?:0|[1-9][0-9]*)\.(?:0|[1-9][0-9]*)(?:\.(?:0|[1-9][0-9]*))?(?:-[0-9A-Za-z.-]+)?$`)
	artifactPattern     = regexp.MustCompile(`^[a-z0-9][a-z0-9-]{0,63}$`)
	checksumPattern     = regexp.MustCompile(`^[a-f0-9]{64}$`)
	errorCodePattern    = regexp.MustCompile(`^[a-z][a-z0-9_]{0,63}$`)
)

// Command is the only executable server instruction understood by the agent.
type Command struct {
	ID            string `json:"id"`
	TargetVersion string `json:"target_version"`
	Artifact      string `json:"artifact"`
	SHA256        string `json:"sha256"`
	Size          int64  `json:"size"`
}

// State is persisted without credentials beside the metrics queue.
type State struct {
	Command   Command `json:"command"`
	State     string `json:"state"`
	ErrorCode string `json:"error_code,omitempty"`
}

// Store owns update-state.json beside the queue.
type Store struct{ path string }

func NewStore(queuePath string) Store {
	return Store{path: filepath.Join(filepath.Dir(queuePath), "update-state.json")}
}

func (store Store) Path() string { return store.path }

func (store Store) Accept(command Command) (State, bool, error) {
	if err := command.Validate(); err != nil {
		return State{}, false, err
	}
	current, err := store.Read()
	if err == nil {
		if current.Command.ID == command.ID {
			return current, false, nil
		}
		if current.State != StateSucceeded && current.State != StateFailed {
			return State{}, false, ErrUpdateInProgress
		}
	} else if !os.IsNotExist(err) {
		return State{}, false, err
	}
	state := State{Command: command, State: StateAccepted}
	if err := store.write(state); err != nil {
		return State{}, false, err
	}
	return state, true, nil
}

func (store Store) Advance(id, next, errorCode string) error {
	state, err := store.Read()
	if err != nil {
		return err
	}
	if state.Command.ID != id {
		return ErrInvalidCommand
	}
	if next == StateFailed {
		if !errorCodePattern.MatchString(errorCode) {
			return ErrInvalidCommand
		}
	} else {
		expected := map[string]string{
			StateAccepted:        StateDownloading,
			StateDownloading:     StateInstalling,
			StateInstalling:      StateAwaitingRestart,
			StateAwaitingRestart: StateSucceeded,
		}[state.State]
		if expected != next {
			return ErrInvalidCommand
		}
		errorCode = ""
	}
	state.State = next
	state.ErrorCode = errorCode
	return store.write(state)
}

func (store Store) Read() (State, error) {
	contents, err := os.ReadFile(store.path)
	if err != nil {
		return State{}, err
	}
	var state State
	if err := json.Unmarshal(contents, &state); err != nil {
		return State{}, fmt.Errorf("decode update state: %w", err)
	}
	if err := state.Command.Validate(); err != nil {
		return State{}, err
	}
	return state, nil
}

// ReconcileInstalled terminalizes stale local progress once this host already
// runs the command target (or a newer build of the same artifact).
func (store Store) ReconcileInstalled(version, artifact string) error {
	state, err := store.Read()
	if os.IsNotExist(err) {
		return nil
	}
	if err != nil {
		return err
	}
	if state.State == StateSucceeded || state.State == StateFailed || state.Command.Artifact != artifact {
		return nil
	}
	if state.Command.TargetVersion != version && !isUpgrade(state.Command.TargetVersion, version) {
		return nil
	}
	state.State = StateSucceeded
	state.ErrorCode = ""
	return store.write(state)
}

func (store Store) write(state State) error {
	contents, err := json.Marshal(state)
	if err != nil {
		return fmt.Errorf("encode update state: %w", err)
	}
	return atomicfile.Write(store.path, contents, 0600)
}

func (command Command) Validate() error {
	if !uuidPattern.MatchString(command.ID) ||
		!versionPattern.MatchString(command.TargetVersion) ||
		!artifactPattern.MatchString(command.Artifact) ||
		!checksumPattern.MatchString(command.SHA256) ||
		command.Size < 1 || command.Size > 128*1024*1024 {
		return ErrInvalidCommand
	}
	return nil
}
