//go:build windows

package update

import (
	"fmt"
	"io"
	"os"
	"os/exec"
	"path/filepath"
	"strconv"
)

// PlatformHandoff starts a protected copy of the trusted current binary. The
// service process then exits so the helper can replace the locked executable.
func PlatformHandoff(requestPath, configPath string) error {
	installed, err := os.Executable()
	if err != nil {
		return err
	}
	helper := filepath.Join(filepath.Dir(requestPath), "update-helper.exe")
	if err := copyExecutable(installed, helper); err != nil {
		return err
	}
	command := exec.Command(
		helper,
		"apply-update",
		"--config", configPath,
		"--request", requestPath,
		"--installed", installed,
		"--parent", strconv.Itoa(os.Getpid()),
	)
	if err := command.Start(); err != nil {
		return fmt.Errorf("start update helper: %w", err)
	}
	return ErrRestartRequired
}

func copyExecutable(source, destination string) error {
	input, err := os.Open(source)
	if err != nil {
		return err
	}
	defer input.Close()
	output, err := os.OpenFile(destination, os.O_CREATE|os.O_TRUNC|os.O_WRONLY, 0700)
	if err != nil {
		return err
	}
	if _, err := io.Copy(output, input); err != nil {
		_ = output.Close()
		return err
	}
	return output.Close()
}
