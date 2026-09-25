// Package runner coordinates configuration refresh, collection, durable
// enqueueing, delivery, and installer-visible health state.
package runner

import (
	"context"
	"encoding/json"
	"errors"
	"fmt"
	"time"

	"github.com/mirivlad/mirvmon/agent/internal/config"
	"github.com/mirivlad/mirvmon/agent/internal/diagnostic"
	"github.com/mirivlad/mirvmon/agent/internal/health"
	"github.com/mirivlad/mirvmon/agent/internal/protocol"
	"github.com/mirivlad/mirvmon/agent/internal/transport"
	"github.com/mirivlad/mirvmon/agent/internal/update"
)

var (
	ErrDeliveryPending = errors.New("delivery pending")
	ErrAuthentication  = errors.New("authentication failed")
	ErrDisabled        = errors.New("agent disabled")
)

// Queue is the runner's minimal durable queue boundary.
type Queue interface {
	Enqueue([]byte) error
	Peek() []byte
	Accept() error
	Reject(string) error
	Len() int
}

// API is the runner's transport boundary.
type API interface {
	Send(context.Context, []byte) (transport.Outcome, error)
	PullConfig(context.Context) (config.Remote, error)
	ReportUpdate(context.Context, update.Command, string, string) error
}

type UpdateManager interface {
	Process(context.Context, update.Command, update.Reporter) error
}

// HostCollector is deliberately equivalent to collector.Collector, allowing
// tests to inject deterministic measurements without package-global state.
type HostCollector interface {
	Collect(context.Context, bool) (protocol.Measurement, error)
}

type ProbeExecutor interface {
	Execute(context.Context, []config.ProbeJob) []protocol.ProbeResult
}

// Dependencies are the explicit runtime boundaries.
type Dependencies struct {
	Queue     Queue
	API       API
	Collector HostCollector
	Probes    ProbeExecutor
	Config    config.Config
	Version   string
	Commit    string
	Artifact  string
	Now       func() time.Time
	SampleID  func() (string, error)
	Updater   UpdateManager
}

// Runner owns one native agent instance.
type Runner struct {
	queue              Queue
	api                API
	collector          HostCollector
	probes             ProbeExecutor
	config             config.Config
	version            string
	commit             string
	artifact           string
	now                func() time.Time
	sampleID           func() (string, error)
	updater            UpdateManager
	health             health.Store
	status             health.Status
	startedAt          time.Time
	lastConfigPull     time.Time
	lastHostCollection time.Time
	lastProbeRuns      map[string]time.Time
	osVersion          string
	authPaused         bool
}

// New validates all dependencies and starts with a fresh health state.
func New(dependencies Dependencies) (*Runner, error) {
	if dependencies.Queue == nil || dependencies.API == nil || dependencies.Collector == nil || dependencies.Now == nil || dependencies.SampleID == nil {
		return nil, errors.New("runner dependencies are required")
	}
	if err := dependencies.Config.Validate(); err != nil {
		return nil, err
	}
	startedAt := dependencies.Now().UTC()
	runner := &Runner{
		queue:         dependencies.Queue,
		api:           dependencies.API,
		collector:     dependencies.Collector,
		probes:        dependencies.Probes,
		config:        dependencies.Config,
		version:       dependencies.Version,
		commit:        dependencies.Commit,
		artifact:      dependencies.Artifact,
		now:           dependencies.Now,
		sampleID:      dependencies.SampleID,
		updater:       dependencies.Updater,
		health:        health.New(dependencies.Config.QueuePath),
		startedAt:     startedAt,
		lastProbeRuns: make(map[string]time.Time),
		status: health.Status{
			AgentVersion: dependencies.Version,
			Commit:       dependencies.Commit,
			StartedAt:    startedAt,
		},
	}
	if err := runner.health.Clear(); err != nil {
		return nil, err
	}
	return runner, nil
}

// Cycle refreshes configuration if due, then collects and flushes one oldest
// queue item. Authentication failures pause fresh collection.
func (runner *Runner) Cycle(context context.Context) error {
	if runner.configDue() {
		runner.lastConfigPull = runner.now().UTC()
		if err := runner.refreshConfig(context); err != nil {
			runner.writeHealth(diagnostic.Classify(err), err, false, false)
			if errors.Is(err, transport.ErrAuthentication) {
				runner.authPaused = true
				return fmt.Errorf("%w: %w", ErrAuthentication, err)
			}
			return err
		}
	}
	if !runner.config.Enabled {
		runner.writeHealth("disabled", nil, false, false)
		return ErrDisabled
	}
	if runner.authPaused {
		return ErrAuthentication
	}

	if runner.queue.Len() > 0 {
		return runner.flushOne(context)
	}
	return runner.collectAndFlush(context)
}

// Once performs one cycle and optionally requires queue delivery to finish.
func (runner *Runner) Once(context context.Context, requireDelivery bool) error {
	err := runner.Cycle(context)
	if err != nil && !errors.Is(err, ErrDeliveryPending) {
		return err
	}
	if requireDelivery && runner.queue.Len() > 0 {
		return ErrDeliveryPending
	}
	return err
}

// Run loops until context cancellation. It never adds a listening socket.
func (runner *Runner) Run(context context.Context) error {
	for attempt := 0; ; attempt++ {
		err := runner.Cycle(context)
		if context.Err() != nil {
			return context.Err()
		}
		if errors.Is(err, update.ErrRestartRequired) {
			return err
		}
		delay := runner.loopDelay()
		if err != nil && !errors.Is(err, ErrDisabled) {
			delay = transport.RetryDelay(attempt)
		} else {
			attempt = 0
		}
		select {
		case <-context.Done():
			return context.Err()
		case <-time.After(delay):
		}
	}
}

func (runner *Runner) collectAndFlush(context context.Context) error {
	now := runner.now().UTC()
	hostDue := runner.lastHostCollection.IsZero() ||
		now.Sub(runner.lastHostCollection) >= time.Duration(runner.config.IntervalSeconds)*time.Second
	dueJobs := runner.dueProbeJobs(now)
	if !hostDue && len(dueJobs) == 0 {
		return nil
	}

	measurement := protocol.Measurement{
		OSVersion: runner.osVersion,
		Metrics:   map[string]float64{},
	}
	if hostDue {
		collected, err := runner.collector.Collect(context, runner.config.CollectProcessCommands)
		if err != nil {
			runner.writeHealth("collection_error", err, false, false)
			return err
		}
		measurement = collected
		runner.osVersion = collected.OSVersion
	}
	if runner.probes != nil && len(dueJobs) > 0 {
		measurement.ProbeResults = runner.probes.Execute(context, dueJobs)
	}
	if !hostDue && len(measurement.ProbeResults) == 0 {
		return nil
	}

	sampleID, err := runner.sampleID()
	if err != nil {
		return err
	}
	capabilities := []string{"self_update_v1"}
	if runner.probes != nil {
		capabilities = append(capabilities, "website_probe_v1")
	}
	envelope, err := protocol.NewEnvelope(
		runner.config.Token,
		runner.version,
		runner.artifact,
		capabilities,
		measurement,
		now,
		sampleID,
	)
	if err != nil {
		return err
	}
	raw, err := jsonMarshal(envelope)
	if err != nil {
		return err
	}
	if err := runner.queue.Enqueue(raw); err != nil {
		return err
	}
	if hostDue {
		runner.lastHostCollection = now
	}
	for _, job := range dueJobs {
		runner.lastProbeRuns[job.ID] = now
	}
	runner.writeHealth("queued", nil, hostDue, false)
	return runner.flushOne(context)
}

func (runner *Runner) dueProbeJobs(now time.Time) []config.ProbeJob {
	if runner.probes == nil {
		return nil
	}
	jobs := make([]config.ProbeJob, 0, len(runner.config.ProbeJobs))
	for _, job := range runner.config.ProbeJobs {
		last, exists := runner.lastProbeRuns[job.ID]
		if !exists || now.Sub(last) >= time.Duration(job.IntervalSeconds)*time.Second {
			jobs = append(jobs, job)
		}
	}
	return jobs
}

func (runner *Runner) loopDelay() time.Duration {
	seconds := runner.config.IntervalSeconds
	for _, job := range runner.config.ProbeJobs {
		if job.IntervalSeconds < seconds {
			seconds = job.IntervalSeconds
		}
	}
	if seconds < 1 {
		seconds = 1
	}
	return time.Duration(seconds) * time.Second
}

func (runner *Runner) flushOne(context context.Context) error {
	raw := runner.queue.Peek()
	if raw == nil {
		return nil
	}
	outcome, err := runner.api.Send(context, raw)
	if err != nil {
		runner.writeHealth(diagnostic.Classify(err), err, false, false)
		return fmt.Errorf("%w: %w", ErrDeliveryPending, err)
	}
	switch outcome {
	case transport.Accepted:
		if err := runner.queue.Accept(); err != nil {
			return err
		}
		runner.writeHealth("accepted", nil, false, true)
		return nil
	case transport.Permanent:
		if err := runner.queue.Reject("http_permanent"); err != nil {
			return err
		}
		runner.writeHealth("rejected", nil, false, false)
		return nil
	case transport.Authentication:
		runner.authPaused = true
		runner.writeHealth(diagnostic.AuthenticationError, transport.ErrAuthentication, false, false)
		return fmt.Errorf("%w: %w", ErrAuthentication, transport.ErrAuthentication)
	default:
		runner.writeHealth("retrying", nil, false, false)
		return ErrDeliveryPending
	}
}

func (runner *Runner) refreshConfig(context context.Context) error {
	remote, err := runner.api.PullConfig(context)
	if err != nil {
		return err
	}
	updated, ok := config.ApplyRemote(runner.config, remote)
	if !ok {
		return transport.ErrInvalidRemoteConfig
	}
	runner.config = updated
	runner.authPaused = false
	if remote.UpdateCommand != nil {
		if runner.updater == nil {
			return transport.ErrInvalidRemoteConfig
		}
		if err := runner.updater.Process(context, *remote.UpdateCommand, runner.api.ReportUpdate); err != nil {
			return err
		}
	}
	return nil
}

func (runner *Runner) configDue() bool {
	return runner.lastConfigPull.IsZero() || runner.now().UTC().Sub(runner.lastConfigPull) >= time.Minute
}

func (runner *Runner) writeHealth(state string, err error, collected bool, delivered bool) {
	runner.status.State = state
	if collected {
		runner.status.LastCollectionAt = runner.now().UTC()
	}
	if delivered {
		runner.status.LastDeliveryAt = runner.now().UTC()
	}
	if err != nil {
		runner.status.LastError = err.Error()
	} else {
		runner.status.LastError = ""
	}
	_ = runner.health.Write(runner.status)
}

func jsonMarshal(envelope protocol.Envelope) ([]byte, error) {
	contents, err := json.Marshal(envelope)
	if err != nil {
		return nil, fmt.Errorf("encode metrics envelope: %w", err)
	}
	return contents, nil
}
