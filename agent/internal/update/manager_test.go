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
	"reflect"
	"testing"
)

func TestManagerStagesOnceReportsProgressAndPublishesFixedRequest(t *testing.T) {
	payload := []byte("v0.4.3-agent")
	digest := sha256.Sum256(payload)
	server := httptest.NewTLSServer(http.HandlerFunc(func(response http.ResponseWriter, request *http.Request) {
		response.Write(payload)
	}))
	defer server.Close()
	directory := t.TempDir()
	queuePath := filepath.Join(directory, "queue.json")
	command := testCommand()
	command.SHA256 = hex.EncodeToString(digest[:])
	command.Size = int64(len(payload))
	states := []string{}
	handoffs := 0
	manager := Manager{
		Store: NewStore(queuePath),
		Downloader: Downloader{
			ConfigURL: server.URL + "/api/v1/agent/config",
			Artifact:  "linux-amd64",
			Client:    server.Client(),
		},
		InstalledVersion: "v0.4.2",
		Artifact:         "linux-amd64",
		Handoff: func(string) error {
			handoffs++
			return nil
		},
	}
	report := func(_ context.Context, _ Command, state, _ string) error {
		states = append(states, state)
		return nil
	}
	if err := manager.Process(context.Background(), command, report); err != nil {
		t.Fatal(err)
	}
	if !reflect.DeepEqual(states, []string{StateAccepted, StateDownloading, StateInstalling}) {
		t.Fatalf("states=%v", states)
	}
	if handoffs != 1 {
		t.Fatalf("handoffs=%d", handoffs)
	}
	if _, err := os.Stat(filepath.Join(directory, "update-request.json")); err != nil {
		t.Fatal(err)
	}
	if err := manager.Process(context.Background(), command, report); err != nil {
		t.Fatal(err)
	}
	if handoffs != 1 {
		t.Fatalf("replay handoffs=%d", handoffs)
	}
}

func TestManagerResumesInstallingStateAfterProgressReportFailure(t *testing.T) {
	payload := []byte("v0.4.3-agent")
	digest := sha256.Sum256(payload)
	server := httptest.NewTLSServer(http.HandlerFunc(func(response http.ResponseWriter, request *http.Request) {
		response.Write(payload)
	}))
	defer server.Close()
	directory := t.TempDir()
	command := testCommand()
	command.SHA256 = hex.EncodeToString(digest[:])
	command.Size = int64(len(payload))
	handoffs := 0
	manager := Manager{
		Store:            NewStore(filepath.Join(directory, "queue.json")),
		Downloader:       Downloader{ConfigURL: server.URL + "/api/v1/agent/config", Artifact: "linux-amd64", Client: server.Client()},
		InstalledVersion: "v0.4.2",
		Artifact:         "linux-amd64",
		Handoff:          func(string) error { handoffs++; return nil },
	}
	reportFailed := true
	report := func(_ context.Context, _ Command, state, _ string) error {
		if state == StateInstalling && reportFailed {
			reportFailed = false
			return errors.New("temporary report failure")
		}
		return nil
	}
	if err := manager.Process(context.Background(), command, report); err == nil {
		t.Fatal("expected report failure")
	}
	if handoffs != 0 {
		t.Fatalf("handoffs after failure=%d", handoffs)
	}
	if err := manager.Process(context.Background(), command, report); err != nil {
		t.Fatal(err)
	}
	if handoffs != 1 {
		t.Fatalf("handoffs after resume=%d", handoffs)
	}
}
