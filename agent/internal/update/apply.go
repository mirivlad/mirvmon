package update

import (
	"crypto/sha256"
	"encoding/hex"
	"encoding/json"
	"errors"
	"fmt"
	"io"
	"os"
	"os/exec"
	"path/filepath"
	"runtime"
	"strings"
	"time"

	"github.com/mirivlad/mirvmon/agent/internal/health"
)

var ErrIdentityMismatch = errors.New("update identity mismatch")

// Apply revalidates and applies the fixed staged artifact. Platform code owns
// process/service coordination, while replacement and rollback remain shared.
func Apply(requestPath, installedPath string, parentPID int) (Command, error) {
	contents, err := os.ReadFile(requestPath)
	if err != nil {
		return Command{}, fmt.Errorf("read update request: %w", err)
	}
	var command Command
	if err := json.Unmarshal(contents, &command); err != nil || command.Validate() != nil {
		return Command{}, ErrInvalidCommand
	}
	suffix := ""
	if runtime.GOOS == "windows" {
		suffix = ".exe"
	}
	stagedPath := filepath.Join(filepath.Dir(requestPath), "update-staged"+suffix)
	if err := verifyFile(stagedPath, command); err != nil {
		return command, failApply(storeForRequest(requestPath), command, applyErrorCode(err), err)
	}
	output, err := exec.Command(stagedPath, "version").Output()
	if err != nil || validateIdentity(output, command.TargetVersion, command.Artifact) != nil {
		if err == nil {
			err = ErrIdentityMismatch
		}
		return command, failApply(storeForRequest(requestPath), command, "identity_mismatch", err)
	}
	store := storeForRequest(requestPath)
	healthPath := health.New(filepath.Join(filepath.Dir(requestPath), "queue.json")).Path()
	if err := platformApply(stagedPath, installedPath, parentPID, command.TargetVersion, healthPath); err != nil {
		return command, failApply(store, command, "apply_failed", err)
	}
	if err := store.Advance(command.ID, StateAwaitingRestart, ""); err != nil {
		return command, err
	}
	return command, nil
}

func storeForRequest(requestPath string) Store {
	return NewStore(filepath.Join(filepath.Dir(requestPath), "queue.json"))
}

func failApply(store Store, command Command, code string, cause error) error {
	if err := store.Advance(command.ID, StateFailed, code); err != nil {
		return errors.Join(cause, err)
	}
	return cause
}

func applyErrorCode(err error) string {
	if errors.Is(err, ErrChecksumMismatch) {
		return "checksum_mismatch"
	}
	return "staged_file_invalid"
}

func verifyFile(path string, command Command) error {
	file, err := os.Open(path)
	if err != nil {
		return err
	}
	defer file.Close()
	hash := sha256.New()
	written, err := io.Copy(hash, io.LimitReader(file, command.Size+1))
	if err != nil {
		return err
	}
	if written != command.Size || hex.EncodeToString(hash.Sum(nil)) != command.SHA256 {
		return ErrChecksumMismatch
	}
	return nil
}

func validateIdentity(output []byte, version, artifact string) error {
	fields := strings.Fields(string(output))
	platform := runtime.GOOS + "/" + runtime.GOARCH
	if len(fields) != 4 || fields[0] != version || fields[2] != platform || fields[3] != artifact {
		return ErrIdentityMismatch
	}
	return nil
}

func waitForTargetHealth(path, targetVersion string, timeout time.Duration) error {
	deadline := time.NewTimer(timeout)
	defer deadline.Stop()
	ticker := time.NewTicker(10 * time.Millisecond)
	defer ticker.Stop()
	for {
		status, err := (health.StoreForPath(path)).Read()
		if err == nil && status.AgentVersion == targetVersion && status.State != "collection_error" && status.State != "authentication_error" {
			return nil
		}
		select {
		case <-deadline.C:
			return errors.New("target agent health timeout")
		case <-ticker.C:
		}
	}
}

func replaceExecutable(staged, installed string, restart, confirm, beforeRestore func() error) error {
	backup := installed + ".previous"
	if err := os.Remove(backup); err != nil && !os.IsNotExist(err) {
		return err
	}
	if err := os.Rename(installed, backup); err != nil {
		return err
	}
	restore := func(cause error) error {
		if beforeRestore != nil {
			if err := beforeRestore(); err != nil {
				cause = errors.Join(cause, fmt.Errorf("stop target agent: %w", err))
			}
		}
		_ = os.Remove(installed)
		if err := os.Rename(backup, installed); err != nil {
			return errors.Join(cause, fmt.Errorf("restore previous agent: %w", err))
		}
		_ = restart()
		return cause
	}
	if err := os.Rename(staged, installed); err != nil {
		return restore(err)
	}
	if err := os.Chmod(installed, 0755); err != nil {
		return restore(err)
	}
	if err := restart(); err != nil {
		return restore(err)
	}
	if confirm != nil {
		if err := confirm(); err != nil {
			return restore(err)
		}
	}
	return nil
}
