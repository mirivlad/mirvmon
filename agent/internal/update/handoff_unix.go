//go:build !windows

package update

// PlatformHandoff relies on the root-owned systemd path unit installed by the
// server-generated installer.
func PlatformHandoff(string, string) error { return nil }
