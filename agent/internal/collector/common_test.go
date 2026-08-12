package collector

import (
	"fmt"
	"testing"

	"github.com/mirivlad/mirvmon/agent/internal/protocol"
)

func TestServiceTrackerReportsInitialStateThenChanges(t *testing.T) {
	tracker := NewServiceTracker()
	first := []protocol.ServiceState{{Name: "sshd.service", Status: "running"}}
	if got := tracker.Changed(first); len(got) != 1 {
		t.Fatalf("first=%v", got)
	}
	if got := tracker.Changed(first); len(got) != 0 {
		t.Fatalf("unchanged=%v", got)
	}
	changed := []protocol.ServiceState{{Name: "sshd.service", Status: "stopped"}}
	if got := tracker.Changed(changed); len(got) != 1 {
		t.Fatalf("changed=%v", got)
	}
}

func TestBoundedMetricsRetainsRequiredValuesThenUsesSortedOptionalNames(t *testing.T) {
	required := map[string]float64{"cpu_load": 1, "uptime": 2}
	optional := make(map[string]float64)
	for index := 0; index < 101; index++ {
		optional[fmt.Sprintf("net_in_%03d", index)] = float64(index)
	}
	metrics := boundedMetrics(required, optional)
	if len(metrics) != 100 || metrics["cpu_load"] != 1 || metrics["uptime"] != 2 {
		t.Fatalf("unexpected bounded metrics: %#v", metrics)
	}
	if _, ok := metrics["net_in_097"]; !ok {
		t.Fatal("lexically earliest optional metric was dropped")
	}
	if _, ok := metrics["net_in_098"]; ok {
		t.Fatal("metric past the 100-entry limit was retained")
	}
}
