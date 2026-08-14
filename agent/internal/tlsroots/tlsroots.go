// Package tlsroots provides the agent's TLS trust configuration.
//
// Current system roots are retained when available, and a pinned Mozilla
// server trust store is appended so legacy hosts with stale CA stores can
// authenticate public MirvMon HTTPS endpoints without disabling verification.
package tlsroots

import (
	"crypto/tls"
	"crypto/x509"
	"errors"
	"fmt"
	"net/http"

	rootcerts "github.com/gwatts/rootcerts"
)

var errInvalidEmbeddedRoots = errors.New("embedded TLS root bundle is empty")

// TLSConfig returns a TLS 1.2+ configuration backed by the host trust store
// plus the embedded Mozilla server-trusted roots. If the platform trust store
// cannot be loaded, the embedded roots remain sufficient for public HTTPS.
func TLSConfig() (*tls.Config, error) {
	roots, err := x509.SystemCertPool()
	if err != nil || roots == nil {
		roots = x509.NewCertPool()
	}

	embedded := rootcerts.CertsByTrust(rootcerts.ServerTrustedDelegator)
	if len(embedded) == 0 {
		return nil, errInvalidEmbeddedRoots
	}
	for _, root := range embedded {
		certificate, err := x509.ParseCertificate(root.DER)
		if err != nil {
			return nil, fmt.Errorf("parse embedded TLS root %q: %w", root.Label, err)
		}
		roots.AddCert(certificate)
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
