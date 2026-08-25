//go:build windows

package collector

import (
	"sort"

	"github.com/mirivlad/mirvmon/agent/internal/protocol"
)

type windowsProcessPerformance struct {
	IDProcess            uint32
	Name                 string
	PercentProcessorTime uint64
	WorkingSetPrivate    uint64
}

type windowsProcessCommand struct {
	ProcessID   uint32
	CommandLine string
}

func (collector *windowsCollector) collectProcesses(includeCommands bool) *protocol.ProcessSnapshot {
	var performance []windowsProcessPerformance
	if collector.source.query("SELECT IDProcess, Name, PercentProcessorTime, WorkingSetPrivate FROM Win32_PerfFormattedData_PerfProc_Process", &performance) != nil {
		return &protocol.ProcessSnapshot{}
	}
	commands := make(map[uint32]string)
	if includeCommands {
		var values []windowsProcessCommand
		if collector.source.query("SELECT ProcessID, CommandLine FROM Win32_Process", &values) == nil {
			for _, value := range values {
				commands[value.ProcessID] = redactCommand(value.CommandLine)
			}
		}
	}
	processes := make([]windowsCollectedProcess, 0, len(performance))
	for _, process := range performance {
		if process.IDProcess == 0 || process.Name == "_Total" || process.Name == "Idle" {
			continue
		}
		processes = append(processes, windowsCollectedProcess{
			pid:     int(process.IDProcess),
			name:    truncateString(process.Name, 255),
			command: commands[process.IDProcess],
			cpu:     float64(process.PercentProcessorTime) / float64(collector.source.logicalProcessors),
			memory:  float64(process.WorkingSetPrivate) / 1024,
		})
	}
	sort.Slice(processes, func(left, right int) bool {
		if processes[left].cpu == processes[right].cpu {
			return processes[left].pid < processes[right].pid
		}
		return processes[left].cpu > processes[right].cpu
	})
	topCPU := windowsProcessList(processes, func(process windowsCollectedProcess) float64 { return process.cpu })
	sort.Slice(processes, func(left, right int) bool {
		if processes[left].memory == processes[right].memory {
			return processes[left].pid < processes[right].pid
		}
		return processes[left].memory > processes[right].memory
	})
	return &protocol.ProcessSnapshot{
		TopCPU:    topCPU,
		TopMemory: windowsProcessList(processes, func(process windowsCollectedProcess) float64 { return process.memory }),
	}
}

type windowsCollectedProcess struct {
	pid     int
	name    string
	command string
	cpu     float64
	memory  float64
}

func windowsProcessList(processes []windowsCollectedProcess, value func(windowsCollectedProcess) float64) []protocol.Process {
	limit := len(processes)
	if limit > 20 {
		limit = 20
	}
	result := make([]protocol.Process, 0, limit)
	for _, process := range processes[:limit] {
		result = append(result, protocol.Process{
			PID: process.pid, Name: process.name, Command: process.command, Value: value(process),
		})
	}
	return result
}
