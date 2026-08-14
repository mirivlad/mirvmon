//go:build !windows

package update

import (
	"errors"
	"os"
	"os/exec"
	"strings"
	"time"
)

func platformApply(staged, installed string, _ int, targetVersion, healthPath string) error {
	restart := func() error {
		if err := runLinuxAgentService("restart"); err != nil {
			return err
		}
		return runLinuxAgentService("status")
	}
	return replaceExecutable(staged, installed, restart, func() error {
		return waitForTargetHealth(healthPath, targetVersion, 30*time.Second)
	}, func() error {
		return runLinuxAgentService("stop")
	})
}

func runLinuxAgentService(action string) error {
	if linuxUsesSystemd() {
		if action == "status" {
			return exec.Command("systemctl", "is-active", "--quiet", "mirvmon-agent.service").Run()
		}
		return exec.Command("systemctl", action, "mirvmon-agent.service").Run()
	}

	const initScript = "/etc/init.d/mirvmon-agent"
	if info, err := os.Stat(initScript); err == nil && !info.IsDir() {
		return exec.Command(initScript, action).Run()
	}
	if _, err := exec.LookPath("service"); err == nil {
		return exec.Command("service", "mirvmon-agent", action).Run()
	}
	return errors.New("no supported Linux service manager found")
}

func linuxUsesSystemd() bool {
	contents, err := os.ReadFile("/proc/1/comm")
	if err != nil || strings.TrimSpace(string(contents)) != "systemd" {
		return false
	}
	_, err = exec.LookPath("systemctl")
	return err == nil
}

// PlatformRecoverHandoff is unnecessary on Linux because the agent keeps
// running while a separate root-owned systemd or SysV updater applies the
// fixed update request.
func PlatformRecoverHandoff(int) error { return nil }
