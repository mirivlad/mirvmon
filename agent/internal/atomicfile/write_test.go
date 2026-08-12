package atomicfile

import (
	"os"
	"path/filepath"
	"testing"
)

func TestWriteAtomicallyReplacesExistingFileWithRequestedMode(t *testing.T) {
	directory := t.TempDir()
	path := filepath.Join(directory, "queue.json")
	if err := os.WriteFile(path, []byte("old"), 0644); err != nil {
		t.Fatal(err)
	}

	if err := Write(path, []byte("new"), 0600); err != nil {
		t.Fatal(err)
	}

	contents, err := os.ReadFile(path)
	if err != nil {
		t.Fatal(err)
	}
	if string(contents) != "new" {
		t.Fatalf("contents = %q, want new", contents)
	}
	info, err := os.Stat(path)
	if err != nil {
		t.Fatal(err)
	}
	if got := info.Mode().Perm(); got != 0600 {
		t.Fatalf("mode = %o, want 600", got)
	}
	matches, err := filepath.Glob(filepath.Join(directory, ".queue.json.*"))
	if err != nil {
		t.Fatal(err)
	}
	if len(matches) != 0 {
		t.Fatalf("temporary files remain: %v", matches)
	}
}

func TestWriteRejectsMissingParentDirectory(t *testing.T) {
	path := filepath.Join(t.TempDir(), "missing", "config.json")
	if err := Write(path, []byte("{}"), 0600); err == nil {
		t.Fatal("Write succeeded with a missing parent directory")
	}
}
