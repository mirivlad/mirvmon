package main

import (
	"context"
	"errors"
	"flag"
	"fmt"
	"io"
	"os"
	"runtime"
	"time"

	"github.com/mirivlad/mirvmon/agent/internal/buildinfo"
	"github.com/mirivlad/mirvmon/agent/internal/collector"
	"github.com/mirivlad/mirvmon/agent/internal/config"
	"github.com/mirivlad/mirvmon/agent/internal/protocol"
	"github.com/mirivlad/mirvmon/agent/internal/queue"
	"github.com/mirivlad/mirvmon/agent/internal/runner"
	"github.com/mirivlad/mirvmon/agent/internal/transport"
)

const (
	exitSuccess = 0
	exitRuntime = 1
	exitInvalid = 2
	exitPending = 3
)

func main() {
	os.Exit(execute(os.Args[1:], os.Stdout, os.Stderr))
}

func execute(arguments []string, stdout, stderr io.Writer) int {
	if len(arguments) == 0 {
		fmt.Fprintln(stderr, "usage: mirvmon-agent <run|check|once|version>")
		return exitInvalid
	}
	switch arguments[0] {
	case "version":
		if len(arguments) != 1 {
			fmt.Fprintln(stderr, "usage: mirvmon-agent version")
			return exitInvalid
		}
		fmt.Fprintf(stdout, "%s %s %s/%s\n", buildinfo.Version, buildinfo.Commit, runtime.GOOS, runtime.GOARCH)
		return exitSuccess
	case "run", "once", "check":
		return executeConfigured(arguments, stdout, stderr)
	default:
		fmt.Fprintln(stderr, "unknown command")
		return exitInvalid
	}
}

func executeConfigured(arguments []string, _ io.Writer, stderr io.Writer) int {
	command := arguments[0]
	flags := flag.NewFlagSet(command, flag.ContinueOnError)
	flags.SetOutput(io.Discard)
	configPath := flags.String("config", "", "configuration file")
	requireDelivery := flags.Bool("require-delivery", false, "require accepted delivery")
	checkServer := flags.Bool("server", false, "check authenticated server configuration")
	if err := flags.Parse(arguments[1:]); err != nil || *configPath == "" || flags.NArg() != 0 ||
		(command != "once" && *requireDelivery) || (command != "check" && *checkServer) {
		fmt.Fprintln(stderr, "invalid command arguments")
		return exitInvalid
	}
	configuration, _, err := config.Load(*configPath)
	if err != nil {
		fmt.Fprintln(stderr, "invalid configuration")
		return exitInvalid
	}
	persistentQueue, err := queue.Open(configuration.QueuePath, configuration.QueueLimit)
	if err != nil {
		fmt.Fprintln(stderr, "queue unavailable")
		return exitRuntime
	}
	api := transport.New(configuration)
	if command == "check" {
		// Construction itself is deliberately side-effect free. Collection is
		// reserved for run/once so installation preflight never emits metrics.
		_ = collector.New()
		if *checkServer {
			remote, err := api.PullConfig(context.Background())
			if err != nil {
				fmt.Fprintln(stderr, "server check failed")
				return exitPending
			}
			updated, ok := config.ApplyRemote(configuration, remote)
			if !ok || !updated.Enabled {
				fmt.Fprintln(stderr, "agent disabled by server")
				return exitPending
			}
		}
		return exitSuccess
	}
	agentRunner, err := runner.New(runner.Dependencies{
		Queue:     persistentQueue,
		API:       api,
		Collector: collector.New(),
		Config:    configuration,
		Version:   buildinfo.Version,
		Commit:    buildinfo.Commit,
		Now:       now,
		SampleID:  protocol.NewSampleID,
	})
	if err != nil {
		fmt.Fprintln(stderr, "agent initialization failed")
		return exitRuntime
	}
	if command == "once" {
		err = agentRunner.Once(context.Background(), *requireDelivery)
	} else {
		err = runner.RunService(context.Background(), agentRunner)
	}
	if err == nil {
		return exitSuccess
	}
	if errors.Is(err, runner.ErrDeliveryPending) || errors.Is(err, runner.ErrAuthentication) || errors.Is(err, runner.ErrDisabled) {
		fmt.Fprintln(stderr, "delivery pending")
		return exitPending
	}
	if errors.Is(err, context.Canceled) {
		return exitSuccess
	}
	fmt.Fprintln(stderr, "agent runtime failed")
	return exitRuntime
}

func now() time.Time { return time.Now() }
