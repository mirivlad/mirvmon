from __future__ import annotations

import json
import os
from dataclasses import dataclass, replace
from pathlib import Path
from typing import Any
from urllib.parse import urlparse


@dataclass(frozen=True)
class AgentConfig:
    api_url: str
    config_url: str
    token: str
    queue_path: Path
    interval_seconds: int = 60
    verify_tls: bool = True
    collect_process_commands: bool = False
    enabled: bool = True
    monitor_services: tuple[str, ...] = ()
    queue_limit: int = 1000

    @classmethod
    def load(cls, path: Path) -> "AgentConfig":
        data = json.loads(path.read_text(encoding="utf-8-sig"))
        if not isinstance(data, dict):
            raise ValueError("Agent configuration must be an object.")

        queue_value = os.path.expandvars(str(data.get("queue_path", "")))
        config = cls(
            api_url=str(data.get("api_url", "")),
            config_url=str(data.get("config_url", "")),
            token=str(data.get("token", "")),
            queue_path=Path(queue_value),
            interval_seconds=int(data.get("interval_seconds", 60)),
            verify_tls=data.get("verify_tls", True) is True,
            collect_process_commands=data.get("collect_process_commands", False) is True,
            queue_limit=int(data.get("queue_limit", 1000)),
        )
        config.validate()
        return config

    def validate(self) -> None:
        for value in (self.api_url, self.config_url):
            parsed = urlparse(value)
            local_http = parsed.scheme == "http" and parsed.hostname in {
                "localhost",
                "127.0.0.1",
                "::1",
            }
            if (
                parsed.scheme != "https"
                and not local_http
            ) or not parsed.hostname or parsed.username or parsed.password:
                raise ValueError("Agent URLs must use HTTPS.")
        if len(self.token) < 32 or len(self.token) > 512:
            raise ValueError("Invalid agent token.")
        if not 10 <= self.interval_seconds <= 86400:
            raise ValueError("Invalid collection interval.")
        if not 1 <= self.queue_limit <= 10000:
            raise ValueError("Invalid queue limit.")
        if not str(self.queue_path):
            raise ValueError("Queue path is required.")

    def apply_remote(self, payload: dict[str, Any]) -> "AgentConfig":
        interval = payload.get("interval_seconds", self.interval_seconds)
        enabled = payload.get("enabled", self.enabled)
        services = payload.get("monitor_services", list(self.monitor_services))
        if (
            not isinstance(interval, int)
            or not 10 <= interval <= 86400
            or not isinstance(enabled, bool)
            or not isinstance(services, list)
            or len(services) > 500
            or any(not isinstance(item, str) for item in services)
        ):
            return self

        return replace(
            self,
            interval_seconds=interval,
            enabled=enabled,
            monitor_services=tuple(services),
        )
