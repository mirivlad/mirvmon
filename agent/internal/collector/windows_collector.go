//go:build windows

package collector

import (
	"context"
	"errors"
	"runtime"
	"sort"

	"github.com/StackExchange/wmi"
	"github.com/mirivlad/mirvmon/agent/internal/protocol"
)

type wmiQuery func(string, any) error

type windowsSource struct {
	query             wmiQuery
	logicalProcessors int
	services          func() ([]serviceRecord, error)
	registryOS        func() (windowsOperatingSystem, error)
}

type windowsCollector struct {
	source  windowsSource
	tracker *ServiceTracker
}

type serviceRecord struct {
	Name  string
	State string
}

// New returns the production Windows implementation of Collector.
func New() Collector {
	return newWindowsCollector(windowsSource{
		query: func(query string, destination any) error {
			return wmi.Query(query, destination)
		},
		logicalProcessors: runtime.NumCPU(),
		services:          querySCMServices,
		registryOS:        registryOperatingSystem,
	})
}

func newWindowsCollector(source windowsSource) *windowsCollector {
	if source.logicalProcessors < 1 {
		source.logicalProcessors = 1
	}
	return &windowsCollector{source: source, tracker: NewServiceTracker()}
}

// Collect obtains host state through WMI, SCM, and the registry only.
func (collector *windowsCollector) Collect(context context.Context, includeCommands bool) (protocol.Measurement, error) {
	if err := context.Err(); err != nil {
		return protocol.Measurement{}, err
	}
	operatingSystem, err := collector.operatingSystem()
	if err != nil {
		return protocol.Measurement{}, err
	}
	metrics, err := collector.collectMetrics(operatingSystem)
	if err != nil {
		return protocol.Measurement{}, err
	}
	services, err := collector.source.services()
	if err != nil {
		services = nil
	}
	processes := collector.collectProcesses(includeCommands, operatingSystem.TotalVisibleMemorySize*1024)

	return protocol.Measurement{
		OSVersion:       normalizeWindowsVersion(operatingSystem.Caption, servicePack(operatingSystem.ServicePackMajorVersion), operatingSystem.BuildNumber),
		Metrics:         metrics,
		Services:        collector.tracker.Changed(normalizeWindowsServices(services)),
		ProcessSnapshot: processes,
	}, nil
}

func (collector *windowsCollector) operatingSystem() (windowsOperatingSystem, error) {
	var values []windowsOperatingSystem
	err := collector.source.query(
		"SELECT TotalVisibleMemorySize, FreePhysicalMemory, Caption, ServicePackMajorVersion, BuildNumber, LastBootUpTime FROM Win32_OperatingSystem",
		&values,
	)
	if err == nil && len(values) > 0 && values[0].Caption != "" && values[0].TotalVisibleMemorySize > 0 {
		return values[0], nil
	}
	fallback, fallbackErr := collector.source.registryOS()
	if fallbackErr != nil {
		if err != nil {
			return windowsOperatingSystem{}, err
		}
		return windowsOperatingSystem{}, fallbackErr
	}
	if fallback.Caption == "" {
		return windowsOperatingSystem{}, errors.New("Windows product name is unavailable")
	}
	return fallback, nil
}

func (collector *windowsCollector) collectMetrics(operatingSystem windowsOperatingSystem) (map[string]float64, error) {
	var processors []windowsProcessor
	if err := collector.source.query(
		"SELECT Name, PercentProcessorTime FROM Win32_PerfFormattedData_PerfOS_Processor",
		&processors,
	); err != nil {
		return nil, err
	}
	cpu := float64(0)
	for _, processor := range processors {
		if processor.Name == "_Total" {
			cpu = float64(processor.PercentProcessorTime) / float64(collector.source.logicalProcessors)
			break
		}
	}
	if cpu < 0 {
		cpu = 0
	}
	if cpu > 100 {
		cpu = 100
	}
	if operatingSystem.TotalVisibleMemorySize == 0 || operatingSystem.FreePhysicalMemory > operatingSystem.TotalVisibleMemorySize {
		return nil, errors.New("invalid Windows memory counters")
	}
	totalMemory := operatingSystem.TotalVisibleMemorySize * 1024
	required := map[string]float64{
		"cpu_load":     cpu,
		"ram_used":     float64(operatingSystem.TotalVisibleMemorySize-operatingSystem.FreePhysicalMemory) * 100 / float64(operatingSystem.TotalVisibleMemorySize),
		"ram_total_gb": float64(totalMemory) / (1024 * 1024 * 1024),
		"uptime":       windowsUptime(operatingSystem.LastBootUpTime),
	}
	diskMetrics, primaryDisk := collector.diskMetrics()
	for name, value := range diskMetrics {
		required[name] = value
	}
	if primaryDisk == "" {
		return nil, errors.New("no fixed Windows disk found")
	}
	required["disk_used"] = required["disk_used_"+primaryDisk]
	performance := collector.performanceMetrics()
	return boundedMetrics(required, performance), nil
}

func (collector *windowsCollector) diskMetrics() (map[string]float64, string) {
	var disks []windowsLogicalDisk
	if collector.source.query("SELECT Name, Size, FreeSpace FROM Win32_LogicalDisk WHERE DriveType=3", &disks) != nil {
		return nil, ""
	}
	metrics := make(map[string]float64)
	primary := ""
	for _, disk := range disks {
		suffix := metricSuffix(disk.Name)
		if suffix == "" || disk.Size == 0 || disk.FreeSpace > disk.Size {
			continue
		}
		metrics["disk_used_"+suffix] = float64(disk.Size-disk.FreeSpace) * 100 / float64(disk.Size)
		metrics["disk_total_gb_"+suffix] = float64(disk.Size) / (1024 * 1024 * 1024)
		if primary == "" || suffix == "c" {
			primary = suffix
		}
	}
	return metrics, primary
}

func (collector *windowsCollector) performanceMetrics() map[string]float64 {
	metrics := make(map[string]float64)
	var disks []windowsDiskPerformance
	if collector.source.query("SELECT Name, DiskReadBytesPersec, DiskWriteBytesPersec FROM Win32_PerfFormattedData_PerfDisk_PhysicalDisk", &disks) == nil {
		for _, disk := range disks {
			if disk.Name == "_Total" {
				continue
			}
			suffix := metricSuffix(disk.Name)
			if suffix == "" {
				continue
			}
			metrics["disk_read_"+suffix] = float64(disk.DiskReadBytesPersec)
			metrics["disk_write_"+suffix] = float64(disk.DiskWriteBytesPersec)
		}
	}
	var networks []windowsNetworkPerformance
	if collector.source.query("SELECT Name, BytesReceivedPersec, BytesSentPersec FROM Win32_PerfFormattedData_Tcpip_NetworkInterface", &networks) == nil {
		for _, network := range networks {
			suffix := metricSuffix(network.Name)
			if suffix == "" {
				continue
			}
			metrics["net_in_"+suffix] = float64(network.BytesReceivedPersec)
			metrics["net_out_"+suffix] = float64(network.BytesSentPersec)
		}
	}
	return metrics
}

func normalizeWindowsServices(records []serviceRecord) []protocol.ServiceState {
	services := make([]protocol.ServiceState, 0, len(records))
	for _, record := range records {
		name := serviceName(record.Name)
		if name == "" {
			continue
		}
		status := "unknown"
		if record.State == "running" {
			status = "running"
		} else if record.State == "stopped" {
			status = "stopped"
		}
		services = append(services, protocol.ServiceState{
			Name: name, Status: status, LoadState: "scm", ActiveState: record.State, SubState: record.State,
		})
	}
	sort.Slice(services, func(left, right int) bool { return services[left].Name < services[right].Name })
	if len(services) > 500 {
		services = services[:500]
	}
	return services
}
