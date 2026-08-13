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
	markerVisible := false
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
			_, err := os.Stat(filepath.Join(directory, "update-handoff"))
			markerVisible = err == nil
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
	if !markerVisible {
		t.Fatal("handoff started before durable marker was published")
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

func TestManagerRemovesRequestWhenHandoffMarkerCannotBePublished(t *testing.T) {
	payload := []byte("v0.4.3-agent")
	digest := sha256.Sum256(payload)
	server := httptest.NewTLSServer(http.HandlerFunc(func(response http.ResponseWriter, request *http.Request) {
		response.Write(payload)
	}))
	defer server.Close()
	directory := t.TempDir()
	if err := os.Mkdir(filepath.Join(directory, "update-handoff"), 0700); err != nil {
		t.Fatal(err)
	}
	command := testCommand()
	command.SHA256 = hex.EncodeToString(digest[:])
	command.Size = int64(len(payload))
	manager := Manager{
		Store:            NewStore(filepath.Join(directory, "queue.json")),
		Downloader:       Downloader{ConfigURL: server.URL + "/api/v1/agent/config", Artifact: "linux-amd64", Client: server.Client()},
		InstalledVersion: "v0.4.2",
		Artifact:         "linux-amd64",
	}
	if err := manager.Process(context.Background(), command, func(context.Context, Command, string, string) error { return nil }); err == nil {
		t.Fatal("expected marker write failure")
	}
	if _, err := os.Stat(filepath.Join(directory, "update-request.json")); !os.IsNotExist(err) {
		t.Fatalf("request remains after marker failure: %v", err)
	}
}

func TestManagerAllowsMetricsWhenCommandTargetIsAlreadyInstalled(t *testing.T) {
	directory := t.TempDir()
	command := testCommand()
	store := NewStore(filepath.Join(directory, "queue.json"))
	if _, accepted, err := store.Accept(command); err != nil || !accepted {
		t.Fatalf("accept=%v err=%v", accepted, err)
	}
	if err := store.Advance(command.ID, StateDownloading, ""); err != nil {
		t.Fatal(err)
	}
	if err := store.Advance(command.ID, StateInstalling, ""); err != nil {
		t.Fatal(err)
	}
	if err := store.Advance(command.ID, StateAwaitingRestart, ""); err != nil {
		t.Fatal(err)
	}
	manager := Manager{
		Store:            store,
		InstalledVersion: command.TargetVersion,
		Artifact:         command.Artifact,
	}
	reports := 0

	if err := manager.Process(context.Background(), command, func(context.Context, Command, string, string) error {
		reports++
		return nil
	}); err != nil {
		t.Fatal(err)
	}
	state, err := store.Read()
	if err != nil {
		t.Fatal(err)
	}
	if state.State != StateSucceeded {
		t.Fatalf("state=%q want=%q", state.State, StateSucceeded)
	}
	if reports != 0 {
		t.Fatalf("reports=%d, already installed command must wait for metrics reconciliation", reports)
	}
}

func TestManagerReconcilesPreviousLocalCommandBeforeNextUpdate(t *testing.T) {
	directory := t.TempDir()
	previous := testCommand()
	store := NewStore(filepath.Join(directory, "queue.json"))
	if _, accepted, err := store.Accept(previous); err != nil || !accepted {
		t.Fatalf("accept=%v err=%v", accepted, err)
	}
	for _, state := range []string{StateDownloading, StateInstalling, StateAwaitingRestart} {
		if err := store.Advance(previous.ID, state, ""); err != nil {
			t.Fatal(err)
		}
	}
	next := previous
	next.ID = "30000000-0000-4000-8000-000000000003"
	next.TargetVersion = "v0.4.4"
	payload := []byte("v0.4.4-agent")
	digest := sha256.Sum256(payload)
	next.SHA256 = hex.EncodeToString(digest[:])
	next.Size = int64(len(payload))
	server := httptest.NewTLSServer(http.HandlerFunc(func(response http.ResponseWriter, request *http.Request) {
		_, _ = response.Write(payload)
	}))
	defer server.Close()
	handoffs := 0
	manager := Manager{
		Store: store,
		Downloader: Downloader{
			ConfigURL: server.URL + "/api/v1/agent/config",
			Artifact:  next.Artifact,
			Client:    server.Client(),
		},
		InstalledVersion: previous.TargetVersion,
		Artifact:         next.Artifact,
		Handoff:          func(string) error { handoffs++; return nil },
	}

	if err := manager.Process(context.Background(), next, func(context.Context, Command, string, string) error { return nil }); err != nil {
		t.Fatal(err)
	}
	if handoffs != 1 {
		t.Fatalf("handoffs=%d", handoffs)
	}
	state, err := store.Read()
	if err != nil {
		t.Fatal(err)
	}
	if state.Command.ID != next.ID || state.State != StateInstalling {
		t.Fatalf("state=%#v", state)
	}
}
