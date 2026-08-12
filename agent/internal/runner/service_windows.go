//go:build windows

package runner

import (
	"context"

	"golang.org/x/sys/windows/svc"
)

// RunService enters SCM hosting when invoked as a Windows service; command-line
// use remains foreground to keep installer checks deterministic.
func RunService(context context.Context, runner *Runner) error {
	isService, err := svc.IsWindowsService()
	if err != nil || !isService {
		return runner.Run(context)
	}
	return svc.Run("MirvMonAgent", serviceHandler{parent: context, runner: runner})
}

type serviceHandler struct {
	parent context.Context
	runner *Runner
}

func (handler serviceHandler) Execute(_ []string, requests <-chan svc.ChangeRequest, statuses chan<- svc.Status) (bool, uint32) {
	statuses <- svc.Status{State: svc.StartPending}
	context, cancel := context.WithCancel(handler.parent)
	defer cancel()
	statuses <- svc.Status{State: svc.Running, Accepts: svc.AcceptStop | svc.AcceptShutdown}
	done := make(chan error, 1)
	go func() { done <- handler.runner.Run(context) }()
	for {
		select {
		case request := <-requests:
			if request.Cmd == svc.Stop || request.Cmd == svc.Shutdown {
				statuses <- svc.Status{State: svc.StopPending}
				cancel()
				<-done
				statuses <- svc.Status{State: svc.Stopped}
				return false, 0
			}
		case <-done:
			statuses <- svc.Status{State: svc.Stopped}
			return false, 1
		}
	}
}
