// Package atomicfile provides durable same-directory file replacement for
// configuration, queue, and health-state files.
package atomicfile

import (
	"fmt"
	"os"
	"path/filepath"
)

// Write replaces path only after its new content has been written and synced
// to a private temporary file in the same directory.
func Write(path string, data []byte, mode os.FileMode) (err error) {
	directory := filepath.Dir(path)
	temporary, err := os.CreateTemp(directory, "."+filepath.Base(path)+".")
	if err != nil {
		return fmt.Errorf("create temporary file: %w", err)
	}
	temporaryPath := temporary.Name()
	replaced := false
	defer func() {
		if !replaced {
			_ = os.Remove(temporaryPath)
		}
	}()

	if _, err := temporary.Write(data); err != nil {
		_ = temporary.Close()
		return fmt.Errorf("write temporary file: %w", err)
	}
	if err := temporary.Sync(); err != nil {
		_ = temporary.Close()
		return fmt.Errorf("sync temporary file: %w", err)
	}
	if err := temporary.Chmod(mode); err != nil {
		_ = temporary.Close()
		return fmt.Errorf("set temporary file mode: %w", err)
	}
	if err := temporary.Close(); err != nil {
		return fmt.Errorf("close temporary file: %w", err)
	}
	if err := replace(temporaryPath, path); err != nil {
		return err
	}
	replaced = true
	return nil
}
