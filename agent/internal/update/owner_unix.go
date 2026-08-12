//go:build !windows

package update

import (
	"errors"
	"os"
	"syscall"
)

func platformPreserveStateOwner(queuePath, statePath string) error {
	queueInfo, err := os.Stat(queuePath)
	if err != nil {
		return err
	}
	stat, ok := queueInfo.Sys().(*syscall.Stat_t)
	if !ok {
		return errors.New("queue owner is unavailable")
	}
	return os.Chown(statePath, int(stat.Uid), int(stat.Gid))
}
