package update

import (
	"context"
	"crypto/sha256"
	"encoding/hex"
	"errors"
	"net/http"
	"net/http/httptest"
	"os"
	"path/filepath"
	"testing"
)

func TestDownloaderUsesFixedArtifactEndpointAndVerifiesChecksum(t *testing.T) {
	payload := []byte("new-agent")
	digest := sha256.Sum256(payload)
	server := httptest.NewTLSServer(http.HandlerFunc(func(response http.ResponseWriter, request *http.Request) {
		if request.URL.Path != "/agent/binaries/linux-amd64" {
			t.Fatalf("unexpected path %q", request.URL.Path)
		}
		response.Write(payload)
	}))
	defer server.Close()

	command := testCommand()
	command.SHA256 = hex.EncodeToString(digest[:])
	command.Size = int64(len(payload))
	destination := filepath.Join(t.TempDir(), "staged-agent")
	downloader := Downloader{
		ConfigURL: server.URL + "/api/v1/agent/config",
		Artifact:  "linux-amd64",
		Client:    server.Client(),
	}
	if err := downloader.Stage(context.Background(), command, destination); err != nil {
		t.Fatal(err)
	}
	contents, err := os.ReadFile(destination)
	if err != nil || string(contents) != string(payload) {
		t.Fatalf("contents=%q err=%v", contents, err)
	}
}

func TestDownloaderRejectsWrongArtifactAndChecksumWithoutDestination(t *testing.T) {
	server := httptest.NewTLSServer(http.HandlerFunc(func(response http.ResponseWriter, request *http.Request) {
		response.Write([]byte("tampered"))
	}))
	defer server.Close()
	destination := filepath.Join(t.TempDir(), "staged-agent")
	downloader := Downloader{
		ConfigURL: server.URL + "/api/v1/agent/config",
		Artifact:  "linux-amd64",
		Client:    server.Client(),
	}
	command := testCommand()
	command.Artifact = "windows-amd64"
	if err := downloader.Stage(context.Background(), command, destination); !errors.Is(err, ErrInvalidCommand) {
		t.Fatalf("wrong artifact: %v", err)
	}
	command.Artifact = "linux-amd64"
	command.Size = int64(len("tampered"))
	if err := downloader.Stage(context.Background(), command, destination); !errors.Is(err, ErrChecksumMismatch) {
		t.Fatalf("wrong checksum: %v", err)
	}
	if _, err := os.Stat(destination); !os.IsNotExist(err) {
		t.Fatalf("destination exists after failure: %v", err)
	}
}
