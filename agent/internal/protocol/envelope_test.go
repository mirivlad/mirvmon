package protocol

import (
	"bytes"
	"encoding/json"
	"errors"
	"fmt"
	"math"
	"strings"
	"testing"
	"time"
)

const testToken = "aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa"

func TestNewEnvelopePreservesProtocolV2(t *testing.T) {
	measurement := Measurement{
		OSVersion: "NethServer 7.9.2009",
		Metrics:   map[string]float64{"cpu_load": 12.5, "uptime": 1234},
	}

	envelope, err := NewEnvelope(
		testToken,
		"1.2.0",
		measurement,
		time.Date(2026, 8, 12, 12, 0, 0, 0, time.UTC),
		"018f47a2-8e4c-7d0a-8d8b-45de8fd746a1",
	)
	if err != nil {
		t.Fatal(err)
	}
	if envelope.Version != 2 || envelope.OSVersion != "NethServer 7.9.2009" {
		t.Fatalf("unexpected envelope: %#v", envelope)
	}
	if envelope.SampleTime != "2026-08-12T12:00:00Z" {
		t.Fatalf("unexpected sample time: %q", envelope.SampleTime)
	}

	encoded, err := json.Marshal(envelope)
	if err != nil {
		t.Fatal(err)
	}
	for _, field := range [][]byte{
		[]byte(`"agent_version":"1.2.0"`),
		[]byte(`"os_version":"NethServer 7.9.2009"`),
		[]byte(`"sample_time":"2026-08-12T12:00:00Z"`),
	} {
		if !bytes.Contains(encoded, field) {
			t.Fatalf("missing field %s in %s", field, encoded)
		}
	}
}

func TestNewEnvelopeRejectsInvalidFieldsAndLimits(t *testing.T) {
	validID := "018f47a2-8e4c-7d0a-8d8b-45de8fd746a1"
	measurement := Measurement{
		OSVersion: "Windows 7\nsecret",
		Metrics:   map[string]float64{"cpu_load": 1},
	}
	_, err := NewEnvelope(testToken, "1.2.0", measurement, time.Now(), validID)
	if !errors.Is(err, ErrInvalidOSVersion) {
		t.Fatalf("got %v", err)
	}

	measurement.OSVersion = "Windows 7 SP1"
	for i := 0; i < 101; i++ {
		measurement.Metrics[fmt.Sprintf("metric_%d", i)] = float64(i)
	}
	_, err = NewEnvelope(testToken, "1.2.0", measurement, time.Now(), validID)
	if !errors.Is(err, ErrTooManyMetrics) {
		t.Fatalf("got %v", err)
	}

	_, err = NewEnvelope(testToken, "bad version!", Measurement{
		OSVersion: "Windows 7 SP1",
		Metrics:   map[string]float64{"cpu_load": 1},
	}, time.Now(), validID)
	if !errors.Is(err, ErrInvalidAgentVersion) {
		t.Fatalf("got %v", err)
	}

	_, err = NewEnvelope(testToken, "1.2.0", Measurement{
		OSVersion: "Windows 7 SP1",
		Metrics:   map[string]float64{"cpu_load": math.NaN()},
	}, time.Now(), validID)
	if !errors.Is(err, ErrInvalidMetricValue) {
		t.Fatalf("got %v", err)
	}
}

func TestEnvelopeMetadataAndTokenTransformsPreserveSampleIdentity(t *testing.T) {
	raw := []byte(`{"version":2,"sample_id":"018f47a2-8e4c-7d0a-8d8b-45de8fd746a1","sample_time":"2026-08-12T12:00:00Z","token":"old","metrics":{"cpu_load":1}}`)

	metadata, err := ParseMetadata(raw)
	if err != nil {
		t.Fatal(err)
	}
	if metadata.SampleID != "018f47a2-8e4c-7d0a-8d8b-45de8fd746a1" ||
		metadata.SampleTime != "2026-08-12T12:00:00Z" || !metadata.HasToken {
		t.Fatalf("unexpected metadata: %#v", metadata)
	}

	rewritten, err := RewriteToken(raw, "new")
	if err != nil {
		t.Fatal(err)
	}
	if !bytes.Contains(rewritten, []byte(`"token":"new"`)) ||
		!bytes.Contains(rewritten, []byte(`"sample_id":"018f47a2-8e4c-7d0a-8d8b-45de8fd746a1"`)) {
		t.Fatalf("rewritten envelope changed identity: %s", rewritten)
	}

	redacted, err := RedactToken(rewritten)
	if err != nil {
		t.Fatal(err)
	}
	if bytes.Contains(redacted, []byte(`"token":"new"`)) ||
		!bytes.Contains(redacted, []byte(`"token":"[redacted]"`)) {
		t.Fatalf("token was not redacted: %s", redacted)
	}
}

func TestNewSampleIDCreatesVersion4UUID(t *testing.T) {
	id, err := NewSampleID()
	if err != nil {
		t.Fatal(err)
	}
	parts := strings.Split(id, "-")
	if len(parts) != 5 || len(parts[2]) != 4 || parts[2][0] != '4' ||
		len(parts[3]) != 4 || !strings.ContainsRune("89ab", rune(parts[3][0])) {
		t.Fatalf("not a UUID v4: %q", id)
	}
}
