package tlsroots

import (
	"crypto/tls"
	"testing"

	rootcerts "github.com/gwatts/rootcerts"
)

func TestEmbeddedRootsAreValidAndTLS12OrNewer(t *testing.T) {
	embedded := rootcerts.CertsByTrust(rootcerts.ServerTrustedDelegator)
	if len(embedded) < 100 {
		t.Fatalf("unexpectedly small embedded server trust store: %d", len(embedded))
	}
	foundISRG := false
	for _, certificate := range embedded {
		if certificate.Label == "ISRG Root X1" {
			foundISRG = true
			break
		}
	}
	if !foundISRG {
		t.Fatal("embedded Mozilla trust store is missing ISRG Root X1")
	}

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
}
