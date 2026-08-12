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
	"github.com/mirivlad/mirvmon/agent/internal/health"
	"github.com/mirivlad/mirvmon/agent/internal/protocol"
	"github.com/mirivlad/mirvmon/agent/internal/transport"
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
}

// HostCollector is deliberately equivalent to collector.Collector, allowing
// tests to inject deterministic measurements without package-global state.
type HostCollector interface {
	Collect(context.Context, bool) (protocol.Measurement, error)
}

// Dependencies are the explicit runtime boundaries.
type Dependencies struct {
	Queue     Queue
	API       API
	Collector HostCollector
	Config    config.Config
	Version   string
	Commit    string
	Now       func() time.Time
	SampleID  func() (string, error)
}

// Runner owns one native agent instance.
type Runner struct {
	queue          Queue
	api            API
	collector      HostCollector
	config         config.Config
	version        string
	commit         string
	now            func() time.Time
	sampleID       func() (string, error)
	health         health.Store
	startedAt      time.Time
	lastConfigPull time.Time
	authPaused     bool
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
		queue:     dependencies.Queue,
		api:       dependencies.API,
		collector: dependencies.Collector,
		config:    dependencies.Config,
		version:   dependencies.Version,
		commit:    dependencies.Commit,
		now:       dependencies.Now,
		sampleID:  dependencies.SampleID,
		health:    health.New(dependencies.Config.QueuePath),
		startedAt: startedAt,
	}
	if err := runner.health.Clear(); err != nil {
		return nil, err
	}
	return runner, nil
}

// Cycle refreshes configuration if due, then collects and flushes one oldest
// queue item. Authentication failures pause fresh collection.
func (runner *Runner) Cycle(context context.Context) error {
	if runner.configDue() || runner.authPaused {
		if err := runner.refreshConfig(context); err != nil {
			runner.writeHealth("authentication_error", err, false, false)
			if errors.Is(err, transport.ErrAuthentication) {
				runner.authPaused = true
				return ErrAuthentication
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
		delay := time.Duration(runner.config.IntervalSeconds) * time.Second
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
	measurement, err := runner.collector.Collect(context, runner.config.CollectProcessCommands)
	if err != nil {
		runner.writeHealth("collection_error", err, false, false)
		return err
	}
	sampleID, err := runner.sampleID()
	if err != nil {
		return err
	}
	envelope, err := protocol.NewEnvelope(runner.config.Token, runner.version, measurement, runner.now(), sampleID)
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
	runner.writeHealth("queued", nil, true, false)
	return runner.flushOne(context)
}

func (runner *Runner) flushOne(context context.Context) error {
	raw := runner.queue.Peek()
	if raw == nil {
		return nil
	}
	outcome, err := runner.api.Send(context, raw)
	if err != nil {
		runner.writeHealth("retrying", err, false, false)
		return ErrDeliveryPending
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
		runner.writeHealth("authentication_error", ErrAuthentication, false, false)
		return ErrAuthentication
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
	runner.lastConfigPull = runner.now().UTC()
	runner.authPaused = false
	return nil
}

func (runner *Runner) configDue() bool {
	return runner.lastConfigPull.IsZero() || runner.now().UTC().Sub(runner.lastConfigPull) >= time.Minute
}

func (runner *Runner) writeHealth(state string, err error, collected bool, delivered bool) {
	status := health.Status{
		AgentVersion: runner.version,
		Commit:       runner.commit,
		StartedAt:    runner.startedAt,
		State:        state,
	}
	if collected {
		status.LastCollectionAt = runner.now().UTC()
	}
	if delivered {
		status.LastDeliveryAt = runner.now().UTC()
	}
	if err != nil {
		status.LastError = err.Error()
	}
	_ = runner.health.Write(status)
}

func jsonMarshal(envelope protocol.Envelope) ([]byte, error) {
	contents, err := json.Marshal(envelope)
	if err != nil {
		return nil, fmt.Errorf("encode metrics envelope: %w", err)
	}
	return contents, nil
}
