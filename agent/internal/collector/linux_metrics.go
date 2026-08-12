//go:build linux

package collector

import (
	"fmt"
	"math"
	"path/filepath"
	"sort"
	"strconv"
	"strings"
	"time"
)

type cpuCounters struct {
	total uint64
	idle  uint64
}

type ioCounters struct {
	read  uint64
	write uint64
}

type linuxCounters struct {
	at      time.Time
	cpu     cpuCounters
	disks   map[string]ioCounters
	network map[string]ioCounters
}

func (collector *linuxCollector) collectRates() (map[string]float64, error) {
	current, err := collector.readCounters()
	if err != nil {
		return nil, err
	}
	prior := collector.prior
	if prior == nil {
		prior = current
		collector.source.sleep(time.Second)
		current, err = collector.readCounters()
		if err != nil {
			return nil, err
		}
	}
	collector.prior = current

	duration := current.at.Sub(prior.at).Seconds()
	if duration <= 0 {
		duration = 1
	}
	metrics := map[string]float64{"cpu_load": cpuPercent(prior.cpu, current.cpu)}
	for _, device := range sortedCounterKeys(current.disks) {
		before, ok := prior.disks[device]
		if !ok {
			continue
		}
		after := current.disks[device]
		if after.read >= before.read {
			metrics["disk_read_"+metricSuffix(device)] = float64(after.read-before.read) / duration
		}
		if after.write >= before.write {
			metrics["disk_write_"+metricSuffix(device)] = float64(after.write-before.write) / duration
		}
	}
	for _, device := range sortedCounterKeys(current.network) {
		before, ok := prior.network[device]
		if !ok {
			continue
		}
		after := current.network[device]
		if after.read >= before.read {
			metrics["net_in_"+metricSuffix(device)] = float64(after.read-before.read) / duration
		}
		if after.write >= before.write {
			metrics["net_out_"+metricSuffix(device)] = float64(after.write-before.write) / duration
		}
	}
	return metrics, nil
}

func (collector *linuxCollector) readCounters() (*linuxCounters, error) {
	cpu, err := collector.readCPU()
	if err != nil {
		return nil, err
	}
	disks, err := collector.readDisks()
	if err != nil {
		return nil, err
	}
	network, err := collector.readNetwork()
	if err != nil {
		return nil, err
	}
	return &linuxCounters{at: collector.source.now(), cpu: cpu, disks: disks, network: network}, nil
}

func (collector *linuxCollector) readCPU() (cpuCounters, error) {
	contents, err := collector.source.readFile("/proc/stat")
	if err != nil {
		return cpuCounters{}, fmt.Errorf("read /proc/stat: %w", err)
	}
	for _, line := range strings.Split(string(contents), "\n") {
		fields := strings.Fields(line)
		if len(fields) < 5 || fields[0] != "cpu" {
			continue
		}
		var counters cpuCounters
		for index, value := range fields[1:] {
			parsed, err := strconv.ParseUint(value, 10, 64)
			if err != nil {
				return cpuCounters{}, fmt.Errorf("parse CPU counter: %w", err)
			}
			counters.total += parsed
			if index == 3 || index == 4 {
				counters.idle += parsed
			}
		}
		return counters, nil
	}
	return cpuCounters{}, fmt.Errorf("cpu aggregate missing from /proc/stat")
}

func (collector *linuxCollector) readDisks() (map[string]ioCounters, error) {
	contents, err := collector.source.readFile("/proc/diskstats")
	if err != nil {
		return nil, fmt.Errorf("read /proc/diskstats: %w", err)
	}
	values := make(map[string]ioCounters)
	for _, line := range strings.Split(string(contents), "\n") {
		fields := strings.Fields(line)
		if len(fields) < 10 || skipDisk(fields[2]) {
			continue
		}
		read, readErr := strconv.ParseUint(fields[5], 10, 64)
		write, writeErr := strconv.ParseUint(fields[9], 10, 64)
		if readErr != nil || writeErr != nil {
			continue
		}
		values[fields[2]] = ioCounters{read: read * 512, write: write * 512}
	}
	return values, nil
}

func (collector *linuxCollector) readNetwork() (map[string]ioCounters, error) {
	contents, err := collector.source.readFile("/proc/net/dev")
	if err != nil {
		return nil, fmt.Errorf("read /proc/net/dev: %w", err)
	}
	values := make(map[string]ioCounters)
	for _, line := range strings.Split(string(contents), "\n") {
		parts := strings.SplitN(line, ":", 2)
		if len(parts) != 2 {
			continue
		}
		name := strings.TrimSpace(parts[0])
		fields := strings.Fields(parts[1])
		if len(fields) < 9 || skipNetwork(name) {
			continue
		}
		read, readErr := strconv.ParseUint(fields[0], 10, 64)
		write, writeErr := strconv.ParseUint(fields[8], 10, 64)
		if readErr != nil || writeErr != nil {
			continue
		}
		values[name] = ioCounters{read: read, write: write}
	}
	return values, nil
}

func (collector *linuxCollector) collectMemory() (map[string]float64, error) {
	contents, err := collector.source.readFile("/proc/meminfo")
	if err != nil {
		return nil, fmt.Errorf("read /proc/meminfo: %w", err)
	}
	values := make(map[string]uint64)
	for _, line := range strings.Split(string(contents), "\n") {
		fields := strings.Fields(line)
		if len(fields) < 2 {
			continue
		}
		value, err := strconv.ParseUint(fields[1], 10, 64)
		if err == nil {
			values[strings.TrimSuffix(fields[0], ":")] = value * 1024
		}
	}
	total := values["MemTotal"]
	available := values["MemAvailable"]
	if available == 0 {
		available = values["MemFree"] + values["Buffers"] + values["Cached"]
	}
	if total == 0 || available > total {
		return nil, fmt.Errorf("invalid /proc/meminfo")
	}
	return map[string]float64{
		"ram_used":     float64(total-available) * 100 / float64(total),
		"ram_total_gb": float64(total) / (1024 * 1024 * 1024),
	}, nil
}

func (collector *linuxCollector) collectUptime() (float64, error) {
	contents, err := collector.source.readFile("/proc/uptime")
	if err != nil {
		return 0, fmt.Errorf("read /proc/uptime: %w", err)
	}
	fields := strings.Fields(string(contents))
	if len(fields) == 0 {
		return 0, fmt.Errorf("invalid /proc/uptime")
	}
	uptime, err := strconv.ParseFloat(fields[0], 64)
	if err != nil || uptime < 0 {
		return 0, fmt.Errorf("invalid /proc/uptime")
	}
	return uptime, nil
}

func (collector *linuxCollector) collectDiskUsage() (map[string]float64, error) {
	mounts, err := collector.mountPoints()
	if err != nil {
		return nil, err
	}
	metrics := make(map[string]float64)
	for _, mount := range mounts {
		statistics, err := collector.source.statFS(mount)
		if err != nil || statistics.Blocks == 0 || statistics.BlockSize == 0 || statistics.BlocksAvailable > statistics.Blocks {
			if mount == "/" {
				return nil, fmt.Errorf("read root filesystem usage: %w", err)
			}
			continue
		}
		suffix := metricSuffix(strings.Trim(mount, "/"))
		if suffix == "" {
			suffix = "root"
		}
		used := float64(statistics.Blocks-statistics.BlocksAvailable) * 100 / float64(statistics.Blocks)
		metrics["disk_used_"+suffix] = used
		metrics["disk_total_gb_"+suffix] = float64(statistics.Blocks*statistics.BlockSize) / (1024 * 1024 * 1024)
		if mount == "/" {
			metrics["disk_used"] = used
		}
	}
	if _, ok := metrics["disk_used_root"]; !ok {
		return nil, fmt.Errorf("root filesystem is missing")
	}
	return metrics, nil
}

func (collector *linuxCollector) mountPoints() ([]string, error) {
	contents, err := collector.source.readFile("/proc/self/mountinfo")
	if err != nil {
		return nil, fmt.Errorf("read mountinfo: %w", err)
	}
	seen := make(map[string]bool)
	mounts := make([]string, 0)
	for _, line := range strings.Split(string(contents), "\n") {
		fields := strings.Fields(line)
		if len(fields) < 7 {
			continue
		}
		mount := unescapeMount(fields[4])
		if mount == "" || seen[mount] || skipMount(mount) {
			continue
		}
		seen[mount] = true
		mounts = append(mounts, mount)
	}
	sort.Strings(mounts)
	return mounts, nil
}

func (collector *linuxCollector) collectTemperature() (float64, bool) {
	paths := []string{"/sys/class/thermal", "/sys/class/hwmon"}
	values := make([]float64, 0)
	for _, path := range paths {
		entries, err := collector.source.readDir(path)
		if err != nil {
			continue
		}
		for _, entry := range entries {
			name := "temp"
			if strings.HasPrefix(entry.Name(), "thermal_zone") {
				name = "temp"
			} else if strings.HasPrefix(entry.Name(), "hwmon") {
				name = "temp1_input"
			} else {
				continue
			}
			contents, err := collector.source.readFile(filepath.Join(path, entry.Name(), name))
			if err != nil {
				continue
			}
			value, err := strconv.ParseFloat(strings.TrimSpace(string(contents)), 64)
			if err != nil {
				continue
			}
			if math.Abs(value) > 1000 {
				value /= 1000
			}
			if value >= -100 && value <= 200 {
				values = append(values, value)
			}
		}
	}
	if len(values) == 0 {
		return 0, false
	}
	var total float64
	for _, value := range values {
		total += value
	}
	return total / float64(len(values)), true
}

func cpuPercent(before, after cpuCounters) float64 {
	if after.total <= before.total || after.idle < before.idle {
		return 0
	}
	total := after.total - before.total
	idle := after.idle - before.idle
	return float64(total-idle) * 100 / float64(total)
}

func sortedCounterKeys(values map[string]ioCounters) []string {
	keys := make([]string, 0, len(values))
	for key := range values {
		keys = append(keys, key)
	}
	sort.Strings(keys)
	return keys
}

func skipDisk(name string) bool {
	return strings.HasPrefix(name, "loop") || strings.HasPrefix(name, "ram") || strings.HasPrefix(name, "fd")
}

func skipNetwork(name string) bool {
	return name == "lo" || strings.HasPrefix(name, "docker") || strings.HasPrefix(name, "veth") || strings.HasPrefix(name, "br-")
}

func skipMount(mount string) bool {
	return mount != "/" && (strings.HasPrefix(mount, "/proc") || strings.HasPrefix(mount, "/sys") || strings.HasPrefix(mount, "/dev"))
}

func unescapeMount(value string) string {
	replacer := strings.NewReplacer("\\040", " ", "\\011", "\t", "\\012", "\n", "\\134", "\\")
	return replacer.Replace(value)
}
