package probe

import (
	"context"
	"net/http"
	"net/http/httptest"
	"testing"

	"github.com/mirivlad/mirvmon/agent/internal/config"
)

func probeJob(target string) config.ProbeJob {
	return config.ProbeJob{
		ID:              "website-1-endpoint-2",
		WebsiteID:       1,
		EndpointID:      2,
		URL:             target,
		Method:          "GET",
		IntervalSeconds: 30,
		TimeoutSeconds:  2,
		FollowRedirects: true,
		MaxRedirects:    3,
	}
}
func TestHTTPExecutorReportsSuccessfulResponse(t *testing.T) {
	server := httptest.NewServer(http.HandlerFunc(func(writer http.ResponseWriter, request *http.Request) {
		writer.WriteHeader(http.StatusNoContent)
	}))
	defer server.Close()

	results := NewHTTPExecutor().Execute(context.Background(), []config.ProbeJob{probeJob(server.URL)})
	if len(results) != 1 {
		t.Fatalf("got %d results", len(results))
	}
	result := results[0]
	if !result.Available || result.StatusCode == nil || *result.StatusCode != http.StatusNoContent {
		t.Fatalf("unexpected result: %#v", result)
	}
	if result.ErrorKind != "" {
		t.Fatalf("unexpected error kind %q", result.ErrorKind)
	}
}
func TestHTTPExecutorKeepsTLSVerificationEnabled(t *testing.T) {
	server := httptest.NewTLSServer(http.HandlerFunc(func(writer http.ResponseWriter, request *http.Request) {
		writer.WriteHeader(http.StatusOK)
	}))
	defer server.Close()

	results := NewHTTPExecutor().Execute(context.Background(), []config.ProbeJob{probeJob(server.URL)})
	if len(results) != 1 {
		t.Fatalf("got %d results", len(results))
	}
	result := results[0]
	if result.Available {
		t.Fatalf("self-signed TLS endpoint unexpectedly accepted: %#v", result)
	}
	if result.ErrorKind != "tls" {
		t.Fatalf("expected tls error, got %#v", result)
	}
}
