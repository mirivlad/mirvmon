//go:build !windows

package atomicfile

import (
	"fmt"
	"os"
	"path/filepath"
)

func replace(temporaryPath, destination string) error {
	if err := os.Rename(temporaryPath, destination); err != nil {
		return fmt.Errorf("replace destination: %w", err)
	}
	directory, err := os.Open(filepath.Dir(destination))
	if err != nil {
		return fmt.Errorf("open parent directory: %w", err)
	}
	defer directory.Close()
	if err := directory.Sync(); err != nil {
		return fmt.Errorf("sync parent directory: %w", err)
	}
	return nil
}
