// Package tlsroots provides the agent's TLS trust configuration.
//
// Current system roots are retained when available, and MirvMon's embedded
// fallback roots are appended so legacy hosts with stale CA stores can still
// authenticate the MirvMon HTTPS endpoint without disabling verification.
package tlsroots

import (
	"crypto/tls"
	"crypto/x509"
	_ "embed"
	"errors"
	"net/http"
)

var errInvalidEmbeddedRoots = errors.New("invalid embedded TLS root bundle")

//go:embed roots.pem
var embeddedRoots []byte

// TLSConfig returns a TLS 1.2+ configuration backed by the host trust store
// plus MirvMon's embedded fallback roots. If the platform trust store cannot be
// loaded, the embedded roots remain sufficient for supported MirvMon HTTPS
// deployments.
func TLSConfig() (*tls.Config, error) {
	roots, err := x509.SystemCertPool()
	if err != nil || roots == nil {
		roots = x509.NewCertPool()
	}
	if !roots.AppendCertsFromPEM(embeddedRoots) {
		return nil, errInvalidEmbeddedRoots
	}
	return &tls.Config{
		MinVersion: tls.VersionTLS12,
		RootCAs:    roots,
	}, nil
}

// Transport clones Go's bounded default transport and replaces only its TLS
// trust configuration, preserving proxy support and sensible connection
// timeouts.
func Transport() (*http.Transport, error) {
	configuration, err := TLSConfig()
	if err != nil {
		return nil, err
	}
	base, ok := http.DefaultTransport.(*http.Transport)
	if !ok {
		return nil, errors.New("default HTTP transport is unavailable")
	}
	transport := base.Clone()
	transport.Proxy = http.ProxyFromEnvironment
	transport.TLSClientConfig = configuration
	return transport, nil
}
