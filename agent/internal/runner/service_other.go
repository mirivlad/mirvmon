//go:build !windows

package runner

import "context"

// RunService runs in the foreground on non-Windows platforms.
func RunService(context context.Context, runner *Runner) error {
	return runner.Run(context)
}
