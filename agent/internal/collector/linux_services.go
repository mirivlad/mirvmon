//go:build linux

package collector

import (
	"context"
	"os/exec"
	"regexp"
	"sort"
	"strings"

	"github.com/mirivlad/mirvmon/agent/internal/protocol"
)

var sysVStatusLine = regexp.MustCompile(`^\s*\[\s*([+\-?])\s*\]\s+(.+?)\s*$`)

func (collector *linuxCollector) collectServices(context context.Context) []protocol.ServiceState {
	if contents, err := collector.source.readFile("/proc/1/comm"); err == nil && strings.TrimSpace(string(contents)) == "systemd" {
		if services, err := collector.systemdServices(context); err == nil {
			return services
		}
	}
	if services, err := collector.sysVServices(context); err == nil && len(services) > 0 {
		return services
	}
	return nil
}

func (collector *linuxCollector) systemdServices(context context.Context) ([]protocol.ServiceState, error) {
	output, err := collector.source.command(
		context,
		"systemctl",
		"show", "--type=service", "--all", "--no-pager",
		"--property=Id", "--property=LoadState", "--property=ActiveState", "--property=SubState",
	)
	if err != nil {
		return nil, err
	}
	records := strings.Split(strings.TrimSpace(output), "\n\n")
	services := make([]protocol.ServiceState, 0, len(records))
	for _, record := range records {
		values := make(map[string]string)
		for _, line := range strings.Split(record, "\n") {
			key, value, ok := strings.Cut(line, "=")
			if ok {
				values[key] = value
			}
		}
		name := serviceName(values["Id"])
		if name == "" {
			continue
		}
		active := truncateString(values["ActiveState"], 50)
		services = append(services, protocol.ServiceState{
			Name:        name,
			Status:      normalizeServiceStatus(active),
			LoadState:   truncateString(values["LoadState"], 50),
			ActiveState: active,
			SubState:    truncateString(values["SubState"], 50),
		})
	}
	return sortedServices(services), nil
}

func (collector *linuxCollector) sysVServices(context context.Context) ([]protocol.ServiceState, error) {
	output, serviceErr := collector.source.command(context, "service", "--status-all")
	services := parseSysVServiceStatus(output)
	if len(services) > 0 {
		return services, nil
	}
	output, chkconfigErr := collector.source.command(context, "chkconfig", "--list")
	services = parseChkconfig(output)
	if len(services) > 0 {
		return services, nil
	}
	if chkconfigErr != nil {
		return nil, chkconfigErr
	}
	return nil, serviceErr
}

func parseSysVServiceStatus(output string) []protocol.ServiceState {
	services := make([]protocol.ServiceState, 0)
	for _, line := range strings.Split(output, "\n") {
		matches := sysVStatusLine.FindStringSubmatch(line)
		if len(matches) != 3 {
			continue
		}
		name := serviceName(matches[2])
		if name == "" {
			continue
		}
		status, active := "unknown", "unknown"
		switch matches[1] {
		case "+":
			status, active = "running", "active"
		case "-":
			status, active = "stopped", "inactive"
		}
		services = append(services, protocol.ServiceState{
			Name: name, Status: status, LoadState: "sysv", ActiveState: active, SubState: "unknown",
		})
	}
	return sortedServices(services)
}

func parseChkconfig(output string) []protocol.ServiceState {
	services := make([]protocol.ServiceState, 0)
	for _, line := range strings.Split(output, "\n") {
		fields := strings.Fields(line)
		if len(fields) == 0 {
			continue
		}
		name := serviceName(fields[0])
		if name == "" {
			continue
		}
		running := false
		for _, field := range fields[1:] {
			if strings.HasSuffix(field, ":on") {
				running = true
				break
			}
		}
		status, active := "stopped", "inactive"
		if running {
			status, active = "running", "active"
		}
		services = append(services, protocol.ServiceState{
			Name: name, Status: status, LoadState: "sysv", ActiveState: active, SubState: "unknown",
		})
	}
	return sortedServices(services)
}

func runCommand(context context.Context, name string, arguments ...string) (string, error) {
	output, err := exec.CommandContext(context, name, arguments...).Output()
	return string(output), err
}

func normalizeServiceStatus(active string) string {
	switch active {
	case "active":
		return "running"
	case "inactive", "failed", "deactivating":
		return "stopped"
	default:
		return "unknown"
	}
}

func sortedServices(services []protocol.ServiceState) []protocol.ServiceState {
	sort.Slice(services, func(left, right int) bool { return services[left].Name < services[right].Name })
	if len(services) > 500 {
		return services[:500]
	}
	return services
}
