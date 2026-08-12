//go:build windows

package atomicfile

import (
	"fmt"
	"syscall"
	"unsafe"
)

const (
	moveFileReplaceExisting = 0x1
	moveFileWriteThrough    = 0x8
)

var moveFileEx = syscall.NewLazyDLL("kernel32.dll").NewProc("MoveFileExW")

func replace(temporaryPath, destination string) error {
	temporary, err := syscall.UTF16PtrFromString(temporaryPath)
	if err != nil {
		return fmt.Errorf("encode temporary path: %w", err)
	}
	target, err := syscall.UTF16PtrFromString(destination)
	if err != nil {
		return fmt.Errorf("encode destination path: %w", err)
	}
	result, _, callErr := moveFileEx.Call(
		uintptr(unsafe.Pointer(temporary)),
		uintptr(unsafe.Pointer(target)),
		moveFileReplaceExisting|moveFileWriteThrough,
	)
	if result == 0 {
		return fmt.Errorf("replace destination: %w", callErr)
	}
	return nil
}
