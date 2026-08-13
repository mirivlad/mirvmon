// Package enroll exchanges a short-lived installer credential for a validated
// native agent configuration without exposing either credential in a URL.
package enroll

import (
	"bytes"
	"context"
	"crypto/tls"
	"encoding/json"
	"errors"
	"io"
	"net"
	"net/http"
	"net/url"
	"os"
	"path/filepath"
	"regexp"
	"strings"
	"time"

	"github.com/mirivlad/mirvmon/agent/internal/config"
)

const (
	maxBootstrapBytes = 4096
	maxResponseBytes  = 64 * 1024
	requestTimeout    = 15 * time.Second
)

var installerCredentialPattern = regexp.MustCompile(`^[a-f0-9]{64}$`)

// Request identifies the protected bootstrap and configuration destination.
type Request struct {
	BootstrapPath string
	OutputConfig  string
	HTTPClient    *http.Client
}

type bootstrap struct {
	BaseURL             string `json:"base_url"`
	InstallerCredential string `json:"installer_credential"`
}

// Activate consumes one installer credential and atomically writes the
// returned permanent configuration only after shared validation succeeds.
func Activate(ctx context.Context, request Request) error {
	if request.BootstrapPath == "" || request.OutputConfig == "" {
		return errors.New("invalid activation arguments")
	}
	contents, err := readLimitedFile(request.BootstrapPath, maxBootstrapBytes)
	if err != nil {
		return errors.New("invalid activation bootstrap")
	}
	var settings bootstrap
	decoder := json.NewDecoder(bytes.NewReader(contents))
	decoder.DisallowUnknownFields()
	if err := decoder.Decode(&settings); err != nil || decoder.More() {
		return errors.New("invalid activation bootstrap")
	}
	baseURL, err := validateBaseURL(settings.BaseURL)
	if err != nil || !installerCredentialPattern.MatchString(settings.InstallerCredential) {
		return errors.New("invalid activation bootstrap")
	}

	httpClient := activationClient(request.HTTPClient)
	httpRequest, err := http.NewRequestWithContext(
		ctx,
		http.MethodPost,
		baseURL+"/api/v1/agent/install",
		nil,
	)
	if err != nil {
		return errors.New("activation request failed")
	}
	httpRequest.Header.Set("Accept", "application/json")
	httpRequest.Header.Set("Authorization", "Bearer "+settings.InstallerCredential)
	response, err := httpClient.Do(httpRequest)
	if err != nil {
		return errors.New("activation request failed")
	}
	defer response.Body.Close()
	body, err := readLimited(response.Body, maxResponseBytes)
	if err != nil || response.StatusCode != http.StatusOK || !json.Valid(body) {
		return errors.New("activation response rejected")
	}
	if err := validateAndWriteConfig(request.OutputConfig, body); err != nil {
		return errors.New("activation configuration rejected")
	}
	return nil
}

func activationClient(provided *http.Client) *http.Client {
	if provided == nil {
		provided = &http.Client{
			Transport: &http.Transport{
				Proxy: http.ProxyFromEnvironment,
				TLSClientConfig: &tls.Config{
					MinVersion: tls.VersionTLS12,
				},
			},
		}
	}
	client := *provided
	if client.Timeout == 0 || client.Timeout > requestTimeout {
		client.Timeout = requestTimeout
	}
	client.CheckRedirect = func(*http.Request, []*http.Request) error {
		return http.ErrUseLastResponse
	}
	return &client
}

func validateBaseURL(value string) (string, error) {
	parsed, err := url.ParseRequestURI(value)
	if err != nil || parsed.Host == "" || parsed.User != nil || parsed.RawQuery != "" || parsed.Fragment != "" || (parsed.Path != "" && parsed.Path != "/") {
		return "", errors.New("invalid base URL")
	}
	if parsed.Scheme != "https" && !(parsed.Scheme == "http" && isLoopback(parsed.Hostname())) {
		return "", errors.New("invalid base URL")
	}
	return strings.TrimSuffix(parsed.String(), "/"), nil
}

func isLoopback(host string) bool {
	if strings.EqualFold(host, "localhost") {
		return true
	}
	address := net.ParseIP(host)
	return address != nil && address.IsLoopback()
}

func readLimitedFile(path string, maximum int64) ([]byte, error) {
	file, err := os.Open(path)
	if err != nil {
		return nil, err
	}
	defer file.Close()
	return readLimited(file, maximum)
}

func readLimited(reader io.Reader, maximum int64) ([]byte, error) {
	contents, err := io.ReadAll(io.LimitReader(reader, maximum+1))
	if err != nil {
		return nil, err
	}
	if int64(len(contents)) > maximum {
		return nil, errors.New("input exceeds limit")
	}
	return contents, nil
}

func validateAndWriteConfig(outputPath string, contents []byte) error {
	directory := filepath.Dir(outputPath)
	temporary, err := os.CreateTemp(directory, ".activation-config-*")
	if err != nil {
		return err
	}
	temporaryPath := temporary.Name()
	defer os.Remove(temporaryPath)
	if err := temporary.Chmod(0600); err != nil {
		_ = temporary.Close()
		return err
	}
	if _, err := temporary.Write(contents); err != nil {
		_ = temporary.Close()
		return err
	}
	if err := temporary.Close(); err != nil {
		return err
	}
	configuration, raw, err := config.Load(temporaryPath)
	if err != nil {
		return err
	}
	return config.WriteAtomic(outputPath, configuration, raw)
}
