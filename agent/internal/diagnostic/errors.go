// Package diagnostic classifies agent failures into stable operator-facing states.
package diagnostic

import (
	"context"
	"crypto/tls"
	"crypto/x509"
	"errors"
	"net"
	"net/url"
	"os"
	"regexp"
	"strings"

	"github.com/mirivlad/mirvmon/agent/internal/transport"
)

const (
	AuthenticationError = "authentication_error"
	DNSError            = "dns_error"
	NetworkTimeout      = "network_timeout"
	NetworkError        = "network_error"
	TLSError            = "tls_error"
	ServerError         = "server_error"
	ConfigurationError  = "configuration_error"
	RuntimeError        = "runtime_error"
)

var sensitiveErrorValue = regexp.MustCompile(`(?i)(token|authorization|password|secret)(?:\s*(?:=|:)\s*|\s+)[^\s]+`)

// Classify returns a stable, secret-free category suitable for health.json and CLI diagnostics.
func Classify(err error) string {
	if err == nil {
		return ""
	}
	if errors.Is(err, transport.ErrAuthentication) {
		return AuthenticationError
	}
	if errors.Is(err, transport.ErrUnexpectedConfig) ||
		errors.Is(err, transport.ErrUnexpectedMetrics) ||
		errors.Is(err, transport.ErrInvalidRemoteConfig) ||
		errors.Is(err, transport.ErrResponseTooLarge) {
		return ServerError
	}
	if errors.Is(err, transport.ErrCrossOriginRedirect) ||
		errors.Is(err, transport.ErrTooManyRedirects) ||
		errors.Is(err, transport.ErrInvalidTLSConfiguration) {
		return ConfigurationError
	}

	var dnsErr *net.DNSError
	if errors.As(err, &dnsErr) {
		return DNSError
	}
	if isTLSError(err) {
		return TLSError
	}
	if errors.Is(err, context.DeadlineExceeded) || os.IsTimeout(err) {
		return NetworkTimeout
	}
	var netErr net.Error
	if errors.As(err, &netErr) {
		if netErr.Timeout() {
			return NetworkTimeout
		}
		return NetworkError
	}
	var urlErr *url.Error
	if errors.As(err, &urlErr) {
		return NetworkError
	}
	return RuntimeError
}

// IsDeliveryPending reports failures that prevent communication with the server
// but do not represent a local agent runtime failure.
func IsDeliveryPending(err error) bool {
	switch Classify(err) {
	case AuthenticationError, DNSError, NetworkTimeout, NetworkError, TLSError, ServerError:
		return true
	default:
		return false
	}
}

// SafeMessage removes common credential-shaped values before showing an error to an operator.
func SafeMessage(err error) string {
	if err == nil {
		return ""
	}
	return sensitiveErrorValue.ReplaceAllString(err.Error(), "$1=[redacted]")
}

func isTLSError(err error) bool {
	var unknownAuthority x509.UnknownAuthorityError
	var hostnameError x509.HostnameError
	var certificateInvalid x509.CertificateInvalidError
	var recordHeader tls.RecordHeaderError
	if errors.As(err, &unknownAuthority) || errors.As(err, &hostnameError) ||
		errors.As(err, &certificateInvalid) || errors.As(err, &recordHeader) {
		return true
	}
	message := strings.ToLower(err.Error())
	return strings.Contains(message, "x509:") || strings.Contains(message, "tls:")
}
