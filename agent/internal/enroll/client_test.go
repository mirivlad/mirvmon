package enroll

import (
	"context"
	"net/http"
	"net/http/httptest"
	"os"
	"path/filepath"
	"strings"
	"testing"

	"github.com/mirivlad/mirvmon/agent/internal/config"
)

const (
	installerCredential = "aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa"
	permanentToken      = "bbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbb"
)

func TestActivateExchangesCredentialAndWritesValidatedConfig(t *testing.T) {
	server := httptest.NewServer(http.HandlerFunc(func(writer http.ResponseWriter, request *http.Request) {
		if request.Method != http.MethodPost || request.URL.Path != "/api/v1/agent/install" {
			t.Fatalf("unexpected request %s %s", request.Method, request.URL.Path)
		}
		if request.URL.RawQuery != "" || request.Header.Get("Authorization") != "Bearer "+installerCredential {
			t.Fatalf("credential was not sent only as bearer: %s %#v", request.URL.String(), request.Header)
		}
		writer.Header().Set("Content-Type", "application/json")
		_, _ = writer.Write([]byte(validConfiguration(permanentToken)))
	}))
	defer server.Close()

	directory := t.TempDir()
	bootstrapPath := filepath.Join(directory, "bootstrap.json")
	outputPath := filepath.Join(directory, "config.json")
	writeBootstrap(t, bootstrapPath, server.URL, installerCredential)

	if err := Activate(context.Background(), Request{
		BootstrapPath: bootstrapPath,
		OutputConfig:  outputPath,
		HTTPClient:    server.Client(),
	}); err != nil {
		t.Fatal(err)
	}

	configuration, _, err := config.Load(outputPath)
	if err != nil {
		t.Fatal(err)
	}
	if configuration.Token != permanentToken || configuration.QueuePath != `%PROGRAMDATA%\MirvMon\Agent\queue.json` {
		t.Fatalf("unexpected configuration: %#v", configuration)
	}
}

func TestActivateRejectsInvalidInputsAndBoundedResponsesWithoutLeakingSecrets(t *testing.T) {
	tests := []struct {
		name       string
		baseURL    string
		credential string
		status     int
		body       string
	}{
		{name: "malformed credential", credential: "secret-invalid", status: http.StatusOK, body: validConfiguration(permanentToken)},
		{name: "insecure remote URL", baseURL: "http://monitor.example", credential: installerCredential, status: http.StatusOK, body: validConfiguration(permanentToken)},
		{name: "authentication rejected", credential: installerCredential, status: http.StatusUnauthorized, body: `{"error":{"code":"invalid_token"}}`},
		{name: "malformed config", credential: installerCredential, status: http.StatusOK, body: `{"token":"` + permanentToken + `"}`},
		{name: "oversized response", credential: installerCredential, status: http.StatusOK, body: strings.Repeat("x", maxResponseBytes+1)},
	}
	for _, test := range tests {
		t.Run(test.name, func(t *testing.T) {
			server := httptest.NewServer(http.HandlerFunc(func(writer http.ResponseWriter, _ *http.Request) {
				writer.WriteHeader(test.status)
				_, _ = writer.Write([]byte(test.body))
			}))
			defer server.Close()
			baseURL := test.baseURL
			if baseURL == "" {
				baseURL = server.URL
			}
			directory := t.TempDir()
			bootstrapPath := filepath.Join(directory, "bootstrap.json")
			outputPath := filepath.Join(directory, "config.json")
			writeBootstrap(t, bootstrapPath, baseURL, test.credential)

			err := Activate(context.Background(), Request{
				BootstrapPath: bootstrapPath,
				OutputConfig:  outputPath,
				HTTPClient:    server.Client(),
			})
			if err == nil {
				t.Fatal("Activate accepted invalid input")
			}
			message := err.Error()
			for _, secret := range []string{installerCredential, permanentToken, "secret-invalid"} {
				if strings.Contains(message, secret) {
					t.Fatalf("secret leaked in error: %q", message)
				}
			}
			if _, statErr := os.Stat(outputPath); !os.IsNotExist(statErr) {
				t.Fatalf("output created after failure: %v", statErr)
			}
		})
	}
}

func TestActivateRejectsTrailingJSON(t *testing.T) {
	server := httptest.NewServer(http.HandlerFunc(func(writer http.ResponseWriter, _ *http.Request) {
		_, _ = writer.Write([]byte(validConfiguration(permanentToken) + `{}`))
	}))
	defer server.Close()
	directory := t.TempDir()
	bootstrapPath := filepath.Join(directory, "bootstrap.json")
	writeBootstrap(t, bootstrapPath, server.URL, installerCredential)

	err := Activate(context.Background(), Request{
		BootstrapPath: bootstrapPath,
		OutputConfig:  filepath.Join(directory, "config.json"),
		HTTPClient:    server.Client(),
	})
	if err == nil {
		t.Fatal("Activate accepted trailing JSON")
	}
}

func writeBootstrap(t *testing.T, path, baseURL, credential string) {
	t.Helper()
	contents := `{"base_url":"` + baseURL + `","installer_credential":"` + credential + `"}`
	if err := os.WriteFile(path, []byte(contents), 0600); err != nil {
		t.Fatal(err)
	}
}

func validConfiguration(token string) string {
	return `{"api_url":"https://monitor.example/api/v1/metrics","config_url":"https://monitor.example/api/v1/agent/config","token":"` + token + `","queue_path":"%PROGRAMDATA%\\MirvMon\\Agent\\queue.json","interval_seconds":60,"verify_tls":true,"collect_process_commands":false,"enabled":true,"monitor_services":[],"queue_limit":1000}`
}
