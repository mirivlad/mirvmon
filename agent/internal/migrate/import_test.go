package migrate

import (
	"encoding/json"
	"os"
	"path/filepath"
	"reflect"
	"strings"
	"testing"
	"time"
)

func TestImportPreservesIdentityUnknownConfigAndQueueOrder(t *testing.T) {
	request := fixtureRequest(t, "python")
	request.Now = time.Date(2026, 8, 12, 12, 0, 0, 0, time.UTC)
	report, err := Import(request)
	if err != nil {
		t.Fatal(err)
	}
	if report.Imported != 2 || report.Duplicates != 1 {
		t.Fatalf("unexpected report: %#v", report)
	}
	raw := readJSONMap(t, request.OutputConfig)
	if string(raw["custom_setting"]) != `{"keep":true}` {
		t.Fatalf("unknown config lost: %s", raw["custom_setting"])
	}
	if got := queueIDs(t, request.OutputQueue); !reflect.DeepEqual(got, []string{"one", "two"}) {
		t.Fatalf("ids=%v", got)
	}
}

func TestImportRewritesOnlyRotatedTokenAndQuarantinesExpiredSample(t *testing.T) {
	request := fixtureRequest(t, "powershell")
	request.Now = time.Date(2026, 8, 12, 12, 0, 0, 0, time.UTC)
	report, err := Import(request)
	if err != nil {
		t.Fatal(err)
	}
	if report.Expired != 1 || report.Imported != 1 {
		t.Fatalf("unexpected report: %#v", report)
	}
	assertEveryQueuedToken(t, request.OutputQueue, strings.Repeat("b", 64))
	quarantine, err := os.ReadFile(request.QuarantinePath)
	if err != nil {
		t.Fatal(err)
	}
	if strings.Contains(string(quarantine), strings.Repeat("a", 64)) || !strings.Contains(string(quarantine), `"reason":"expired"`) {
		t.Fatalf("unexpected quarantine: %s", quarantine)
	}
}

func fixtureRequest(t *testing.T, kind string) Request {
	t.Helper()
	directory := t.TempDir()
	copyFixture(t, "python-config.json", filepath.Join(directory, "old-config.json"))
	queueName := kind + "-queue.json"
	if kind == "powershell" {
		queueName = "powershell-queue.txt"
	}
	copyFixture(t, queueName, filepath.Join(directory, "old-queue"))
	serverConfig := `{
        "api_url":"https://new.example/api/v1/metrics",
        "config_url":"https://new.example/api/v1/agent/config",
        "token":"bbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbb",
        "queue_path":"` + filepath.Join(directory, "output-queue.json") + `",
        "interval_seconds":60,
        "verify_tls":true
    }`
	serverPath := filepath.Join(directory, "server-config.json")
	if err := os.WriteFile(serverPath, []byte(serverConfig), 0600); err != nil {
		t.Fatal(err)
	}
	return Request{
		SourceConfig:   filepath.Join(directory, "old-config.json"),
		SourceQueue:    filepath.Join(directory, "old-queue"),
		ServerConfig:   serverPath,
		OutputConfig:   filepath.Join(directory, "output-config.json"),
		OutputQueue:    filepath.Join(directory, "output-queue.json"),
		QuarantinePath: filepath.Join(directory, "quarantine.json"),
	}
}

func copyFixture(t *testing.T, name, destination string) {
	t.Helper()
	contents, err := os.ReadFile(filepath.Join("testdata", name))
	if err != nil {
		t.Fatal(err)
	}
	if err := os.WriteFile(destination, contents, 0600); err != nil {
		t.Fatal(err)
	}
}

func readJSONMap(t *testing.T, path string) map[string]json.RawMessage {
	t.Helper()
	contents, err := os.ReadFile(path)
	if err != nil {
		t.Fatal(err)
	}
	var raw map[string]json.RawMessage
	if err := json.Unmarshal(contents, &raw); err != nil {
		t.Fatal(err)
	}
	return raw
}

func queueIDs(t *testing.T, path string) []string {
	t.Helper()
	var values []map[string]json.RawMessage
	contents, err := os.ReadFile(path)
	if err != nil {
		t.Fatal(err)
	}
	if err := json.Unmarshal(contents, &values); err != nil {
		t.Fatal(err)
	}
	ids := make([]string, 0, len(values))
	for _, value := range values {
		var id string
		if err := json.Unmarshal(value["sample_id"], &id); err != nil {
			t.Fatal(err)
		}
		ids = append(ids, id)
	}
	return ids
}

func assertEveryQueuedToken(t *testing.T, path, want string) {
	t.Helper()
	contents, err := os.ReadFile(path)
	if err != nil {
		t.Fatal(err)
	}
	var values []map[string]json.RawMessage
	if err := json.Unmarshal(contents, &values); err != nil {
		t.Fatal(err)
	}
	for _, value := range values {
		var token string
		if err := json.Unmarshal(value["token"], &token); err != nil || token != want {
			t.Fatal("queued token was not rotated")
		}
	}
}
