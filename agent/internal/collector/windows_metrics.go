//go:build windows

package collector

import (
	"strconv"
	"time"
)

type windowsProcessor struct {
	Name                 string
	PercentProcessorTime uint64
}

type windowsOperatingSystem struct {
	TotalVisibleMemorySize  uint64
	FreePhysicalMemory      uint64
	Caption                 string
	ServicePackMajorVersion uint16
	BuildNumber             string
	LastBootUpTime          time.Time
}

type windowsLogicalDisk struct {
	Name      string
	Size      uint64
	FreeSpace uint64
}

type windowsDiskPerformance struct {
	Name                 string
	DiskReadBytesPersec  uint64
	DiskWriteBytesPersec uint64
}

type windowsNetworkPerformance struct {
	Name                string
	BytesReceivedPersec uint64
	BytesSentPersec     uint64
}

func servicePack(major uint16) string {
	if major == 0 {
		return ""
	}
	return "SP" + strconv.FormatUint(uint64(major), 10)
}

func windowsUptime(boot time.Time) float64 {
	if boot.IsZero() {
		return 0
	}
	uptime := time.Since(boot).Seconds()
	if uptime < 0 {
		return 0
	}
	return uptime
}
