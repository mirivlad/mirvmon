#!/usr/bin/env python3

import argparse
import os
import signal
import sys
import time
from pathlib import Path

from mirvmon_agent.client import ApiClient, DeliveryResult, build_envelope
from mirvmon_agent.collectors import ServiceChangeTracker, SystemCollector
from mirvmon_agent.config import AgentConfig
from mirvmon_agent.queue import PersistentQueue


def default_config_path():
    if os.name == "nt":
        return Path(os.environ.get("PROGRAMDATA", "C:\\ProgramData")) / "MirvMon" / "Agent" / "config.json"
    return Path("/etc/mirvmon-agent/config.json")


def run(config_path):
    config = AgentConfig.load(config_path)
    queue = PersistentQueue(config.queue_path, config.queue_limit)
    collector = SystemCollector()
    service_tracker = ServiceChangeTracker()
    running = True

    def stop(_signal, _frame):
        nonlocal running
        running = False

    signal.signal(signal.SIGTERM, stop)
    signal.signal(signal.SIGINT, stop)
    next_config_pull = 0.0
    failed_attempts = 0

    while running:
        client = ApiClient(config)
        if time.monotonic() >= next_config_pull:
            config = config.apply_remote(client.pull_config())
            client = ApiClient(config)
            next_config_pull = time.monotonic() + 300

        if config.enabled:
            try:
                services = service_tracker.changed(collector.services())
                envelope = build_envelope(
                    config.token,
                    collector.metrics(),
                    services,
                    collector.process_snapshot(config.collect_process_commands),
                )
                queue.append(envelope)
            except Exception as exception:
                print(
                    f"collection_failed:{exception.__class__.__name__}",
                    file=sys.stderr,
                    flush=True,
                )

            retry = False
            while running and queue.peek() is not None:
                result = client.send(queue.peek() or {})
                if result in (DeliveryResult.ACCEPTED, DeliveryResult.DISCARD):
                    queue.pop()
                    failed_attempts = 0
                    continue
                failed_attempts += 1
                retry = True
                break

            delay = (
                client.retry_delay(failed_attempts)
                if retry
                else config.interval_seconds
            )
        else:
            delay = min(config.interval_seconds, 60)

        deadline = time.monotonic() + delay
        while running and time.monotonic() < deadline:
            time.sleep(min(1.0, deadline - time.monotonic()))

    return 0


def check(config_path):
    """Validate an installed agent without collecting or sending a sample."""
    config = AgentConfig.load(config_path)
    PersistentQueue(config.queue_path, config.queue_limit)
    SystemCollector()
    ServiceChangeTracker()
    return 0


def main() -> int:
    parser = argparse.ArgumentParser(description="MirvMon outbound monitoring agent")
    parser.add_argument("--config", type=Path, default=default_config_path())
    parser.add_argument("--check", action="store_true")
    arguments = parser.parse_args()
    try:
        return check(arguments.config) if arguments.check else run(arguments.config)
    except Exception as exception:
        print(
            f"agent_start_failed:{exception.__class__.__name__}",
            file=sys.stderr,
        )
        return 1


if __name__ == "__main__":
    raise SystemExit(main())
