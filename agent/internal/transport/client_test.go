package transport

import (
	"context"
	"errors"
	"io"
	"net/http"
	"strings"
	"testing"
	"time"

	"github.com/mirivlad/mirvmon/agent/internal/config"
	"github.com/mirivlad/mirvmon/agent/internal/update"
)

const transportToken = "aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa"

func TestSendClassifiesResponsesWithoutLosingAuthFailures(t *testing.T) {
	for code, want := range map[int]Outcome{
		200: Accepted, 202: Accepted, 400: Permanent, 413: Permanent,
		422: Permanent, 401: Authentication, 403: Authentication,
		408: Retry, 429: Retry, 500: Retry,
	} {
		t.Run(http.StatusText(code), func(t *testing.T) {
			client := testClientReturning(code)
			got, err := client.Send(context.Background(), []byte(`{"version":2}`))
			if err != nil && want != Retry {
				t.Fatalf("%d: %v", code, err)
			}
			if got != want {
				t.Fatalf("%d: got %v want %v", code, got, want)
			}
		})
	}
}

func TestConfigCredentialNeverCrossesOrigin(t *testing.T) {
	client := New(testConfig(), WithRoundTripper(redirectRecorder(t)))
	_, err := client.PullConfig(context.Background())
	if !errors.Is(err, ErrCrossOriginRedirect) {
		t.Fatalf("got %v", err)
	}
}

func TestPullConfigRejectsOversizedAndMalformedBodies(t *testing.T) {
	for name, body := range map[string]string{
		"malformed":     "{",
		"oversized":     strings.Repeat("x", maxResponseBytes+1),
		"trailing JSON": `{"enabled":true}{}`,
	} {
		t.Run(name, func(t *testing.T) {
			client := New(testConfig(), WithRoundTripper(roundTripperFunc(func(*http.Request) (*http.Response, error) {
				return response(http.StatusOK, body), nil
			})))
			_, err := client.PullConfig(context.Background())
			if err == nil {
				t.Fatal("PullConfig accepted invalid response body")
			}
		})
	}
}

func TestPullConfigDecodesTypedUpdateAndReportsProgressWithBearer(t *testing.T) {
	requests := 0
	client := New(testConfig(), WithRoundTripper(roundTripperFunc(func(request *http.Request) (*http.Response, error) {
		requests++
		if request.Header.Get("Authorization") != "Bearer "+transportToken {
			t.Fatalf("missing bearer: %#v", request.Header)
		}
		if request.Method == http.MethodGet {
			return response(http.StatusOK, `{"enabled":true,"interval_seconds":60,"monitor_services":[],"update_command":{"id":"20000000-0000-4000-8000-000000000001","target_version":"v0.4.3","artifact":"linux-amd64","sha256":"aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa","size":100}}`), nil
		}
		if request.URL.Path != "/api/v1/agent/update/20000000-0000-4000-8000-000000000001/status" {
			t.Fatalf("unexpected status path %q", request.URL.Path)
		}
		return response(http.StatusOK, `{"saved":true}`), nil
	})))
	remote, err := client.PullConfig(context.Background())
	if err != nil || remote.UpdateCommand == nil || remote.UpdateCommand.Artifact != "linux-amd64" {
		t.Fatalf("remote=%#v err=%v", remote, err)
	}
	if err := client.ReportUpdate(context.Background(), *remote.UpdateCommand, update.StateAccepted, ""); err != nil {
		t.Fatal(err)
	}
	if requests != 2 {
		t.Fatalf("requests=%d", requests)
	}
}

func TestNewRejectsInsecureTLSOutsideLoopbackHTTP(t *testing.T) {
	configuration := testConfig()
	configuration.VerifyTLS = false
	client := New(configuration)
	_, err := client.PullConfig(context.Background())
	if !errors.Is(err, ErrInvalidTLSConfiguration) {
		t.Fatalf("got %v", err)
	}
}

func TestRetryDelayIsBoundedExponential(t *testing.T) {
	for attempt, want := range map[int]time.Duration{
		-1: time.Second,
		0:  time.Second,
		1:  2 * time.Second,
		2:  4 * time.Second,
		9:  5 * time.Minute,
	} {
		if got := RetryDelay(attempt); got != want {
			t.Fatalf("attempt %d: got %s want %s", attempt, got, want)
		}
	}
}

func testClientReturning(code int) *Client {
	return New(testConfig(), WithRoundTripper(roundTripperFunc(func(request *http.Request) (*http.Response, error) {
		if request.Method != http.MethodPost {
			return nil, errors.New("unexpected method")
		}
		return response(code, ""), nil
	})))
}

func testConfig() config.Config {
	return config.Config{
		APIURL:          "https://monitor.example/api/v1/metrics",
		ConfigURL:       "https://monitor.example/api/v1/agent/config",
		Token:           transportToken,
		QueuePath:       "/var/lib/mirvmon-agent/queue.json",
		IntervalSeconds: 60,
		VerifyTLS:       true,
		Enabled:         true,
		QueueLimit:      1000,
	}
}

func redirectRecorder(t *testing.T) http.RoundTripper {
	t.Helper()
	return roundTripperFunc(func(request *http.Request) (*http.Response, error) {
		if request.URL.Host == "monitor.example" {
			if request.Header.Get("Authorization") != "Bearer "+transportToken {
				t.Fatalf("missing initial authorization header: %#v", request.Header)
			}
			redirect := response(http.StatusFound, "")
			redirect.Header.Set("Location", "https://other.example/config")
			return redirect, nil
		}
		if request.Header.Get("Authorization") != "" {
			t.Fatalf("authorization reached a different origin: %#v", request.Header)
		}
		return response(http.StatusOK, `{}`), nil
	})
}

func response(status int, body string) *http.Response {
	return &http.Response{
		StatusCode: status,
		Header:     make(http.Header),
		Body:       io.NopCloser(strings.NewReader(body)),
	}
}

type roundTripperFunc func(*http.Request) (*http.Response, error)

func (function roundTripperFunc) RoundTrip(request *http.Request) (*http.Response, error) {
	return function(request)
}
