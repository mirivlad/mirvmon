package main

import (
	"bytes"
	"strings"
	"testing"
)

func TestExecuteVersionDoesNotExposeConfiguration(t *testing.T) {
	var stdout, stderr bytes.Buffer
	code := execute([]string{"version"}, &stdout, &stderr)
	if code != exitSuccess {
		t.Fatalf("exit=%d stderr=%s", code, stderr.String())
	}
	if !strings.Contains(stdout.String(), "dev unknown") || strings.Contains(stdout.String(), "token") {
		t.Fatalf("unexpected version output: %q", stdout.String())
	}
}

func TestExecuteRejectsUnknownCommandWithoutLeakingArguments(t *testing.T) {
	var stdout, stderr bytes.Buffer
	code := execute([]string{"invalid", "aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa"}, &stdout, &stderr)
	if code != exitInvalid {
		t.Fatalf("exit=%d stderr=%s", code, stderr.String())
	}
	if strings.Contains(stderr.String(), "aaaaaaaa") {
		t.Fatalf("invalid arguments leaked: %q", stderr.String())
	}
}
