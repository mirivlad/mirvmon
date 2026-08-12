// Package collector contains the platform-specific host collectors and their
// shared v2 measurement contracts.
package collector

import (
	"context"
	"sort"
	"sync"

	"github.com/mirivlad/mirvmon/agent/internal/protocol"
)

// Collector obtains one bounded host measurement. The boolean controls whether
// process command lines may be included in the process snapshot.
type Collector interface {
	Collect(context.Context, bool) (protocol.Measurement, error)
}

// ServiceTracker emits every initial state and only subsequent state changes.
type ServiceTracker struct {
	mu       sync.Mutex
	previous map[string]protocol.ServiceState
}

// NewServiceTracker creates an empty tracker.
func NewServiceTracker() *ServiceTracker {
	return &ServiceTracker{previous: make(map[string]protocol.ServiceState)}
}

// Changed returns service states that are new or differ from the prior cycle.
// A previously visible service that disappears is reported as unknown once.
func (tracker *ServiceTracker) Changed(current []protocol.ServiceState) []protocol.ServiceState {
	tracker.mu.Lock()
	defer tracker.mu.Unlock()

	next := make(map[string]protocol.ServiceState, len(current))
	changed := make([]protocol.ServiceState, 0, len(current))
	for _, service := range current {
		next[service.Name] = service
		if prior, ok := tracker.previous[service.Name]; !ok || prior != service {
			changed = append(changed, service)
		}
	}
	for name, prior := range tracker.previous {
		if _, ok := next[name]; !ok {
			changed = append(changed, protocol.ServiceState{
				Name:        name,
				Status:      "unknown",
				LoadState:   prior.LoadState,
				ActiveState: "unknown",
				SubState:    "missing",
			})
		}
	}
	tracker.previous = next
	sort.Slice(changed, func(left, right int) bool {
		return changed[left].Name < changed[right].Name
	})
	return changed
}

func boundedMetrics(required map[string]float64, optional ...map[string]float64) map[string]float64 {
	metrics := make(map[string]float64, 100)
	for name, value := range required {
		metrics[name] = value
	}
	for _, values := range optional {
		keys := make([]string, 0, len(values))
		for name := range values {
			keys = append(keys, name)
		}
		sort.Strings(keys)
		for _, name := range keys {
			if _, exists := metrics[name]; exists || len(metrics) == 100 {
				continue
			}
			metrics[name] = values[name]
		}
	}
	return metrics
}
