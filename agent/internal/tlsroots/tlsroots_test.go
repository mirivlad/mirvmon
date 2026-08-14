package tlsroots

import (
	"crypto/tls"
	"crypto/x509"
	"encoding/pem"
	"testing"
)

func TestEmbeddedRootsAreValidAndTLS12OrNewer(t *testing.T) {
	configuration, err := TLSConfig()
	if err != nil {
		t.Fatal(err)
	}
	if configuration.MinVersion != tls.VersionTLS12 {
		t.Fatalf("unexpected minimum TLS version: %d", configuration.MinVersion)
	}
	if configuration.RootCAs == nil {
		t.Fatal("root pool is nil")
	}

	remaining := embeddedRoots
	commonNames := map[string]bool{}
	count := 0
	for len(remaining) > 0 {
		block, rest := pem.Decode(remaining)
		if block == nil {
			break
		}
		remaining = rest
		if block.Type != "CERTIFICATE" {
			continue
		}
		certificate, err := x509.ParseCertificate(block.Bytes)
		if err != nil {
			t.Fatal(err)
		}
		commonNames[certificate.Subject.CommonName] = true
		count++
	}
	if count != 2 {
		t.Fatalf("unexpected embedded root count: %d", count)
	}
	for _, name := range []string{"ISRG Root X1", "ISRG Root X2"} {
		if !commonNames[name] {
			t.Fatalf("missing embedded root %q", name)
		}
	}
}
