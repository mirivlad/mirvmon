package collector

import (
	"regexp"
	"strings"
)

var sensitiveCommandValue = regexp.MustCompile(`(?i)(--(?:api-?key|authorization|passwd|password|secret|token)(?:=|\s+))[^\s]+`)

func redactCommand(value string) string {
	value = sensitiveCommandValue.ReplaceAllString(value, "$1[REDACTED]")
	return truncateString(strings.TrimSpace(strings.Join(strings.Fields(value), " ")), 512)
}
