// Package migrate imports Python and PowerShell agent state into the native
// agent format without changing sample identity or queue ordering.
package migrate

import (
	"bufio"
	"bytes"
	"encoding/json"
	"errors"
	"fmt"
	"os"
	"time"

	"github.com/mirivlad/mirvmon/agent/internal/atomicfile"
	"github.com/mirivlad/mirvmon/agent/internal/config"
	"github.com/mirivlad/mirvmon/agent/internal/protocol"
)

const sampleMaximumAge = 7 * 24 * time.Hour

// Request identifies every source and destination touched by one migration.
type Request struct {
	SourceConfig   string
	SourceQueue    string
	ServerConfig   string
	OutputConfig   string
	OutputQueue    string
	QuarantinePath string
	Now            time.Time
}

// Report makes migration outcomes auditable without logging sample tokens.
type Report struct {
	Imported   int
	Duplicates int
	Expired    int
	Invalid    int
}

type quarantineEntry struct {
	Reason   string          `json:"reason"`
	Envelope json.RawMessage `json:"envelope"`
}

// Import merges configuration and converts either supported old queue encoding.
func Import(request Request) (Report, error) {
	if request.ServerConfig == "" || request.OutputConfig == "" || request.OutputQueue == "" {
		return Report{}, errors.New("server and output paths are required")
	}
	serverConfig, _, err := config.Load(request.ServerConfig)
	if err != nil {
		return Report{}, fmt.Errorf("load server config: %w", err)
	}
	mergedConfig, sourceRaw, err := mergedConfiguration(request, serverConfig)
	if err != nil {
		return Report{}, err
	}
	items, err := readQueue(request.SourceQueue)
	if err != nil {
		return Report{}, err
	}
	converted, quarantined, report := convertQueue(items, serverConfig.Token, request.Now)
	if err := config.WriteAtomic(request.OutputConfig, mergedConfig, sourceRaw); err != nil {
		return Report{}, fmt.Errorf("write native config: %w", err)
	}
	encodedQueue, err := json.Marshal(converted)
	if err != nil {
		return Report{}, fmt.Errorf("encode native queue: %w", err)
	}
	if err := atomicfile.Write(request.OutputQueue, encodedQueue, 0600); err != nil {
		return Report{}, fmt.Errorf("write native queue: %w", err)
	}
	if len(quarantined) > 0 {
		if request.QuarantinePath == "" {
			return Report{}, errors.New("quarantine path is required for rejected samples")
		}
		encoded, err := json.Marshal(quarantined)
		if err != nil {
			return Report{}, fmt.Errorf("encode migration quarantine: %w", err)
		}
		if err := atomicfile.Write(request.QuarantinePath, encoded, 0600); err != nil {
			return Report{}, fmt.Errorf("write migration quarantine: %w", err)
		}
	}
	return report, nil
}

func mergedConfiguration(request Request, server config.Config) (config.Config, config.Raw, error) {
	if request.SourceConfig == "" {
		merged := server
		merged.QueuePath = request.OutputQueue
		return merged, config.Raw{}, merged.Validate()
	}
	source, raw, err := config.Load(request.SourceConfig)
	if errors.Is(err, os.ErrNotExist) {
		merged := server
		merged.QueuePath = request.OutputQueue
		return merged, config.Raw{}, merged.Validate()
	}
	if err != nil {
		return config.Config{}, nil, fmt.Errorf("load source config: %w", err)
	}
	source.APIURL = server.APIURL
	source.ConfigURL = server.ConfigURL
	source.Token = server.Token
	source.QueuePath = request.OutputQueue
	if err := source.Validate(); err != nil {
		return config.Config{}, nil, err
	}
	return source, raw, nil
}

func readQueue(path string) ([]json.RawMessage, error) {
	if path == "" {
		return nil, nil
	}
	contents, err := os.ReadFile(path)
	if errors.Is(err, os.ErrNotExist) {
		return nil, nil
	}
	if err != nil {
		return nil, fmt.Errorf("read source queue: %w", err)
	}
	var values []json.RawMessage
	if json.Unmarshal(contents, &values) == nil {
		return values, nil
	}
	values = nil
	scanner := bufio.NewScanner(bytes.NewReader(contents))
	scanner.Buffer(make([]byte, 1024), 128*1024)
	for scanner.Scan() {
		line := bytes.TrimSpace(scanner.Bytes())
		if len(line) != 0 {
			values = append(values, append(json.RawMessage(nil), line...))
		}
	}
	if err := scanner.Err(); err != nil {
		return nil, fmt.Errorf("read PowerShell queue: %w", err)
	}
	return values, nil
}

func convertQueue(items []json.RawMessage, token string, now time.Time) ([]json.RawMessage, []quarantineEntry, Report) {
	if now.IsZero() {
		now = time.Now().UTC()
	}
	seen := make(map[string]bool)
	converted := make([]json.RawMessage, 0, len(items))
	quarantined := make([]quarantineEntry, 0)
	report := Report{}
	for _, raw := range items {
		metadata, err := protocol.ParseMetadata(raw)
		if err != nil {
			report.Invalid++
			quarantined = append(quarantined, migrationQuarantine("invalid", raw))
			continue
		}
		if seen[metadata.SampleID] {
			report.Duplicates++
			continue
		}
		seen[metadata.SampleID] = true
		sampleTime, err := time.Parse(time.RFC3339, metadata.SampleTime)
		if err != nil || sampleTime.Before(now.Add(-sampleMaximumAge)) {
			report.Expired++
			quarantined = append(quarantined, migrationQuarantine("expired", raw))
			continue
		}
		updated, err := rewriteIfTokenDiffers(raw, token)
		if err != nil {
			report.Invalid++
			quarantined = append(quarantined, migrationQuarantine("invalid", raw))
			continue
		}
		converted = append(converted, updated)
		report.Imported++
	}
	return converted, quarantined, report
}

func rewriteIfTokenDiffers(raw []byte, token string) ([]byte, error) {
	var fields map[string]json.RawMessage
	if err := json.Unmarshal(raw, &fields); err != nil {
		return nil, err
	}
	var current string
	if err := json.Unmarshal(fields["token"], &current); err != nil || current == "" {
		return nil, errors.New("sample token is missing")
	}
	if current == token {
		return append([]byte(nil), raw...), nil
	}
	return protocol.RewriteToken(raw, token)
}

func migrationQuarantine(reason string, raw []byte) quarantineEntry {
	redacted, err := protocol.RedactToken(raw)
	if err != nil {
		redacted = []byte(`{"token":"[redacted]"}`)
	}
	return quarantineEntry{Reason: reason, Envelope: redacted}
}
