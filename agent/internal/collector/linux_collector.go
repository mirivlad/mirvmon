//go:build linux

package collector

import (
	"context"
	"os"
	"sync"
	"syscall"
	"time"

	"github.com/mirivlad/mirvmon/agent/internal/protocol"
)

type filesystemStats struct {
	Blocks          uint64
	BlocksAvailable uint64
	BlockSize       uint64
}

type linuxSource struct {
	root     string
	readFile func(string) ([]byte, error)
	readDir  func(string) ([]os.DirEntry, error)
	statFS   func(string) (filesystemStats, error)
	command  func(context.Context, string, ...string) (string, error)
	sleep    func(time.Duration)
	now      func() time.Time
}

type linuxCollector struct {
	source         linuxSource
	tracker        *ServiceTracker
	mu             sync.Mutex
	prior          *linuxCounters
	priorProcesses map[int]linuxProcessUsage
}

// New returns the production Linux implementation of Collector.
func New() Collector {
	return newLinuxCollector(defaultLinuxSource())
}

func newLinuxCollector(source linuxSource) *linuxCollector {
	return &linuxCollector{source: source, tracker: NewServiceTracker(), priorProcesses: make(map[int]linuxProcessUsage)}
}

func defaultLinuxSource() linuxSource {
	return linuxSource{
		readFile: os.ReadFile,
		readDir:  os.ReadDir,
		statFS: func(path string) (filesystemStats, error) {
			var statistics syscall.Statfs_t
			if err := syscall.Statfs(path, &statistics); err != nil {
				return filesystemStats{}, err
			}
			return filesystemStats{
				Blocks:          statistics.Blocks,
				BlocksAvailable: statistics.Bavail,
				BlockSize:       uint64(statistics.Bsize),
			}, nil
		},
		command: runCommand,
		sleep:   time.Sleep,
		now:     time.Now,
	}
}

// Collect obtains a single bounded v2 measurement without relying on libc.
func (collector *linuxCollector) Collect(context context.Context, includeCommands bool) (protocol.Measurement, error) {
	if err := context.Err(); err != nil {
		return protocol.Measurement{}, err
	}
	collector.mu.Lock()
	defer collector.mu.Unlock()

	metrics, err := collector.collectMetrics()
	if err != nil {
		return protocol.Measurement{}, err
	}
	operatingSystem, err := collector.collectOSVersion()
	if err != nil {
		return protocol.Measurement{}, err
	}
	processes := collector.collectProcesses(includeCommands)
	services := collector.tracker.Changed(collector.collectServices(context))

	return protocol.Measurement{
		OSVersion:       operatingSystem,
		Metrics:         metrics,
		Services:        services,
		ProcessSnapshot: processes,
	}, nil
}

func (collector *linuxCollector) collectMetrics() (map[string]float64, error) {
	rates, err := collector.collectRates()
	if err != nil {
		return nil, err
	}
	memory, err := collector.collectMemory()
	if err != nil {
		return nil, err
	}
	uptime, err := collector.collectUptime()
	if err != nil {
		return nil, err
	}
	disks, err := collector.collectDiskUsage()
	if err != nil {
		return nil, err
	}
	load, err := collector.collectLoadAverage()
	if err != nil {
		return nil, err
	}
	required := map[string]float64{
		"cpu_load":           rates["cpu_load"],
		"ram_used":           memory["ram_used"],
		"ram_total_gb":       memory["ram_total_gb"],
		"uptime":             uptime,
		"load_1":             load["load_1"],
		"load_5":             load["load_5"],
		"load_15":            load["load_15"],
		"disk_used":          disks["disk_used"],
		"disk_used_root":     disks["disk_used_root"],
		"disk_total_gb_root": disks["disk_total_gb_root"],
	}
	optional := make(map[string]float64, len(rates)+len(disks)+1)
	for name, value := range rates {
		optional[name] = value
	}
	for name, value := range disks {
		optional[name] = value
	}
	if temperature, ok := collector.collectTemperature(); ok {
		optional["temp_system"] = temperature
	}
	return boundedMetrics(required, optional), nil
}
