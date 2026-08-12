//go:build windows

package update

import (
	"errors"
	"os"
)

func platformValidateStaged(info os.FileInfo) error {
	if !info.Mode().IsRegular() {
		return errors.New("staged update is not a regular file")
	}
	return nil
}
