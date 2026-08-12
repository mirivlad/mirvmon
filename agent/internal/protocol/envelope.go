// Package protocol defines the version 2 metrics envelope shared by every
// MirvMon native-agent build.
package protocol

import (
	"crypto/rand"
	"encoding/hex"
	"encoding/json"
	"errors"
	"fmt"
	"math"
	"regexp"
	"strings"
	"time"
	"unicode/utf8"
)

var (
	ErrInvalidToken        = errors.New("invalid token")
	ErrInvalidAgentVersion = errors.New("invalid agent version")
	ErrInvalidOSVersion    = errors.New("invalid OS version")
	ErrInvalidSampleID     = errors.New("invalid sample ID")
	ErrInvalidMetricName   = errors.New("invalid metric name")
	ErrInvalidMetricValue  = errors.New("invalid metric value")
	ErrTooManyMetrics      = errors.New("too many metrics")
	ErrInvalidServices     = errors.New("invalid services")
	ErrInvalidSnapshot     = errors.New("invalid process snapshot")
)

var (
	agentVersionPattern = regexp.MustCompile(`^[A-Za-z0-9][A-Za-z0-9._+-]{0,31}$`)
	metricNamePattern   = regexp.MustCompile(`^[a-z][a-z0-9_]{0,99}$`)
	serviceNamePattern  = regexp.MustCompile(`^[A-Za-z0-9_.@:-]{1,255}$`)
	sampleIDPattern     = regexp.MustCompile(`^[0-9a-f]{8}-[0-9a-f]{4}-[1-8][0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$`)
)

// ServiceState matches the strict server-side services object.
type ServiceState struct {
	Name        string `json:"name"`
	Status      string `json:"status"`
	LoadState   string `json:"load_state"`
	ActiveState string `json:"active_state"`
	SubState    string `json:"sub_state"`
}

// Process is one item in a top-process list.
type Process struct {
	PID     int     `json:"pid"`
	Name    string  `json:"name"`
	Command string  `json:"command"`
	Value   float64 `json:"value"`
}

// ProcessSnapshot contains the two bounded process rankings accepted by v2.
type ProcessSnapshot struct {
	TopCPU    []Process `json:"top_cpu"`
	TopMemory []Process `json:"top_memory"`
}

// Measurement is platform collector output before envelope identity is added.
type Measurement struct {
	OSVersion       string
	Metrics         map[string]float64
	Services        []ServiceState
	ProcessSnapshot *ProcessSnapshot
}

// Envelope is the JSON payload accepted by POST /api/v1/metrics.
type Envelope struct {
	Version         int                `json:"version"`
	AgentVersion    string             `json:"agent_version"`
	OSVersion       string             `json:"os_version"`
	SampleID        string             `json:"sample_id"`
	SampleTime      string             `json:"sample_time"`
	Token           string             `json:"token"`
	Metrics         map[string]float64 `json:"metrics"`
	Services        []ServiceState     `json:"services,omitempty"`
	ProcessSnapshot *ProcessSnapshot   `json:"process_snapshot,omitempty"`
}

// Metadata is the small stable identity subset used by queue migration.
type Metadata struct {
	SampleID   string
	SampleTime string
	HasToken   bool
}

// NewEnvelope validates collector output against the v2 server contract.
func NewEnvelope(token, agentVersion string, measurement Measurement, now time.Time, sampleID string) (Envelope, error) {
	if len(token) < 32 || len(token) > 512 {
		return Envelope{}, ErrInvalidToken
	}
	if !agentVersionPattern.MatchString(agentVersion) {
		return Envelope{}, ErrInvalidAgentVersion
	}
	if !validOSVersion(measurement.OSVersion) {
		return Envelope{}, ErrInvalidOSVersion
	}
	if !sampleIDPattern.MatchString(strings.ToLower(sampleID)) {
		return Envelope{}, ErrInvalidSampleID
	}
	if err := validateMetrics(measurement.Metrics); err != nil {
		return Envelope{}, err
	}
	if err := validateServices(measurement.Services); err != nil {
		return Envelope{}, err
	}
	if err := validateSnapshot(measurement.ProcessSnapshot); err != nil {
		return Envelope{}, err
	}

	return Envelope{
		Version:         2,
		AgentVersion:    agentVersion,
		OSVersion:       measurement.OSVersion,
		SampleID:        strings.ToLower(sampleID),
		SampleTime:      now.UTC().Format("2006-01-02T15:04:05Z"),
		Token:           token,
		Metrics:         measurement.Metrics,
		Services:        measurement.Services,
		ProcessSnapshot: measurement.ProcessSnapshot,
	}, nil
}

// NewSampleID returns a cryptographically random UUID version 4.
func NewSampleID() (string, error) {
	var bytes [16]byte
	if _, err := rand.Read(bytes[:]); err != nil {
		return "", fmt.Errorf("read random UUID bytes: %w", err)
	}
	bytes[6] = (bytes[6] & 0x0f) | 0x40
	bytes[8] = (bytes[8] & 0x3f) | 0x80

	encoded := hex.EncodeToString(bytes[:])
	return encoded[0:8] + "-" + encoded[8:12] + "-" + encoded[12:16] + "-" + encoded[16:20] + "-" + encoded[20:32], nil
}

// ParseMetadata reads only queue identity fields. It intentionally does not
// decode metrics or process snapshots during queue migration.
func ParseMetadata(raw []byte) (Metadata, error) {
	fields, err := decodeObject(raw)
	if err != nil {
		return Metadata{}, err
	}

	var metadata Metadata
	if err := json.Unmarshal(fields["sample_id"], &metadata.SampleID); err != nil || metadata.SampleID == "" {
		return Metadata{}, ErrInvalidSampleID
	}
	if err := json.Unmarshal(fields["sample_time"], &metadata.SampleTime); err != nil || metadata.SampleTime == "" {
		return Metadata{}, errors.New("invalid sample time")
	}
	var token string
	if value, ok := fields["token"]; ok && json.Unmarshal(value, &token) == nil && token != "" {
		metadata.HasToken = true
	}

	return metadata, nil
}

// RewriteToken keeps every field except token semantically unchanged.
func RewriteToken(raw []byte, token string) ([]byte, error) {
	fields, err := decodeObject(raw)
	if err != nil {
		return nil, err
	}
	encodedToken, err := json.Marshal(token)
	if err != nil {
		return nil, fmt.Errorf("encode token: %w", err)
	}
	fields["token"] = encodedToken
	return json.Marshal(fields)
}

// RedactToken returns an envelope suitable for bounded local quarantine.
func RedactToken(raw []byte) ([]byte, error) {
	return RewriteToken(raw, "[redacted]")
}

func validOSVersion(value string) bool {
	if value == "" || len(value) > 255 || !utf8.ValidString(value) {
		return false
	}
	for _, character := range value {
		if character <= 0x1f || character == 0x7f {
			return false
		}
	}
	return true
}

func validateMetrics(metrics map[string]float64) error {
	if len(metrics) == 0 {
		return ErrInvalidMetricValue
	}
	if len(metrics) > 100 {
		return ErrTooManyMetrics
	}
	for name, value := range metrics {
		if !metricNamePattern.MatchString(name) {
			return ErrInvalidMetricName
		}
		if math.IsNaN(value) || math.IsInf(value, 0) {
			return ErrInvalidMetricValue
		}
	}
	return nil
}

func validateServices(services []ServiceState) error {
	if len(services) > 500 {
		return ErrInvalidServices
	}
	for _, service := range services {
		if !serviceNamePattern.MatchString(service.Name) ||
			(service.Status != "running" && service.Status != "stopped" && service.Status != "unknown") ||
			len(service.LoadState) > 50 || len(service.ActiveState) > 50 || len(service.SubState) > 50 {
			return ErrInvalidServices
		}
	}
	return nil
}

func validateSnapshot(snapshot *ProcessSnapshot) error {
	if snapshot == nil {
		return nil
	}
	if err := validateProcesses(snapshot.TopCPU); err != nil {
		return err
	}
	if err := validateProcesses(snapshot.TopMemory); err != nil {
		return err
	}
	encoded, err := json.Marshal(snapshot)
	if err != nil || len(encoded) > 64*1024 {
		return ErrInvalidSnapshot
	}
	return nil
}

func validateProcesses(processes []Process) error {
	if len(processes) > 20 {
		return ErrInvalidSnapshot
	}
	for _, process := range processes {
		if process.PID < 1 || len(process.Name) > 255 || len(process.Command) > 512 ||
			math.IsNaN(process.Value) || math.IsInf(process.Value, 0) || process.Value < 0 {
			return ErrInvalidSnapshot
		}
	}
	return nil
}

func decodeObject(raw []byte) (map[string]json.RawMessage, error) {
	var fields map[string]json.RawMessage
	if err := json.Unmarshal(raw, &fields); err != nil || fields == nil {
		if err != nil {
			return nil, fmt.Errorf("decode envelope: %w", err)
		}
		return nil, errors.New("envelope must be an object")
	}
	return fields, nil
}
