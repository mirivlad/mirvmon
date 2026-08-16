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

var (
	ErrIdentityMismatch    = errors.New("update identity mismatch")
	ErrUnauthorizedCommand = errors.New("update command was not authorized by server")
)

// Apply revalidates and applies the fixed staged artifact. Platform code owns
// process/service coordination, while replacement and rollback remain shared.
func Apply(
	requestPath string,
	installedPath string,
	parentPID int,
	authorized *Command,
) (Command, error) {
	request, err := os.Open(requestPath)
	if err != nil {
		return Command{}, fmt.Errorf("read update request: %w", err)
	}
	defer request.Close()
	contents, err := io.ReadAll(io.LimitReader(request, 16*1024+1))
	if err != nil || len(contents) > 16*1024 {
		return Command{}, ErrInvalidCommand
	}
	var command Command
	if err := json.Unmarshal(contents, &command); err != nil || command.Validate() != nil {
		return Command{}, ErrInvalidCommand
	}
	if err := authorize(command, authorized); err != nil {
		return command, err
	}
	suffix := ""
	if runtime.GOOS == "windows" {
		suffix = ".exe"
	}
	stagedPath := filepath.Join(filepath.Dir(requestPath), "update-staged"+suffix)
	candidatePath, err := protectCandidate(stagedPath, installedPath)
	if err != nil {
		return command, failApply(storeForRequest(requestPath), queueForRequest(requestPath), command, "staged_file_invalid", err)
	}
	defer os.Remove(candidatePath)
	if err := verifyFile(candidatePath, command); err != nil {
		return command, failApply(storeForRequest(requestPath), queueForRequest(requestPath), command, applyErrorCode(err), err)
	}
	output, err := exec.Command(candidatePath, "version").Output()
	if err != nil || validateIdentity(output, command.TargetVersion, command.Artifact) != nil {
		if err == nil {
			err = ErrIdentityMismatch
		}
		return command, failApply(storeForRequest(requestPath), queueForRequest(requestPath), command, "identity_mismatch", err)
	}
	store := storeForRequest(requestPath)
	queuePath := queueForRequest(requestPath)
	healthPath := health.New(queuePath).Path()
	if err := platformApply(candidatePath, installedPath, parentPID, command.TargetVersion, healthPath); err != nil {
		return command, failApply(store, queuePath, command, "apply_failed", err)
	}
	if err := advanceOwned(store, queuePath, command.ID, StateAwaitingRestart, ""); err != nil {
		return command, err
	}
	return command, nil
}

func authorize(requested Command, authorized *Command) error {
	if authorized == nil || *authorized != requested {
		return ErrUnauthorizedCommand
	}
	return nil
}

func protectCandidate(stagedPath, installedPath string) (string, error) {
	input, err := os.Open(stagedPath)
	if err != nil {
		return "", err
	}
	defer input.Close()
	info, err := input.Stat()
	if err != nil {
		return "", err
	}
	if err := platformValidateStaged(info); err != nil {
		return "", errors.New("staged update is not a private regular file")
	}
	candidatePath := installedPath + ".candidate"
	if err := os.Remove(candidatePath); err != nil && !os.IsNotExist(err) {
		return "", err
	}
	output, err := os.OpenFile(candidatePath, os.O_CREATE|os.O_EXCL|os.O_WRONLY, 0700)
	if err != nil {
		return "", err
	}
	failed := true
	defer func() {
		_ = output.Close()
		if failed {
			_ = os.Remove(candidatePath)
		}
	}()
	if _, err := io.Copy(output, io.LimitReader(input, 128*1024*1024+1)); err != nil {
		return "", err
	}
	if err := output.Sync(); err != nil {
		return "", err
	}
	if err := output.Close(); err != nil {
		return "", err
	}
	failed = false
	return candidatePath, nil
}

func storeForRequest(requestPath string) Store {
	return NewStore(queueForRequest(requestPath))
}

func queueForRequest(requestPath string) string {
	return filepath.Join(filepath.Dir(requestPath), "queue.json")
}

func advanceOwned(store Store, queuePath, id, state, code string) error {
	if err := store.Advance(id, state, code); err != nil {
		return err
	}
	return platformPreserveStateOwner(queuePath, store.Path())
}

func failApply(store Store, queuePath string, command Command, code string, cause error) error {
	if err := advanceOwned(store, queuePath, command.ID, StateFailed, code); err != nil {
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

func replaceExecutable(staged, installed string, beforeReplace, restart, confirm, beforeRestore func() error) error {
	backup := installed + ".previous"
	if err := os.Remove(backup); err != nil && !os.IsNotExist(err) {
		return err
	}
	if beforeReplace != nil {
		if err := beforeReplace(); err != nil {
			return fmt.Errorf("stop installed agent: %w", err)
		}
	}
	if err := os.Rename(installed, backup); err != nil {
		if restartErr := restart(); restartErr != nil {
			return errors.Join(err, fmt.Errorf("restart installed agent: %w", restartErr))
		}
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
