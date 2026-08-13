// Package wininstall installs the selected bundled Windows agent without
// depending on PowerShell or a command interpreter.
package wininstall

import (
	"context"
	"crypto/sha256"
	"encoding/hex"
	"errors"
	"fmt"
	"io"
	"os"
	"path/filepath"
	"regexp"

	"github.com/mirivlad/mirvmon/agent/internal/config"
	"github.com/mirivlad/mirvmon/agent/internal/enroll"
	"github.com/mirivlad/mirvmon/agent/internal/migrate"
	"github.com/mirivlad/mirvmon/agent/internal/queue"
)

var checksumPattern = regexp.MustCompile(`^[a-f0-9]{64}$`)

// Request contains public artifact metadata and protected local paths.
type Request struct {
	BootstrapPath    string
	ExecutablePath   string
	ExpectedVersion  string
	ExpectedArtifact string
	ExpectedSHA256   string
	ExpectedSize     int64
	CurrentVersion   string
	CurrentArtifact  string
	InstallDir       string
	StateDir         string
	Platform         Platform
	Activate         func(context.Context, string, string) error
	Migrate          func(Paths) error
}

// Paths names every staged, source, and installed file in one transaction.
type Paths struct {
	Bootstrap       string
	SelectedAgent   string
	InstalledAgent  string
	InstalledConfig string
	InstalledQueue  string
	LegacyQueue     string
	SourceConfig    string
	SourceQueue     string
	ServerConfig    string
	StagedConfig    string
	StagedQueue     string
	CheckConfig     string
	Quarantine      string
	StageDir        string
}

// Snapshot is opaque transaction state owned by the platform adapter.
type Snapshot struct {
	Value any
}

// Platform isolates Windows-only privilege, service, task, ACL, and file work.
type Platform interface {
	Validate(Request) error
	ProtectStage(Paths) error
	Snapshot(Paths) (Snapshot, error)
	Protect(Paths) error
	Freeze(Snapshot) error
	InstallFiles(Paths, Snapshot) error
	ConfigureService(Paths, Snapshot) error
	StartService() error
	VerifyService() error
	DeleteLegacyTask(Snapshot) error
	Rollback(Paths, Snapshot) error
}

// Install runs a preflight-first, rollback-capable native installation.
func Install(ctx context.Context, request Request) error {
	if validateRequest(request) != nil {
		return stageError("arguments")
	}
	if request.Platform.Validate(request) != nil {
		return stageError("prerequisites")
	}
	info, err := os.Stat(request.ExecutablePath)
	if err != nil || info.Size() != request.ExpectedSize {
		return stageError("validate-size")
	}
	checksum, err := SHA256(request.ExecutablePath)
	if err != nil || checksum != request.ExpectedSHA256 {
		return stageError("validate-checksum")
	}

	paths, err := request.makePaths()
	if err != nil {
		return stageError("staging")
	}
	defer os.RemoveAll(paths.StageDir)
	request.setSources(&paths)
	if request.Platform.ProtectStage(paths) != nil {
		return stageError("protect-stage")
	}

	activate := request.Activate
	if activate == nil {
		activate = func(ctx context.Context, bootstrapPath, outputConfig string) error {
			return enroll.Activate(ctx, enroll.Request{BootstrapPath: bootstrapPath, OutputConfig: outputConfig})
		}
	}
	if activate(ctx, paths.Bootstrap, paths.ServerConfig) != nil {
		return stageError("activate")
	}
	migrateState := request.Migrate
	if migrateState == nil {
		migrateState = migratePaths
	}
	if migrateState(paths) != nil {
		return stageError("migrate-preflight")
	}
	if checkStaged(paths) != nil {
		return stageError("check-preflight")
	}

	snapshot, err := request.Platform.Snapshot(paths)
	if err != nil {
		return stageError("snapshot")
	}
	if request.Platform.Protect(paths) != nil {
		return rollbackError(request.Platform, paths, snapshot, "protect")
	}
	if request.Platform.Freeze(snapshot) != nil {
		return rollbackError(request.Platform, paths, snapshot, "freeze")
	}
	if migrateState(paths) != nil {
		return rollbackError(request.Platform, paths, snapshot, "migrate-commit")
	}
	if checkStaged(paths) != nil {
		return rollbackError(request.Platform, paths, snapshot, "check-commit")
	}
	for _, step := range []struct {
		name string
		run  func() error
	}{
		{"install-files", func() error { return request.Platform.InstallFiles(paths, snapshot) }},
		{"configure-service", func() error { return request.Platform.ConfigureService(paths, snapshot) }},
		{"start-service", request.Platform.StartService},
		{"verify-service", request.Platform.VerifyService},
		{"delete-legacy-task", func() error { return request.Platform.DeleteLegacyTask(snapshot) }},
	} {
		if step.run() != nil {
			return rollbackError(request.Platform, paths, snapshot, step.name)
		}
	}
	return nil
}

func validateRequest(request Request) error {
	if request.BootstrapPath == "" || request.ExecutablePath == "" ||
		request.ExpectedVersion == "" || request.ExpectedArtifact == "" ||
		request.ExpectedSize < 1 || !checksumPattern.MatchString(request.ExpectedSHA256) ||
		request.CurrentVersion == "" || request.CurrentArtifact == "" ||
		request.InstallDir == "" || request.StateDir == "" || request.Platform == nil {
		return errors.New("invalid request")
	}
	if request.CurrentVersion != request.ExpectedVersion {
		return errors.New("version mismatch")
	}
	if request.CurrentArtifact != request.ExpectedArtifact {
		return errors.New("artifact mismatch")
	}
	return nil
}

func (request Request) makePaths() (Paths, error) {
	stageDir, err := os.MkdirTemp("", "mirvmon-install-")
	if err != nil {
		return Paths{}, err
	}
	if err := os.Chmod(stageDir, 0700); err != nil {
		_ = os.RemoveAll(stageDir)
		return Paths{}, err
	}
	return Paths{
		Bootstrap:       request.BootstrapPath,
		SelectedAgent:   request.ExecutablePath,
		InstalledAgent:  filepath.Join(request.InstallDir, "mirvmon-agent.exe"),
		InstalledConfig: filepath.Join(request.StateDir, "config.json"),
		InstalledQueue:  filepath.Join(request.StateDir, "queue.json"),
		LegacyQueue:     filepath.Join(request.StateDir, "queue.txt"),
		ServerConfig:    filepath.Join(stageDir, "server-config.json"),
		StagedConfig:    filepath.Join(stageDir, "config.json"),
		StagedQueue:     filepath.Join(stageDir, "queue.json"),
		CheckConfig:     filepath.Join(stageDir, "check-config.json"),
		Quarantine:      filepath.Join(stageDir, "quarantine.json"),
		StageDir:        stageDir,
	}, nil
}

func (request Request) setSources(paths *Paths) {
	if regularFile(paths.InstalledConfig) {
		paths.SourceConfig = paths.InstalledConfig
	}
	if regularFile(paths.InstalledQueue) {
		paths.SourceQueue = paths.InstalledQueue
	} else if regularFile(paths.LegacyQueue) {
		paths.SourceQueue = paths.LegacyQueue
	}
}

func migratePaths(paths Paths) error {
	_, err := migrate.Import(migrate.Request{
		SourceConfig: paths.SourceConfig, SourceQueue: paths.SourceQueue,
		ServerConfig: paths.ServerConfig, OutputConfig: paths.StagedConfig,
		OutputQueue: paths.StagedQueue, QuarantinePath: paths.Quarantine,
	})
	return err
}

func checkStaged(paths Paths) error {
	configuration, raw, err := config.Load(paths.StagedConfig)
	if err != nil {
		return err
	}
	configuration.QueuePath = paths.StagedQueue
	if err := config.WriteAtomic(paths.CheckConfig, configuration, raw); err != nil {
		return err
	}
	_, err = queue.Open(paths.StagedQueue, configuration.QueueLimit)
	return err
}

func rollbackError(platform Platform, paths Paths, snapshot Snapshot, stage string) error {
	if platform.Rollback(paths, snapshot) != nil {
		return stageError(stage + "-rollback")
	}
	return stageError(stage)
}

func stageError(stage string) error {
	return fmt.Errorf("windows installation failed at %s", stage)
}

func regularFile(path string) bool {
	info, err := os.Stat(path)
	return err == nil && info.Mode().IsRegular()
}

// SHA256 returns the lowercase digest of one file.
func SHA256(path string) (string, error) {
	file, err := os.Open(path)
	if err != nil {
		return "", err
	}
	defer file.Close()
	digest := sha256.New()
	if _, err := io.Copy(digest, file); err != nil {
		return "", err
	}
	return hex.EncodeToString(digest.Sum(nil)), nil
}
