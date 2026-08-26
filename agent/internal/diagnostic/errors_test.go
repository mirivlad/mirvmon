package diagnostic

import (
	"context"
	"crypto/x509"
	"errors"
	"net"
	"testing"

	"github.com/mirivlad/mirvmon/agent/internal/transport"
)

func TestClassify(t *testing.T) {
	tests := []struct {
		name string
		err  error
		want string
	}{
		{"authentication", transport.ErrAuthentication, AuthenticationError},
		{"dns", &net.DNSError{Err: "no such host", Name: "monitor.example"}, DNSError},
		{"timeout", context.DeadlineExceeded, NetworkTimeout},
		{"network", &net.OpError{Op: "dial", Net: "tcp", Err: errors.New("refused")}, NetworkError},
		{"tls", x509.UnknownAuthorityError{}, TLSError},
		{"server status", transport.ErrUnexpectedConfig, ServerError},
		{"server metrics", transport.ErrUnexpectedMetrics, ServerError},
		{"configuration", transport.ErrCrossOriginRedirect, ConfigurationError},
		{"runtime", errors.New("disk queue broken"), RuntimeError},
	}
	for _, test := range tests {
		t.Run(test.name, func(t *testing.T) {
			if got := Classify(test.err); got != test.want {
				t.Fatalf("got %q want %q", got, test.want)
			}
		})
	}
}

func TestIsDeliveryPending(t *testing.T) {
	if !IsDeliveryPending(context.DeadlineExceeded) {
		t.Fatal("network timeout should be delivery pending")
	}
	if !IsDeliveryPending(transport.ErrUnexpectedConfig) {
		t.Fatal("server error should be delivery pending")
	}
	if IsDeliveryPending(errors.New("disk queue broken")) {
		t.Fatal("local runtime error must not be delivery pending")
	}
}

func TestSafeMessageRedactsCredentials(t *testing.T) {
	got := SafeMessage(errors.New("authorization: Bearer-secret token=abc password=hunter2"))
	if got == "" || got == "authorization: Bearer-secret token=abc password=hunter2" {
		t.Fatalf("message was not sanitized: %q", got)
	}
	for _, secret := range []string{"Bearer-secret", "abc", "hunter2"} {
		if contains(got, secret) {
			t.Fatalf("secret %q leaked in %q", secret, got)
		}
	}
}

func contains(value, part string) bool {
	for i := 0; i+len(part) <= len(value); i++ {
		if value[i:i+len(part)] == part {
			return true
		}
	}
	return false
}
