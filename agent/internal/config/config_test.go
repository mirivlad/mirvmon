package config

import (
	"bytes"
	"os"
	"path/filepath"
	"reflect"
	"strings"
	"testing"
)

const validToken = "aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa"

func TestLoadAndApplyRemotePreservesUnknownValues(t *testing.T) {
	path := filepath.Join(t.TempDir(), "config.json")
	if err := os.WriteFile(path, []byte(`{
        "api_url":"https://monitor.example/api/v1/metrics",
        "config_url":"https://monitor.example/api/v1/agent/config",
        "token":"aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa",
        "queue_path":"/var/lib/mirvmon-agent/queue.json",
        "interval_seconds":60,
        "verify_tls":true,
        "collect_process_commands":false,
        "unknown_key":{"preserve":true}
    }`), 0600); err != nil {
		t.Fatal(err)
	}

	configuration, raw, err := Load(path)
	if err != nil {
		t.Fatal(err)
	}
	enabled := false
	interval := 30
	updated, ok := ApplyRemote(configuration, Remote{
		Enabled:         &enabled,
		IntervalSeconds: &interval,
	})
	if !ok || updated.Enabled || updated.IntervalSeconds != 30 {
		t.Fatalf("unexpected updated config: %#v", updated)
	}
	if string(raw["unknown_key"]) != `{"preserve":true}` {
		t.Fatalf("unknown key lost: %s", raw["unknown_key"])
	}
}

func TestLoadExpandsWindowsStyleEnvironmentVariablesInQueuePath(t *testing.T) {
	t.Setenv("PROGRAMDATA", `C:\ProgramData`)
	path := filepath.Join(t.TempDir(), "config.json")
	if err := os.WriteFile(path, []byte(`{
        "api_url":"https://monitor.example/api/v1/metrics",
        "config_url":"https://monitor.example/api/v1/agent/config",
        "token":"aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa",
        "queue_path":"%PROGRAMDATA%\\MirvMon\\Agent\\queue.json"
    }`), 0600); err != nil {
		t.Fatal(err)
	}

	configuration, _, err := Load(path)
	if err != nil {
		t.Fatal(err)
	}
	if got, want := configuration.QueuePath, `C:\ProgramData\MirvMon\Agent\queue.json`; got != want {
		t.Fatalf("QueuePath = %q, want %q", got, want)
	}
}

func TestLoadAcceptsLegacyNullMonitorServicesAndWriteAtomicNormalizesIt(t *testing.T) {
	directory := t.TempDir()
	path := filepath.Join(directory, "config.json")
	if err := os.WriteFile(path, []byte(`{
        "api_url":"https://monitor.example/api/v1/metrics",
        "config_url":"https://monitor.example/api/v1/agent/config",
        "token":"aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa",
        "queue_path":"/var/lib/mirvmon-agent/queue.json",
        "monitor_services":null
    }`), 0600); err != nil {
		t.Fatal(err)
	}

	configuration, raw, err := Load(path)
	if err != nil {
		t.Fatal(err)
	}
	if configuration.MonitorServices != nil {
		t.Fatalf("MonitorServices = %#v, want nil", configuration.MonitorServices)
	}
	if err := WriteAtomic(path, configuration, raw); err != nil {
		t.Fatal(err)
	}
	contents, err := os.ReadFile(path)
	if err != nil {
		t.Fatal(err)
	}
	if !bytes.Contains(contents, []byte(`"monitor_services":[]`)) {
		t.Fatalf("empty services were not normalized: %s", contents)
	}
}

func TestLoadRejectsUnsafeEndpointAndWriteAtomicKeepsUnknownValues(t *testing.T) {
	directory := t.TempDir()
	unsafePath := filepath.Join(directory, "unsafe.json")
	if err := os.WriteFile(unsafePath, []byte(`{
        "api_url":"http://monitor.example/api/v1/metrics",
        "config_url":"https://monitor.example/api/v1/agent/config",
        "token":"aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa",
        "queue_path":"/var/lib/mirvmon-agent/queue.json"
    }`), 0600); err != nil {
		t.Fatal(err)
	}
	if _, _, err := Load(unsafePath); err == nil {
		t.Fatal("Load accepted non-loopback HTTP endpoint")
	}

	path := filepath.Join(directory, "config.json")
	configuration := Config{
		APIURL:                 "https://monitor.example/api/v1/metrics",
		ConfigURL:              "https://monitor.example/api/v1/agent/config",
		Token:                  validToken,
		QueuePath:              "/var/lib/mirvmon-agent/queue.json",
		IntervalSeconds:        60,
		VerifyTLS:              true,
		CollectProcessCommands: false,
		Enabled:                true,
		QueueLimit:             1000,
	}
	if err := WriteAtomic(path, configuration, Raw{"custom_setting": []byte(`{"keep":true}`)}); err != nil {
		t.Fatal(err)
	}
	contents, err := os.ReadFile(path)
	if err != nil {
		t.Fatal(err)
	}
	if !strings.Contains(string(contents), `"custom_setting":{"keep":true}`) {
		t.Fatalf("unknown key was not written: %s", contents)
	}
	if info, err := os.Stat(path); err != nil || info.Mode().Perm() != 0600 {
		t.Fatalf("config mode was not 0600: %v, %v", info, err)
	}
}

func TestApplyRemoteRejectsInvalidUpdateWithoutChangingConfig(t *testing.T) {
	configuration := Config{
		APIURL:          "https://monitor.example/api/v1/metrics",
		ConfigURL:       "https://monitor.example/api/v1/agent/config",
		Token:           validToken,
		QueuePath:       "/var/lib/mirvmon-agent/queue.json",
		IntervalSeconds: 60,
		VerifyTLS:       true,
		Enabled:         true,
		QueueLimit:      1000,
	}
	invalidInterval := 9
	updated, ok := ApplyRemote(configuration, Remote{IntervalSeconds: &invalidInterval})
	if ok || !reflect.DeepEqual(updated, configuration) {
		t.Fatalf("invalid remote update changed config: %#v", updated)
	}
}
