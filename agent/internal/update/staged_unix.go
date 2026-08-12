//go:build !windows

package update

import (
	"errors"
	"os"
)

func platformValidateStaged(info os.FileInfo) error {
	if !info.Mode().IsRegular() || info.Mode().Perm()&0077 != 0 {
		return errors.New("staged update permissions are unsafe")
	}
	return nil
}
