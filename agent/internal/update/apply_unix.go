//go:build !windows

package update

import (
	"os/exec"
	"time"
)

func platformApply(staged, installed string, _ int, targetVersion, healthPath string) error {
	restart := func() error {
		if err := exec.Command("systemctl", "restart", "mirvmon-agent.service").Run(); err != nil {
			return err
		}
		return exec.Command("systemctl", "is-active", "--quiet", "mirvmon-agent.service").Run()
	}
	return replaceExecutable(staged, installed, restart, func() error {
		return waitForTargetHealth(healthPath, targetVersion, 30*time.Second)
	}, func() error {
		return exec.Command("systemctl", "stop", "mirvmon-agent.service").Run()
	})
}

// PlatformRecoverHandoff is unnecessary on Linux because the collector never
// exits while the separate one-shot updater authorizes the request.
func PlatformRecoverHandoff(int) error { return nil }
