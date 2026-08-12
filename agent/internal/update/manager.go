package update

import (
	"context"
	"encoding/json"
	"errors"
	"path/filepath"
	"runtime"
	"strconv"
	"strings"

	"github.com/mirivlad/mirvmon/agent/internal/atomicfile"
)

var ErrRestartRequired = errors.New("agent restart required")

// Reporter sends monotonic progress to the command owner.
type Reporter func(context.Context, Command, string, string) error

// Manager coordinates unprivileged validation/download with a constrained
// platform handoff.
type Manager struct {
	Store            Store
	Downloader       Downloader
	InstalledVersion string
	Artifact         string
	Handoff          func(string) error
}

func (manager Manager) Process(
	context context.Context,
	command Command,
	report Reporter,
) error {
	if command.Artifact != manager.Artifact || !isUpgrade(manager.InstalledVersion, command.TargetVersion) {
		return ErrInvalidCommand
	}
	state, accepted, err := manager.Store.Accept(command)
	if err != nil {
		return err
	}
	if !accepted && state.State != StateAccepted && state.State != StateDownloading {
		return report(context, command, state.State, state.ErrorCode)
	}
	if accepted {
		if err := report(context, command, StateAccepted, ""); err != nil {
			return err
		}
	}
	if state.State == StateAccepted {
		if err := manager.Store.Advance(command.ID, StateDownloading, ""); err != nil {
			return err
		}
		if err := report(context, command, StateDownloading, ""); err != nil {
			return err
		}
	}
	directory := filepath.Dir(manager.Store.Path())
	suffix := ""
	if runtime.GOOS == "windows" {
		suffix = ".exe"
	}
	stagedPath := filepath.Join(directory, "update-staged"+suffix)
	if err := manager.Downloader.Stage(context, command, stagedPath); err != nil {
		return manager.fail(context, command, report, errorCode(err), err)
	}
	if err := manager.Store.Advance(command.ID, StateInstalling, ""); err != nil {
		return err
	}
	if err := report(context, command, StateInstalling, ""); err != nil {
		return err
	}
	requestPath := filepath.Join(directory, "update-request.json")
	contents, err := json.Marshal(command)
	if err != nil {
		return manager.fail(context, command, report, "invalid_command", err)
	}
	if err := atomicfile.Write(requestPath, contents, 0600); err != nil {
		return manager.fail(context, command, report, "request_write_failed", err)
	}
	if manager.Handoff != nil {
		if err := manager.Handoff(requestPath); err != nil {
			if errors.Is(err, ErrRestartRequired) {
				return err
			}
			return manager.fail(context, command, report, "handoff_failed", err)
		}
	}
	return nil
}

func (manager Manager) fail(
	context context.Context,
	command Command,
	report Reporter,
	code string,
	cause error,
) error {
	_ = manager.Store.Advance(command.ID, StateFailed, code)
	_ = report(context, command, StateFailed, code)
	return cause
}

func errorCode(err error) string {
	if errors.Is(err, ErrChecksumMismatch) {
		return "checksum_mismatch"
	}
	return "download_failed"
}

func isUpgrade(installed, target string) bool {
	installedParts, installedPre, ok := parseVersion(installed)
	if !ok {
		return false
	}
	targetParts, targetPre, ok := parseVersion(target)
	if !ok {
		return false
	}
	for index := range installedParts {
		if targetParts[index] != installedParts[index] {
			return targetParts[index] > installedParts[index]
		}
	}
	return installedPre != "" && (targetPre == "" || targetPre > installedPre)
}

func parseVersion(value string) ([3]int, string, bool) {
	var parts [3]int
	if !versionPattern.MatchString(value) {
		return parts, "", false
	}
	value = strings.TrimPrefix(value, "v")
	release, prerelease, _ := strings.Cut(value, "-")
	for index, part := range strings.Split(release, ".") {
		number, err := strconv.Atoi(part)
		if err != nil {
			return parts, "", false
		}
		parts[index] = number
	}
	return parts, prerelease, true
}
