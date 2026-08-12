//go:build linux

package collector

import (
	"fmt"
	"strings"
)

func (collector *linuxCollector) collectOSVersion() (string, error) {
	if contents, err := collector.source.readFile("/etc/nethserver-release"); err == nil {
		if version := strings.TrimSpace(string(contents)); version != "" {
			return truncateOSVersion(version), nil
		}
	}
	contents, err := collector.source.readFile("/etc/os-release")
	if err != nil {
		return "", fmt.Errorf("read operating system release: %w", err)
	}
	for _, line := range strings.Split(string(contents), "\n") {
		key, value, found := strings.Cut(line, "=")
		if !found || key != "PRETTY_NAME" {
			continue
		}
		value = strings.Trim(strings.TrimSpace(value), "\"")
		if value != "" {
			return truncateOSVersion(value), nil
		}
	}
	return "", fmt.Errorf("PRETTY_NAME missing from operating system release")
}
