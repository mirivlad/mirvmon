//go:build windows

package collector

import (
	"context"
	"errors"
	"math"
	"testing"
)

func TestWindowsCollectorUsesWMIContractFixtures(t *testing.T) {
	source := windowsSource{
		logicalProcessors: 4,
		query: func(query string, destination any) error {
			switch result := destination.(type) {
			case *[]windowsProcessor:
				*result = []windowsProcessor{{Name: "_Total", PercentProcessorTime: 200}}
			case *[]windowsOperatingSystem:
				*result = []windowsOperatingSystem{{
					TotalVisibleMemorySize:  1048576,
					FreePhysicalMemory:      524288,
					Caption:                 "Windows Server 2008 R2 Enterprise",
					ServicePackMajorVersion: 1,
					BuildNumber:             "7601",
				}}
			case *[]windowsLogicalDisk:
				*result = []windowsLogicalDisk{{Name: "C:", Size: 1000, FreeSpace: 250}}
			case *[]windowsDiskPerformance:
				*result = []windowsDiskPerformance{{Name: "0 C:", DiskReadBytesPersec: 10, DiskWriteBytesPersec: 20}}
			case *[]windowsNetworkPerformance:
				*result = []windowsNetworkPerformance{{Name: "Intel Ethernet", BytesReceivedPersec: 30, BytesSentPersec: 40}}
			case *[]windowsProcessPerformance:
				*result = []windowsProcessPerformance{{IDProcess: 42, Name: "sshd", PercentProcessorTime: 8, WorkingSetPrivate: 2048}}
			case *[]windowsProcessCommand:
				*result = []windowsProcessCommand{{ProcessID: 42, CommandLine: "sshd --token secret"}}
			default:
				return errors.New("unexpected WMI destination")
			}
			return nil
		},
		services: func() ([]serviceRecord, error) {
			return []serviceRecord{{Name: "sshd", State: "running"}}, nil
		},
		registryOS: func() (windowsOperatingSystem, error) {
			return windowsOperatingSystem{}, errors.New("registry should not be needed")
		},
	}

	collector := newWindowsCollector(source)
	measurement, err := collector.Collect(context.Background(), true)
	if err != nil {
		t.Fatal(err)
	}
	for _, name := range []string{
		"cpu_load", "ram_used", "ram_total_gb", "disk_used", "disk_used_c",
		"disk_total_gb_c", "disk_read_0_c", "disk_write_0_c",
		"net_in_intel_ethernet", "net_out_intel_ethernet",
	} {
		value, ok := measurement.Metrics[name]
		if !ok || math.IsNaN(value) || math.IsInf(value, 0) {
			t.Errorf("missing or invalid %s: %#v", name, measurement.Metrics)
		}
	}
	if measurement.OSVersion != "Windows Server 2008 R2 Enterprise SP1 (build 7601)" {
		t.Fatal(measurement.OSVersion)
	}
	if len(measurement.Services) != 1 || measurement.Services[0].Status != "running" {
		t.Fatalf("unexpected services: %#v", measurement.Services)
	}
	if measurement.ProcessSnapshot == nil || len(measurement.ProcessSnapshot.TopCPU) != 1 ||
		measurement.ProcessSnapshot.TopCPU[0].Command != "sshd --token [REDACTED]" {
		t.Fatalf("unexpected processes: %#v", measurement.ProcessSnapshot)
	}
}
