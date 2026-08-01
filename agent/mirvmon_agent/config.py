import json
import os
from pathlib import Path
from urllib.parse import urlparse


class AgentConfig(object):
    """Immutable validated agent configuration with only the standard library."""

    __slots__ = (
        "api_url",
        "config_url",
        "token",
        "queue_path",
        "interval_seconds",
        "verify_tls",
        "collect_process_commands",
        "enabled",
        "monitor_services",
        "queue_limit",
    )

    def __setattr__(self, name, value):
        if hasattr(self, name):
            raise AttributeError("Agent configuration is immutable.")
        object.__setattr__(self, name, value)

    def __init__(
        self,
        api_url,
        config_url,
        token,
        queue_path,
        interval_seconds=60,
        verify_tls=True,
        collect_process_commands=False,
        enabled=True,
        monitor_services=(),
        queue_limit=1000,
    ):
        self.api_url = api_url
        self.config_url = config_url
        self.token = token
        self.queue_path = queue_path
        self.interval_seconds = interval_seconds
        self.verify_tls = verify_tls
        self.collect_process_commands = collect_process_commands
        self.enabled = enabled
        self.monitor_services = monitor_services
        self.queue_limit = queue_limit

    @classmethod
    def load(cls, path):
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

    def validate(self):
        for value in (self.api_url, self.config_url):
            parsed = urlparse(value)
            local_http = parsed.scheme == "http" and parsed.hostname in {
                "localhost", "127.0.0.1", "::1"
            }
            if (
                (parsed.scheme != "https" and not local_http)
                or not parsed.hostname
                or parsed.username
                or parsed.password
            ):
                raise ValueError("Agent URLs must use HTTPS.")
        if len(self.token) < 32 or len(self.token) > 512:
            raise ValueError("Invalid agent token.")
        if not 10 <= self.interval_seconds <= 86400:
            raise ValueError("Invalid collection interval.")
        if not 1 <= self.queue_limit <= 10000:
            raise ValueError("Invalid queue limit.")
        if not str(self.queue_path):
            raise ValueError("Queue path is required.")

    def apply_remote(self, payload):
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

        return AgentConfig(
            self.api_url,
            self.config_url,
            self.token,
            self.queue_path,
            interval_seconds=interval,
            verify_tls=self.verify_tls,
            collect_process_commands=self.collect_process_commands,
            enabled=enabled,
            monitor_services=tuple(services),
            queue_limit=self.queue_limit,
        )
