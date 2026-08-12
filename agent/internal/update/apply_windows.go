//go:build windows

package update

import (
	"errors"
	"time"

	"golang.org/x/sys/windows"
	"golang.org/x/sys/windows/svc"
	"golang.org/x/sys/windows/svc/mgr"
)

func platformApply(staged, installed string, parentPID int, targetVersion, healthPath string) error {
	if parentPID > 0 {
		handle, err := windows.OpenProcess(windows.SYNCHRONIZE, false, uint32(parentPID))
		if err == nil {
			defer windows.CloseHandle(handle)
			status, waitErr := windows.WaitForSingleObject(handle, 30000)
			if waitErr != nil || status == uint32(windows.WAIT_TIMEOUT) {
				return errors.New("agent service did not stop")
			}
		}
	}
	restart := func() error { return startService() }
	return replaceExecutable(staged, installed, restart, func() error {
		return waitForTargetHealth(healthPath, targetVersion, 30*time.Second)
	}, stopService)
}

func startService() error {
	manager, err := mgr.Connect()
	if err != nil {
		return err
	}
	defer manager.Disconnect()
	service, err := manager.OpenService("MirvMonAgent")
	if err != nil {
		return err
	}
	defer service.Close()
	return service.Start()
}

func stopService() error {
	manager, err := mgr.Connect()
	if err != nil {
		return err
	}
	defer manager.Disconnect()
	service, err := manager.OpenService("MirvMonAgent")
	if err != nil {
		return err
	}
	defer service.Close()
	status, err := service.Query()
	if err != nil || status.State == svc.Stopped {
		return err
	}
	if _, err := service.Control(svc.Stop); err != nil {
		return err
	}
	deadline := time.Now().Add(30 * time.Second)
	for time.Now().Before(deadline) {
		status, err = service.Query()
		if err != nil {
			return err
		}
		if status.State == svc.Stopped {
			return nil
		}
		time.Sleep(250 * time.Millisecond)
	}
	return errors.New("agent service did not stop")
}
