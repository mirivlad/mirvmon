//go:build windows

package collector

import (
	"sort"

	"golang.org/x/sys/windows/svc"
	"golang.org/x/sys/windows/svc/mgr"
)

func querySCMServices() ([]serviceRecord, error) {
	manager, err := mgr.Connect()
	if err != nil {
		return nil, err
	}
	defer manager.Disconnect()
	names, err := manager.ListServices()
	if err != nil {
		return nil, err
	}
	records := make([]serviceRecord, 0, len(names))
	for _, name := range names {
		service, err := manager.OpenService(name)
		if err != nil {
			continue
		}
		status, queryErr := service.Query()
		service.Close()
		if queryErr != nil {
			continue
		}
		records = append(records, serviceRecord{Name: name, State: scmState(status.State)})
	}
	sort.Slice(records, func(left, right int) bool { return records[left].Name < records[right].Name })
	return records, nil
}

func scmState(state svc.State) string {
	switch state {
	case svc.Running:
		return "running"
	case svc.Stopped:
		return "stopped"
	case svc.StartPending:
		return "start_pending"
	case svc.StopPending:
		return "stop_pending"
	case svc.PausePending:
		return "pause_pending"
	case svc.Paused:
		return "paused"
	case svc.ContinuePending:
		return "continue_pending"
	default:
		return "unknown"
	}
}
