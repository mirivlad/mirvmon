// Package transport implements the agent's bounded, proxy-aware HTTPS client.
package transport

import (
	"bytes"
	"context"
	"crypto/tls"
	"encoding/json"
	"errors"
	"fmt"
	"io"
	"net/http"
	"net/url"
	"strings"
	"time"

	"github.com/mirivlad/mirvmon/agent/internal/config"
	"github.com/mirivlad/mirvmon/agent/internal/update"
)

const (
	maxResponseBytes = 64 * 1024
	requestTimeout   = 15 * time.Second
)

var (
	ErrCrossOriginRedirect     = errors.New("cross-origin redirect refused")
	ErrTooManyRedirects        = errors.New("too many redirects")
	ErrInvalidTLSConfiguration = errors.New("insecure TLS is only allowed for loopback HTTP")
	ErrAuthentication          = errors.New("agent authentication failed")
	ErrUnexpectedConfig        = errors.New("unexpected configuration response")
	ErrResponseTooLarge        = errors.New("response body exceeds limit")
	ErrInvalidRemoteConfig     = errors.New("invalid remote configuration")
)

// Outcome determines whether the runner accepts, retries, or quarantines the
// queue head after a metrics request.
type Outcome uint8

const (
	Accepted Outcome = iota
	Permanent
	Retry
	Authentication
)

// Client sends envelopes and fetches remote agent configuration.
type Client struct {
	configuration config.Config
	http          *http.Client
	initErr       error
}

// Option customises a Client for tests without changing production defaults.
type Option func(*clientOptions)

type clientOptions struct {
	roundTripper http.RoundTripper
}

// WithRoundTripper injects the single HTTP boundary used by Client.
func WithRoundTripper(roundTripper http.RoundTripper) Option {
	return func(options *clientOptions) {
		options.roundTripper = roundTripper
	}
}

// New creates a client. Invalid configuration is retained as an operation
// error so callers keep a small, testable constructor.
func New(configuration config.Config, options ...Option) *Client {
	settings := clientOptions{}
	for _, option := range options {
		option(&settings)
	}
	client := &Client{configuration: configuration}
	if err := configuration.Validate(); err != nil {
		client.initErr = err
		return client
	}
	if !configuration.VerifyTLS && !bothLoopbackHTTP(configuration) {
		client.initErr = ErrInvalidTLSConfiguration
		return client
	}

	transport := settings.roundTripper
	if transport == nil {
		transport = &http.Transport{
			Proxy: http.ProxyFromEnvironment,
			TLSClientConfig: &tls.Config{
				MinVersion: tls.VersionTLS12,
			},
		}
	}
	client.http = &http.Client{
		Transport: transport,
		Timeout:   requestTimeout,
		CheckRedirect: func(request *http.Request, previous []*http.Request) error {
			if len(previous) > 3 {
				return ErrTooManyRedirects
			}
			if len(previous) == 0 || !sameOrigin(previous[len(previous)-1].URL, request.URL) {
				return ErrCrossOriginRedirect
			}
			return nil
		},
	}
	return client
}

// Send posts one envelope without ever putting its permanent token in a URL.
func (client *Client) Send(context context.Context, envelope []byte) (Outcome, error) {
	if client.initErr != nil {
		return Retry, client.initErr
	}
	request, err := http.NewRequestWithContext(
		context,
		http.MethodPost,
		client.configuration.APIURL,
		bytes.NewReader(envelope),
	)
	if err != nil {
		return Retry, fmt.Errorf("create metrics request: %w", err)
	}
	request.Header.Set("Accept", "application/json")
	request.Header.Set("Content-Type", "application/json")

	response, err := client.http.Do(request)
	if err != nil {
		return Retry, err
	}
	defer response.Body.Close()
	if _, err := readBody(response.Body); err != nil {
		return Retry, err
	}

	switch response.StatusCode {
	case http.StatusOK, http.StatusAccepted:
		return Accepted, nil
	case http.StatusBadRequest, http.StatusRequestEntityTooLarge, http.StatusUnprocessableEntity:
		return Permanent, nil
	case http.StatusUnauthorized, http.StatusForbidden:
		return Authentication, nil
	default:
		return Retry, nil
	}
}

// PullConfig uses the Bearer credential only for the configured same-origin
// endpoint and parses exactly the server's supported remote settings.
func (client *Client) PullConfig(context context.Context) (config.Remote, error) {
	if client.initErr != nil {
		return config.Remote{}, client.initErr
	}
	request, err := http.NewRequestWithContext(
		context,
		http.MethodGet,
		client.configuration.ConfigURL,
		nil,
	)
	if err != nil {
		return config.Remote{}, fmt.Errorf("create config request: %w", err)
	}
	request.Header.Set("Accept", "application/json")
	request.Header.Set("Authorization", "Bearer "+client.configuration.Token)

	response, err := client.http.Do(request)
	if err != nil {
		return config.Remote{}, err
	}
	defer response.Body.Close()
	body, err := readBody(response.Body)
	if err != nil {
		return config.Remote{}, err
	}
	if response.StatusCode == http.StatusUnauthorized || response.StatusCode == http.StatusForbidden {
		return config.Remote{}, ErrAuthentication
	}
	if response.StatusCode != http.StatusOK {
		return config.Remote{}, fmt.Errorf("%w: HTTP %d", ErrUnexpectedConfig, response.StatusCode)
	}

	decoder := json.NewDecoder(bytes.NewReader(body))
	decoder.DisallowUnknownFields()
	var remote config.Remote
	if err := decoder.Decode(&remote); err != nil {
		return config.Remote{}, fmt.Errorf("%w: %v", ErrInvalidRemoteConfig, err)
	}
	if decoder.More() {
		return config.Remote{}, ErrInvalidRemoteConfig
	}
	if _, ok := config.ApplyRemote(client.configuration, remote); !ok {
		return config.Remote{}, ErrInvalidRemoteConfig
	}
	if remote.UpdateCommand != nil {
		if err := remote.UpdateCommand.Validate(); err != nil {
			return config.Remote{}, ErrInvalidRemoteConfig
		}
	}
	return remote, nil
}

// ReportUpdate advances one bearer-owned command without exposing the token in
// either the URL or body.
func (client *Client) ReportUpdate(
	context context.Context,
	command update.Command,
	state string,
	errorCode string,
) error {
	if client.initErr != nil {
		return client.initErr
	}
	if err := command.Validate(); err != nil {
		return err
	}
	base, err := url.Parse(client.configuration.ConfigURL)
	if err != nil {
		return ErrInvalidRemoteConfig
	}
	base.Path = "/api/v1/agent/update/" + command.ID + "/status"
	base.RawPath = ""
	base.RawQuery = ""
	base.Fragment = ""
	payload := map[string]string{"state": state}
	if errorCode != "" {
		payload["error_code"] = errorCode
	}
	body, err := json.Marshal(payload)
	if err != nil {
		return fmt.Errorf("encode update status: %w", err)
	}
	request, err := http.NewRequestWithContext(context, http.MethodPost, base.String(), bytes.NewReader(body))
	if err != nil {
		return fmt.Errorf("create update status request: %w", err)
	}
	request.Header.Set("Accept", "application/json")
	request.Header.Set("Content-Type", "application/json")
	request.Header.Set("Authorization", "Bearer "+client.configuration.Token)
	response, err := client.http.Do(request)
	if err != nil {
		return err
	}
	defer response.Body.Close()
	if _, err := readBody(response.Body); err != nil {
		return err
	}
	if response.StatusCode == http.StatusUnauthorized || response.StatusCode == http.StatusForbidden {
		return ErrAuthentication
	}
	if response.StatusCode != http.StatusOK {
		return fmt.Errorf("update status HTTP %d", response.StatusCode)
	}
	return nil
}

// RetryDelay is deterministic exponential backoff, capped at five minutes.
func RetryDelay(attempt int) time.Duration {
	if attempt < 0 {
		attempt = 0
	}
	if attempt >= 9 {
		return 5 * time.Minute
	}
	delay := time.Second << attempt
	if delay > 5*time.Minute {
		return 5 * time.Minute
	}
	return delay
}

func readBody(body io.Reader) ([]byte, error) {
	contents, err := io.ReadAll(io.LimitReader(body, maxResponseBytes+1))
	if err != nil {
		return nil, fmt.Errorf("read response body: %w", err)
	}
	if len(contents) > maxResponseBytes {
		return nil, ErrResponseTooLarge
	}
	return contents, nil
}

func bothLoopbackHTTP(configuration config.Config) bool {
	return loopbackHTTP(configuration.APIURL) && loopbackHTTP(configuration.ConfigURL)
}

func loopbackHTTP(value string) bool {
	parsed, err := url.Parse(value)
	if err != nil || parsed.Scheme != "http" {
		return false
	}
	host := strings.ToLower(parsed.Hostname())
	return host == "localhost" || host == "127.0.0.1" || host == "::1"
}

func sameOrigin(first, second *url.URL) bool {
	if !strings.EqualFold(first.Scheme, second.Scheme) || !strings.EqualFold(first.Hostname(), second.Hostname()) {
		return false
	}
	return port(first) == port(second)
}

func port(value *url.URL) string {
	if explicit := value.Port(); explicit != "" {
		return explicit
	}
	if strings.EqualFold(value.Scheme, "https") {
		return "443"
	}
	return "80"
}
