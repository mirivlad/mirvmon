//go:build linux

package collector

import (
	"sort"
	"strconv"
	"strings"
	"time"

	"github.com/mirivlad/mirvmon/agent/internal/protocol"
)

type linuxProcess struct {
	pid       int
	name      string
	command   string
	cpu       float64
	cpuTicks  float64
	startTime uint64
	memory    float64
}

type linuxProcessUsage struct {
	ticks     float64
	startTime uint64
	observed  time.Time
}

func (collector *linuxCollector) collectProcesses(includeCommands bool) *protocol.ProcessSnapshot {
	entries, err := collector.source.readDir("/proc")
	if err != nil {
		return &protocol.ProcessSnapshot{}
	}
	observed := collector.source.now()
	processes := make([]linuxProcess, 0)
	next := make(map[int]linuxProcessUsage)
	for _, entry := range entries {
		pid, err := strconv.Atoi(entry.Name())
		if err != nil || pid < 1 || !entry.IsDir() {
			continue
		}
		process, ok := collector.readProcess(pid, includeCommands)
		if ok {
			process.cpu = collector.processCPUPercent(process, observed)
			next[pid] = linuxProcessUsage{ticks: process.cpuTicks, startTime: process.startTime, observed: observed}
			processes = append(processes, process)
		}
	}
	collector.priorProcesses = next
	sort.Slice(processes, func(left, right int) bool {
		if processes[left].cpu == processes[right].cpu {
			return processes[left].pid < processes[right].pid
		}
		return processes[left].cpu > processes[right].cpu
	})
	topCPU := processList(processes, func(process linuxProcess) float64 { return process.cpu })
	sort.Slice(processes, func(left, right int) bool {
		if processes[left].memory == processes[right].memory {
			return processes[left].pid < processes[right].pid
		}
		return processes[left].memory > processes[right].memory
	})
	return &protocol.ProcessSnapshot{
		TopCPU:    topCPU,
		TopMemory: processList(processes, func(process linuxProcess) float64 { return process.memory }),
	}
}

func (collector *linuxCollector) readProcess(pid int, includeCommands bool) (linuxProcess, bool) {
	base := "/proc/" + strconv.Itoa(pid)
	stat, err := collector.source.readFile(base + "/stat")
	if err != nil {
		return linuxProcess{}, false
	}
	cpuTicks, startTime, ok := processCPUCounters(string(stat))
	if !ok {
		return linuxProcess{}, false
	}
	name := ""
	if contents, err := collector.source.readFile(base + "/comm"); err == nil {
		name = truncateString(strings.TrimSpace(string(contents)), 255)
	}
	if name == "" {
		return linuxProcess{}, false
	}
	process := linuxProcess{pid: pid, name: name, cpuTicks: cpuTicks, startTime: startTime}
	if contents, err := collector.source.readFile(base + "/status"); err == nil {
		process.memory = processMemoryKB(string(contents))
	}
	if includeCommands {
		if contents, err := collector.source.readFile(base + "/cmdline"); err == nil {
			process.command = redactCommand(strings.ReplaceAll(string(contents), "\x00", " "))
		}
	}
	return process, true
}

func (collector *linuxCollector) processCPUPercent(process linuxProcess, observed time.Time) float64 {
	prior, ok := collector.priorProcesses[process.pid]
	if !ok || prior.startTime != process.startTime || !observed.After(prior.observed) || process.cpuTicks < prior.ticks {
		return 0
	}
	// Linux /proc CPU fields are measured in USER_HZ, which Linux defines as 100.
	return (process.cpuTicks - prior.ticks) / observed.Sub(prior.observed).Seconds()
}

func processCPUCounters(stat string) (float64, uint64, bool) {
	closeParenthesis := strings.LastIndex(stat, ")")
	if closeParenthesis == -1 || closeParenthesis+2 >= len(stat) {
		return 0, 0, false
	}
	fields := strings.Fields(stat[closeParenthesis+2:])
	if len(fields) < 20 {
		return 0, 0, false
	}
	user, userErr := strconv.ParseUint(fields[11], 10, 64)
	system, systemErr := strconv.ParseUint(fields[12], 10, 64)
	start, startErr := strconv.ParseUint(fields[19], 10, 64)
	if userErr != nil || systemErr != nil || startErr != nil {
		return 0, 0, false
	}
	return float64(user + system), start, true
}

func processMemoryKB(status string) float64 {
	for _, line := range strings.Split(status, "\n") {
		fields := strings.Fields(line)
		if len(fields) < 2 || fields[0] != "VmRSS:" {
			continue
		}
		value, err := strconv.ParseUint(fields[1], 10, 64)
		if err == nil {
			return float64(value)
		}
	}
	return 0
}

func processList(processes []linuxProcess, value func(linuxProcess) float64) []protocol.Process {
	limit := len(processes)
	if limit > 20 {
		limit = 20
	}
	result := make([]protocol.Process, 0, limit)
	for _, process := range processes[:limit] {
		result = append(result, protocol.Process{
			PID:     process.pid,
			Name:    process.name,
			Command: process.command,
			Value:   value(process),
		})
	}
	return result
}
