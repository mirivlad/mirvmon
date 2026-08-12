package update

import (
	"context"
	"crypto/sha256"
	"encoding/hex"
	"errors"
	"fmt"
	"io"
	"net/http"
	"net/url"
	"os"
	"path/filepath"
)

var ErrChecksumMismatch = errors.New("update checksum mismatch")

// Downloader stages one fixed same-origin artifact and never sends credentials
// to the public binary endpoint.
type Downloader struct {
	ConfigURL string
	Artifact  string
	Client    *http.Client
}

func (downloader Downloader) Stage(
	context context.Context,
	command Command,
	destination string,
) (err error) {
	if err := command.Validate(); err != nil || command.Artifact != downloader.Artifact {
		return ErrInvalidCommand
	}
	base, err := url.Parse(downloader.ConfigURL)
	if err != nil || base.User != nil || (base.Scheme != "https" && base.Scheme != "http") {
		return ErrInvalidCommand
	}
	artifactURL := *base
	artifactURL.Path = "/agent/binaries/" + command.Artifact
	artifactURL.RawPath = ""
	artifactURL.RawQuery = ""
	artifactURL.Fragment = ""

	request, err := http.NewRequestWithContext(context, http.MethodGet, artifactURL.String(), nil)
	if err != nil {
		return fmt.Errorf("create update request: %w", err)
	}
	request.Header.Set("Accept", "application/octet-stream")
	client := downloader.Client
	if client == nil {
		client = http.DefaultClient
	}
	safeClient := *client
	safeClient.CheckRedirect = func(*http.Request, []*http.Request) error {
		return errors.New("update redirect refused")
	}
	response, err := safeClient.Do(request)
	if err != nil {
		return fmt.Errorf("download update: %w", err)
	}
	defer response.Body.Close()
	if response.StatusCode != http.StatusOK ||
		(response.ContentLength >= 0 && response.ContentLength != command.Size) {
		return errors.New("unexpected update response")
	}

	temporary, err := os.CreateTemp(filepath.Dir(destination), ".mirvmon-update-*")
	if err != nil {
		return fmt.Errorf("create staged update: %w", err)
	}
	temporaryPath := temporary.Name()
	defer func() {
		_ = temporary.Close()
		if err != nil {
			_ = os.Remove(temporaryPath)
		}
	}()
	hash := sha256.New()
	written, err := io.Copy(io.MultiWriter(temporary, hash), io.LimitReader(response.Body, command.Size+1))
	if err != nil {
		return fmt.Errorf("write staged update: %w", err)
	}
	if written != command.Size || hex.EncodeToString(hash.Sum(nil)) != command.SHA256 {
		return ErrChecksumMismatch
	}
	if err := temporary.Chmod(0700); err != nil {
		return fmt.Errorf("protect staged update: %w", err)
	}
	if err := temporary.Sync(); err != nil {
		return fmt.Errorf("sync staged update: %w", err)
	}
	if err := temporary.Close(); err != nil {
		return fmt.Errorf("close staged update: %w", err)
	}
	if err := os.Rename(temporaryPath, destination); err != nil {
		return fmt.Errorf("publish staged update: %w", err)
	}
	return nil
}
