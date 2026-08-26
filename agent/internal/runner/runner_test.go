package runner

import (
	"context"
	"errors"
	"fmt"
	"net"
	"path/filepath"
	"strings"
	"testing"
	"time"

	"github.com/mirivlad/mirvmon/agent/internal/config"
	"github.com/mirivlad/mirvmon/agent/internal/diagnostic"
	"github.com/mirivlad/mirvmon/agent/internal/protocol"
	"github.com/mirivlad/mirvmon/agent/internal/transport"
	"github.com/mirivlad/mirvmon/agent/internal/update"
)

func TestOncePersistsBeforeDeliveryAndKeepsRetry(t *testing.T) {
	queue := newRecordingQueue()
	api := &fakeAPI{outcome: transport.Retry}
	runner := newTestRunner(t, queue, api)

	err := runner.Once(context.Background(), true)
	if !errors.Is(err, ErrDeliveryPending) {
		t.Fatalf("got %v", err)
	}
	if len(queue.events) < 2 || queue.events[0] != "enqueue" || queue.events[1] != "peek" {
		t.Fatalf("events=%v", queue.events)
	}
	if queue.Len() != 1 {
		t.Fatalf("queue len=%d", queue.Len())
	}
}

func TestAuthenticationFailurePausesCollectionWithoutDroppingQueue(t *testing.T) {
	api := &fakeAPI{outcome: transport.Authentication}
	queue := newRecordingQueue(envelopeBytes("queued"))
	runner := newTestRunner(t, queue, api)

	if err := runner.Cycle(context.Background()); !errors.Is(err, ErrAuthentication) {
		t.Fatal(err)
	}
	if runner.collector.(*recordingCollector).calls != 0 || queue.Len() != 1 {
		t.Fatal("authentication failure lost or added data")
	}
}

func TestCycleAppliesRemoteConfigurationBeforeCollecting(t *testing.T) {
	enabled := false
	interval := 30
	api := &fakeAPI{outcome: transport.Accepted, remote: config.Remote{Enabled: &enabled, IntervalSeconds: &interval}}
	runner := newTestRunner(t, newRecordingQueue(), api)

	if err := runner.Cycle(context.Background()); !errors.Is(err, ErrDisabled) {
		t.Fatalf("got %v", err)
	}
	if runner.collector.(*recordingCollector).calls != 0 || runner.config.IntervalSeconds != 30 || runner.config.Enabled {
		t.Fatalf("remote config was not applied: %#v, calls=%d", runner.config, runner.collector.(*recordingCollector).calls)
	}
}

func TestAuthenticationConfigurationPullIsNotRetriedBeforeOneMinute(t *testing.T) {
	api := &fakeAPI{pullErr: transport.ErrAuthentication}
	runner := newTestRunner(t, newRecordingQueue(), api)

	if err := runner.Cycle(context.Background()); !errors.Is(err, ErrAuthentication) {
		t.Fatalf("first cycle: %v", err)
	}
	if err := runner.Cycle(context.Background()); !errors.Is(err, ErrAuthentication) {
		t.Fatalf("second cycle: %v", err)
	}
	if api.pulls != 1 {
		t.Fatalf("configuration pulls = %d, want 1", api.pulls)
	}
}

func TestConfigPullTimeoutHasNetworkHealthState(t *testing.T) {
	api := &fakeAPI{pullErr: context.DeadlineExceeded}
	runner := newTestRunner(t, newRecordingQueue(), api)

	if err := runner.Cycle(context.Background()); !errors.Is(err, context.DeadlineExceeded) {
		t.Fatalf("got %v", err)
	}
	status, err := runner.health.Read()
	if err != nil {
		t.Fatal(err)
	}
	if status.State != diagnostic.NetworkTimeout {
		t.Fatalf("state=%q error=%q", status.State, status.LastError)
	}
}

func TestDeliveryDNSErrorHasDNSHealthState(t *testing.T) {
	api := &fakeAPI{sendErr: &net.DNSError{Err: "temporary failure", Name: "monitor.example"}}
	runner := newTestRunner(t, newRecordingQueue(envelopeBytes("queued")), api)

	if err := runner.Cycle(context.Background()); !errors.Is(err, ErrDeliveryPending) {
		t.Fatalf("got %v", err)
	}
	status, err := runner.health.Read()
	if err != nil {
		t.Fatal(err)
	}
	if status.State != diagnostic.DNSError {
		t.Fatalf("state=%q error=%q", status.State, status.LastError)
	}
}

func TestHealthRetainsCollectionTimeAfterAcceptedDelivery(t *testing.T) {
	runner := newTestRunner(t, newRecordingQueue(), &fakeAPI{outcome: transport.Accepted})
	if err := runner.Cycle(context.Background()); err != nil {
		t.Fatal(err)
	}
	status, err := runner.health.Read()
	if err != nil {
		t.Fatal(err)
	}
	if status.LastCollectionAt.IsZero() || status.LastDeliveryAt.IsZero() {
		t.Fatalf("health timestamps were not retained: %#v", status)
	}
}

type recordingQueue struct {
	events []string
	items  [][]byte
}

func newRecordingQueue(items ...[]byte) *recordingQueue {
	return &recordingQueue{items: items}
}

func (queue *recordingQueue) Enqueue(value []byte) error {
	queue.events = append(queue.events, "enqueue")
	queue.items = append(queue.items, append([]byte(nil), value...))
	return nil
}

func (queue *recordingQueue) Peek() []byte {
	queue.events = append(queue.events, "peek")
	if len(queue.items) == 0 {
		return nil
	}
	return append([]byte(nil), queue.items[0]...)
}

func (queue *recordingQueue) Accept() error {
	queue.events = append(queue.events, "accept")
	queue.items = queue.items[1:]
	return nil
}

func (queue *recordingQueue) Reject(string) error {
	queue.events = append(queue.events, "reject")
	queue.items = queue.items[1:]
	return nil
}

func (queue *recordingQueue) Len() int { return len(queue.items) }

type fakeAPI struct {
	outcome transport.Outcome
	remote  config.Remote
	pullErr error
	sendErr error
	pulls   int
}

func (api *fakeAPI) Send(context.Context, []byte) (transport.Outcome, error) {
	return api.outcome, api.sendErr
}

func (api *fakeAPI) PullConfig(context.Context) (config.Remote, error) {
	api.pulls++
	return api.remote, api.pullErr
}

func (api *fakeAPI) ReportUpdate(context.Context, update.Command, string, string) error {
	return nil
}

type recordingCollector struct {
	calls int
}

func (collector *recordingCollector) Collect(context.Context, bool) (protocol.Measurement, error) {
	collector.calls++
	return protocol.Measurement{
		OSVersion: "NethServer 7.9.2009",
		Metrics:   map[string]float64{"cpu_load": 1},
	}, nil
}

func newTestRunner(t *testing.T, queue *recordingQueue, api *fakeAPI) *Runner {
	t.Helper()
	collector := &recordingCollector{}
	runner, err := New(Dependencies{
		Queue:     queue,
		API:       api,
		Collector: collector,
		Config: config.Config{
			APIURL:          "https://monitor.example/api/v1/metrics",
			ConfigURL:       "https://monitor.example/api/v1/agent/config",
			Token:           strings.Repeat("a", 64),
			QueuePath:       filepath.Join(t.TempDir(), "queue.json"),
			IntervalSeconds: 60,
			VerifyTLS:       true,
			Enabled:         true,
			QueueLimit:      1000,
		},
		Version:  "1.2.0",
		Commit:   "0123456789abcdef",
		Artifact: "linux-amd64",
		Now:      func() time.Time { return time.Date(2026, 8, 12, 12, 0, 0, 0, time.UTC) },
		SampleID: func() (string, error) {
			return "018f47a2-8e4c-7d0a-8d8b-45de8fd746a1", nil
		},
	})
	if err != nil {
		t.Fatal(err)
	}
	runner.collector = collector
	return runner
}

func envelopeBytes(id string) []byte {
	return []byte(fmt.Sprintf(`{"version":2,"sample_id":%q,"sample_time":"2026-08-12T12:00:00Z","token":%q,"metrics":{"cpu_load":1}}`, id, strings.Repeat("a", 64)))
}
