//go:build linux

package collector

import (
	"fmt"
	"strconv"
	"strings"
)

func (collector *linuxCollector) collectLoadAverage() (map[string]float64, error) {
	contents, err := collector.source.readFile("/proc/loadavg")
	if err != nil {
		return nil, fmt.Errorf("read /proc/loadavg: %w", err)
	}
	fields := strings.Fields(string(contents))
	if len(fields) < 3 {
		return nil, fmt.Errorf("invalid /proc/loadavg")
	}

	values := make([]float64, 3)
	for index := range values {
		value, parseErr := strconv.ParseFloat(fields[index], 64)
		if parseErr != nil || value < 0 {
			return nil, fmt.Errorf("invalid /proc/loadavg")
		}
		values[index] = value
	}

	return map[string]float64{
		"load_1":  values[0],
		"load_5":  values[1],
		"load_15": values[2],
	}, nil
}
