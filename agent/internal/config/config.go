// Package config loads and safely updates the native agent's local settings.
package config

import (
	"bytes"
	"encoding/json"
	"errors"
	"fmt"
	"net"
	"net/url"
	"os"
	"regexp"
	"strings"

	"github.com/mirivlad/mirvmon/agent/internal/atomicfile"
)

const (
	defaultInterval   = 60
	defaultQueueLimit = 1000
)

// Raw keeps unrecognised configuration values byte-for-byte between updates.
type Raw map[string]json.RawMessage

// Config is the validated local configuration used by every agent build.
type Config struct {
	APIURL                 string
	ConfigURL              string
	Token                  string
	QueuePath              string
	IntervalSeconds        int
	VerifyTLS              bool
	CollectProcessCommands bool
	Enabled                bool
	MonitorServices        []string
	QueueLimit             int
}

// Remote is the supported response shape from GET /api/v1/agent/config.
// Pointer scalars distinguish an omitted field from an explicit false or zero.
type Remote struct {
	Enabled         *bool    `json:"enabled"`
	IntervalSeconds *int     `json:"interval_seconds"`
	MonitorServices []string `json:"monitor_services"`
}

// Load decodes a UTF-8 JSON object and returns both recognised settings and
// unrecognised values that must survive a subsequent atomic write.
func Load(path string) (Config, Raw, error) {
	contents, err := os.ReadFile(path)
	if err != nil {
		return Config{}, nil, fmt.Errorf("read config: %w", err)
	}
	contents = bytes.TrimPrefix(contents, []byte{0xef, 0xbb, 0xbf})
	var raw Raw
	if err := json.Unmarshal(contents, &raw); err != nil || raw == nil {
		if err != nil {
			return Config{}, nil, fmt.Errorf("decode config: %w", err)
		}
		return Config{}, nil, errors.New("config must be an object")
	}

	configuration := Config{
		IntervalSeconds: defaultInterval,
		VerifyTLS:       true,
		Enabled:         true,
		QueueLimit:      defaultQueueLimit,
	}
	if configuration.APIURL, err = requiredString(raw, "api_url"); err != nil {
		return Config{}, nil, err
	}
	if configuration.ConfigURL, err = requiredString(raw, "config_url"); err != nil {
		return Config{}, nil, err
	}
	if configuration.Token, err = requiredString(raw, "token"); err != nil {
		return Config{}, nil, err
	}
	if configuration.QueuePath, err = requiredString(raw, "queue_path"); err != nil {
		return Config{}, nil, err
	}
	configuration.QueuePath = expandEnvironment(configuration.QueuePath)
	if configuration.IntervalSeconds, err = optionalInt(raw, "interval_seconds", defaultInterval); err != nil {
		return Config{}, nil, err
	}
	if configuration.VerifyTLS, err = optionalBool(raw, "verify_tls", true); err != nil {
		return Config{}, nil, err
	}
	if configuration.CollectProcessCommands, err = optionalBool(raw, "collect_process_commands", false); err != nil {
		return Config{}, nil, err
	}
	if configuration.Enabled, err = optionalBool(raw, "enabled", true); err != nil {
		return Config{}, nil, err
	}
	if configuration.QueueLimit, err = optionalInt(raw, "queue_limit", defaultQueueLimit); err != nil {
		return Config{}, nil, err
	}
	if configuration.MonitorServices, err = optionalStrings(raw, "monitor_services"); err != nil {
		return Config{}, nil, err
	}
	if err := configuration.Validate(); err != nil {
		return Config{}, nil, err
	}
	return configuration, raw, nil
}

var windowsEnvironmentVariable = regexp.MustCompile(`%([^%]+)%`)

func expandEnvironment(value string) string {
	value = os.ExpandEnv(value)

	return windowsEnvironmentVariable.ReplaceAllStringFunc(value, func(match string) string {
		name := match[1 : len(match)-1]
		if replacement, ok := os.LookupEnv(name); ok {
			return replacement
		}

		return match
	})
}

// Validate enforces the local values that affect transport and storage safety.
func (configuration Config) Validate() error {
	if err := validateURL(configuration.APIURL); err != nil {
		return fmt.Errorf("invalid API URL: %w", err)
	}
	if err := validateURL(configuration.ConfigURL); err != nil {
		return fmt.Errorf("invalid config URL: %w", err)
	}
	if len(configuration.Token) < 32 || len(configuration.Token) > 512 {
		return errors.New("invalid agent token")
	}
	if strings.TrimSpace(configuration.QueuePath) == "" {
		return errors.New("queue path is required")
	}
	if configuration.IntervalSeconds < 10 || configuration.IntervalSeconds > 86400 {
		return errors.New("invalid collection interval")
	}
	if configuration.QueueLimit < 1 || configuration.QueueLimit > 10000 {
		return errors.New("invalid queue limit")
	}
	if len(configuration.MonitorServices) > 500 {
		return errors.New("too many monitored services")
	}
	for _, service := range configuration.MonitorServices {
		if service == "" {
			return errors.New("monitored service cannot be empty")
		}
	}
	return nil
}

// ApplyRemote returns an unchanged configuration and false if a complete
// remote update is not valid. This prevents partial remote mutation.
func ApplyRemote(configuration Config, remote Remote) (Config, bool) {
	updated := configuration
	if remote.Enabled != nil {
		updated.Enabled = *remote.Enabled
	}
	if remote.IntervalSeconds != nil {
		updated.IntervalSeconds = *remote.IntervalSeconds
	}
	if remote.MonitorServices != nil {
		updated.MonitorServices = append([]string(nil), remote.MonitorServices...)
	}
	if err := updated.Validate(); err != nil {
		return configuration, false
	}
	return updated, true
}

// WriteAtomic writes all recognised values alongside unknown raw JSON values
// using the shared durable replacement primitive.
func WriteAtomic(path string, configuration Config, raw Raw) error {
	if err := configuration.Validate(); err != nil {
		return err
	}
	values := make(Raw, len(raw)+10)
	for key, value := range raw {
		values[key] = append(json.RawMessage(nil), value...)
	}
	for key, value := range map[string]any{
		"api_url":                  configuration.APIURL,
		"config_url":               configuration.ConfigURL,
		"token":                    configuration.Token,
		"queue_path":               configuration.QueuePath,
		"interval_seconds":         configuration.IntervalSeconds,
		"verify_tls":               configuration.VerifyTLS,
		"collect_process_commands": configuration.CollectProcessCommands,
		"enabled":                  configuration.Enabled,
		"monitor_services":         configuration.MonitorServices,
		"queue_limit":              configuration.QueueLimit,
	} {
		encoded, err := json.Marshal(value)
		if err != nil {
			return fmt.Errorf("encode %s: %w", key, err)
		}
		values[key] = encoded
	}
	encoded, err := json.Marshal(values)
	if err != nil {
		return fmt.Errorf("encode config: %w", err)
	}
	return atomicfile.Write(path, encoded, 0600)
}

func requiredString(raw Raw, key string) (string, error) {
	value, ok := raw[key]
	if !ok {
		return "", fmt.Errorf("missing %s", key)
	}
	var decoded string
	if err := json.Unmarshal(value, &decoded); err != nil || decoded == "" {
		return "", fmt.Errorf("invalid %s", key)
	}
	return decoded, nil
}

func optionalInt(raw Raw, key string, fallback int) (int, error) {
	value, ok := raw[key]
	if !ok {
		return fallback, nil
	}
	var decoded int
	if err := json.Unmarshal(value, &decoded); err != nil {
		return 0, fmt.Errorf("invalid %s", key)
	}
	return decoded, nil
}

func optionalBool(raw Raw, key string, fallback bool) (bool, error) {
	value, ok := raw[key]
	if !ok {
		return fallback, nil
	}
	var decoded bool
	if err := json.Unmarshal(value, &decoded); err != nil {
		return false, fmt.Errorf("invalid %s", key)
	}
	return decoded, nil
}

func optionalStrings(raw Raw, key string) ([]string, error) {
	value, ok := raw[key]
	if !ok {
		return nil, nil
	}
	var decoded []string
	if err := json.Unmarshal(value, &decoded); err != nil || decoded == nil {
		return nil, fmt.Errorf("invalid %s", key)
	}
	return decoded, nil
}

func validateURL(value string) error {
	parsed, err := url.ParseRequestURI(value)
	if err != nil || parsed.Host == "" || parsed.User != nil {
		return errors.New("must be an absolute URL without credentials")
	}
	if parsed.Scheme == "https" {
		return nil
	}
	if parsed.Scheme == "http" && isLoopbackHost(parsed.Hostname()) {
		return nil
	}
	return errors.New("must use HTTPS")
}

func isLoopbackHost(host string) bool {
	if strings.EqualFold(host, "localhost") {
		return true
	}
	address := net.ParseIP(host)
	return address != nil && address.IsLoopback()
}
