from __future__ import annotations

import uuid
from datetime import datetime, timezone
from enum import Enum
from typing import Any

import requests

from . import __version__
from .config import AgentConfig


class DeliveryResult(Enum):
    ACCEPTED = "accepted"
    DISCARD = "discard"
    RETRY = "retry"


class ApiClient:
    def __init__(
        self,
        config: AgentConfig,
        session: requests.Session | Any | None = None,
    ) -> None:
        self.config = config
        self.session = session or requests.Session()
        self.session.trust_env = True

    def send(self, envelope: dict[str, Any]) -> DeliveryResult:
        try:
            response = self.session.post(
                self.config.api_url,
                json=envelope,
                timeout=(5, 15),
                verify=self.config.verify_tls,
                headers={"Accept": "application/json"},
            )
        except requests.RequestException:
            return DeliveryResult.RETRY

        if response.status_code in (200, 202):
            return DeliveryResult.ACCEPTED
        if response.status_code in (400, 413, 422):
            return DeliveryResult.DISCARD
        return DeliveryResult.RETRY

    def pull_config(self) -> dict[str, Any]:
        try:
            response = self.session.get(
                self.config.config_url,
                timeout=(5, 15),
                verify=self.config.verify_tls,
                headers={
                    "Accept": "application/json",
                    "Authorization": f"Bearer {self.config.token}",
                },
            )
        except requests.RequestException:
            return {}

        if response.status_code != 200:
            return {}
        try:
            payload = response.json()
        except (ValueError, TypeError):
            return {}
        return payload if isinstance(payload, dict) else {}

    @staticmethod
    def retry_delay(attempt: int) -> int:
        return min(300, 2 ** max(0, min(attempt, 9)))


def build_envelope(
    token: str,
    metrics: dict[str, float],
    services: list[dict[str, str]],
    process_snapshot: dict[str, list[dict[str, Any]]] | None,
    now: datetime | None = None,
) -> dict[str, Any]:
    timestamp = now or datetime.now(timezone.utc)
    timestamp = timestamp.astimezone(timezone.utc).replace(microsecond=0)
    envelope: dict[str, Any] = {
        "version": 2,
        "agent_version": __version__,
        "sample_id": str(uuid.uuid4()),
        "sample_time": timestamp.isoformat().replace("+00:00", "Z"),
        "token": token,
        "metrics": metrics,
    }
    if services:
        envelope["services"] = services
    if process_snapshot is not None:
        envelope["process_snapshot"] = process_snapshot
    return envelope
