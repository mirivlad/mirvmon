package main

import (
	"bytes"
	"net/http"
	"net/http/httptest"
	"os"
	"path/filepath"
	"strings"
	"testing"
)

func TestExecuteVersionDoesNotExposeConfiguration(t *testing.T) {
	var stdout, stderr bytes.Buffer
	code := execute([]string{"version"}, &stdout, &stderr)
	if code != exitSuccess {
		t.Fatalf("exit=%d stderr=%s", code, stderr.String())
	}
	if !strings.Contains(stdout.String(), "dev unknown") ||
		!strings.Contains(stdout.String(), "development") ||
		strings.Contains(stdout.String(), "token") {
		t.Fatalf("unexpected version output: %q", stdout.String())
	}
}

func TestExecuteApplyUpdateRejectsIncompleteArguments(t *testing.T) {
	var stdout, stderr bytes.Buffer
	code := execute([]string{"apply-update", "--request", "/tmp/request.json"}, &stdout, &stderr)
	if code != exitInvalid {
		t.Fatalf("exit=%d stderr=%s", code, stderr.String())
	}
	if strings.Contains(stderr.String(), "/tmp/request.json") {
		t.Fatalf("arguments leaked: %q", stderr.String())
	}
}

func TestExecuteMigrateConvertsLegacyState(t *testing.T) {
	directory := t.TempDir()
	sourceConfig := filepath.Join(directory, "old-config.json")
	sourceQueue := filepath.Join(directory, "old-queue.json")
	serverConfig := filepath.Join(directory, "server-config.json")
	outputConfig := filepath.Join(directory, "new-config.json")
	outputQueue := filepath.Join(directory, "new-queue.json")
	if err := os.WriteFile(sourceConfig, []byte(`{"api_url":"https://old.example/api/v1/metrics","config_url":"https://old.example/api/v1/agent/config","token":"aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa","queue_path":"/tmp/old.json"}`), 0600); err != nil {
		t.Fatal(err)
	}
	if err := os.WriteFile(sourceQueue, []byte(`[{"sample_id":"one","sample_time":"2099-01-01T00:00:00Z","token":"aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa"}]`), 0600); err != nil {
		t.Fatal(err)
	}
	if err := os.WriteFile(serverConfig, []byte(`{"api_url":"https://new.example/api/v1/metrics","config_url":"https://new.example/api/v1/agent/config","token":"bbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbb","queue_path":"/tmp/new.json"}`), 0600); err != nil {
		t.Fatal(err)
	}
	var stdout, stderr bytes.Buffer
	code := execute([]string{"migrate", "--source-config", sourceConfig, "--source-queue", sourceQueue, "--server-config", serverConfig, "--output-config", outputConfig, "--output-queue", outputQueue}, &stdout, &stderr)
	if code != exitSuccess {
		t.Fatalf("exit=%d stderr=%s", code, stderr.String())
	}
	contents, err := os.ReadFile(outputQueue)
	if err != nil {
		t.Fatal(err)
	}
	if !strings.Contains(string(contents), `"token":"bbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbb"`) {
		t.Fatalf("migration did not rotate queue credential: %s", contents)
	}
}

func TestExecuteRejectsUnknownCommandWithoutLeakingArguments(t *testing.T) {
	var stdout, stderr bytes.Buffer
	code := execute([]string{"invalid", "aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa"}, &stdout, &stderr)
	if code != exitInvalid {
		t.Fatalf("exit=%d stderr=%s", code, stderr.String())
	}
	if strings.Contains(stderr.String(), "aaaaaaaa") {
		t.Fatalf("invalid arguments leaked: %q", stderr.String())
	}
}

func TestExecuteActivateWritesConfiguration(t *testing.T) {
	server := httptest.NewServer(http.HandlerFunc(func(writer http.ResponseWriter, request *http.Request) {
		if request.Header.Get("Authorization") != "Bearer "+strings.Repeat("a", 64) {
			t.Fatal("installer credential missing")
		}
		_, _ = writer.Write([]byte(`{"api_url":"https://monitor.example/api/v1/metrics","config_url":"https://monitor.example/api/v1/agent/config","token":"bbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbb","queue_path":"%PROGRAMDATA%\\MirvMon\\Agent\\queue.json","interval_seconds":60,"verify_tls":true,"enabled":true,"monitor_services":[],"queue_limit":1000}`))
	}))
	defer server.Close()
	directory := t.TempDir()
	bootstrap := filepath.Join(directory, "bootstrap.json")
	output := filepath.Join(directory, "config.json")
	if err := os.WriteFile(bootstrap, []byte(`{"base_url":"`+server.URL+`","installer_credential":"`+strings.Repeat("a", 64)+`"}`), 0600); err != nil {
		t.Fatal(err)
	}
	var stdout, stderr bytes.Buffer
	code := execute([]string{"activate", "--bootstrap", bootstrap, "--output-config", output}, &stdout, &stderr)
	if code != exitSuccess {
		t.Fatalf("exit=%d stderr=%s", code, stderr.String())
	}
	contents, err := os.ReadFile(output)
	if err != nil || !strings.Contains(string(contents), strings.Repeat("b", 64)) {
		t.Fatalf("configuration not written: %s err=%v", contents, err)
	}
}

func TestExecuteActivateRejectsInvalidArgumentsWithoutLeakingThem(t *testing.T) {
	secret := strings.Repeat("a", 64)
	var stdout, stderr bytes.Buffer
	code := execute([]string{"activate", "--bootstrap", secret}, &stdout, &stderr)
	if code != exitInvalid {
		t.Fatalf("exit=%d stderr=%s", code, stderr.String())
	}
	if strings.Contains(stderr.String(), secret) {
		t.Fatalf("activation argument leaked: %q", stderr.String())
	}
}

func TestExecuteInstallWindowsRejectsIncompleteArgumentsWithoutLeakingThem(t *testing.T) {
	secret := strings.Repeat("a", 64)
	var stdout, stderr bytes.Buffer
	code := execute([]string{"install-windows", "--bootstrap", secret}, &stdout, &stderr)
	if code != exitInvalid {
		t.Fatalf("exit=%d stderr=%s", code, stderr.String())
	}
	if !strings.Contains(stderr.String(), "invalid installation arguments") {
		t.Fatalf("wrong installer error: %q", stderr.String())
	}
	if strings.Contains(stderr.String(), secret) {
		t.Fatalf("installer argument leaked: %q", stderr.String())
	}
}
