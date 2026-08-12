//go:build linux

package collector

import (
	"context"
	"errors"
	"os"
	"path/filepath"
	"testing"
	"time"
)

func TestLinuxCollectorCollectsFixtureHostState(t *testing.T) {
	root := t.TempDir()
	writeLinuxFixture(t, root)
	stage := 0
	base := time.Date(2026, 8, 12, 12, 0, 0, 0, time.UTC)
	source := linuxSource{
		root: root,
		readFile: func(path string) ([]byte, error) {
			switch path {
			case "/proc/stat":
				if stage == 0 {
					return []byte("cpu  100 0 100 700 0 0 0 0 0 0\n"), nil
				}
				return []byte("cpu  120 0 120 760 0 0 0 0 0 0\n"), nil
			case "/proc/diskstats":
				if stage == 0 {
					return []byte("8 0 sda 0 0 100 0 0 0 200 0\n"), nil
				}
				return []byte("8 0 sda 0 0 110 0 0 0 220 0\n"), nil
			case "/proc/net/dev":
				if stage == 0 {
					return []byte("Inter-|   Receive                                                |  Transmit\n face |bytes    packets errs drop fifo frame compressed multicast|bytes    packets errs drop fifo colls carrier compressed\n eth0: 1000 0 0 0 0 0 0 0 2000 0 0 0 0 0 0 0\n"), nil
				}
				return []byte("Inter-|   Receive                                                |  Transmit\n face |bytes    packets errs drop fifo frame compressed multicast|bytes    packets errs drop fifo colls carrier compressed\n eth0: 1100 0 0 0 0 0 0 0 2200 0 0 0 0 0 0 0\n"), nil
			default:
				return os.ReadFile(filepath.Join(root, path[1:]))
			}
		},
		readDir: func(path string) ([]os.DirEntry, error) {
			return os.ReadDir(filepath.Join(root, path[1:]))
		},
		statFS: func(path string) (filesystemStats, error) {
			if path != "/" {
				return filesystemStats{}, errors.New("unexpected mount")
			}
			return filesystemStats{Blocks: 1000, BlocksAvailable: 250, BlockSize: 4096}, nil
		},
		command: func(_ context.Context, name string, arguments ...string) (string, error) {
			if name == "service" {
				return " [ + ]  sshd\n", nil
			}
			return "", errors.New("unexpected command: " + name)
		},
		sleep: func(time.Duration) { stage = 1 },
		now: func() time.Time {
			if stage == 0 {
				return base
			}
			return base.Add(time.Second)
		},
	}

	collector := newLinuxCollector(source)
	measurement, err := collector.Collect(context.Background(), true)
	if err != nil {
		t.Fatal(err)
	}
	for _, name := range []string{
		"cpu_load", "ram_used", "ram_total_gb", "uptime", "disk_used",
		"disk_used_root", "disk_total_gb_root", "disk_read_sda", "disk_write_sda",
		"net_in_eth0", "net_out_eth0", "temperature",
	} {
		if _, ok := measurement.Metrics[name]; !ok {
			t.Errorf("missing %s from %#v", name, measurement.Metrics)
		}
	}
	if measurement.Metrics["cpu_load"] != 40 {
		t.Fatalf("cpu_load=%v, want 40", measurement.Metrics["cpu_load"])
	}
	if measurement.Metrics["disk_read_sda"] != 5120 || measurement.Metrics["disk_write_sda"] != 10240 {
		t.Fatalf("unexpected disk rates: %#v", measurement.Metrics)
	}
	if measurement.Metrics["net_in_eth0"] != 100 || measurement.Metrics["net_out_eth0"] != 200 {
		t.Fatalf("unexpected network rates: %#v", measurement.Metrics)
	}
	if measurement.OSVersion != "NethServer 7.9.2009" {
		t.Fatal(measurement.OSVersion)
	}
	if measurement.ProcessSnapshot == nil || len(measurement.ProcessSnapshot.TopCPU) > 20 || len(measurement.ProcessSnapshot.TopMemory) > 20 {
		t.Fatalf("unexpected process snapshot: %#v", measurement.ProcessSnapshot)
	}
	if len(measurement.Services) != 1 || measurement.Services[0].Name != "sshd" {
		t.Fatalf("unexpected services: %#v", measurement.Services)
	}
}

func TestLinuxProcessCPUUsesElapsedTicksInsteadOfLifetimeTicks(t *testing.T) {
	observed := time.Date(2026, 8, 12, 12, 0, 0, 0, time.UTC)
	collector := newLinuxCollector(linuxSource{})
	collector.priorProcesses[42] = linuxProcessUsage{
		ticks:     100,
		startTime: 123,
		observed:  observed,
	}

	usage := collector.processCPUPercent(linuxProcess{pid: 42, cpuTicks: 150, startTime: 123}, observed.Add(time.Second))
	if usage != 50 {
		t.Fatalf("usage=%v, want 50", usage)
	}
	if usage := collector.processCPUPercent(linuxProcess{pid: 42, cpuTicks: 10, startTime: 456}, observed.Add(time.Second)); usage != 0 {
		t.Fatalf("reused pid usage=%v, want 0", usage)
	}
}

func writeLinuxFixture(t *testing.T, root string) {
	t.Helper()
	files := map[string]string{
		"etc/os-release":                       "PRETTY_NAME=\"CentOS Linux 7\"\n",
		"etc/nethserver-release":               "NethServer 7.9.2009\n",
		"proc/meminfo":                         "MemTotal:       1048576 kB\nMemAvailable:    524288 kB\n",
		"proc/uptime":                          "1234.00 0.00\n",
		"proc/1/comm":                          "init\n",
		"proc/self/mountinfo":                  "36 25 0:32 / / rw,relatime - ext4 /dev/sda rw\n",
		"sys/class/thermal/thermal_zone0/temp": "42000\n",
		"proc/42/comm":                         "sshd\n",
		"proc/42/cmdline":                      "sshd\x00-D\x00",
		"proc/42/stat":                         "42 (sshd) S 1 1 1 0 0 0 0 0 0 0 11 9 0 0 0 0 0 0 0\n",
		"proc/42/status":                       "Name:\tsshd\nVmRSS:\t1024 kB\n",
	}
	for name, contents := range files {
		path := filepath.Join(root, name)
		if err := os.MkdirAll(filepath.Dir(path), 0755); err != nil {
			t.Fatal(err)
		}
		if err := os.WriteFile(path, []byte(contents), 0644); err != nil {
			t.Fatal(err)
		}
	}
}
