import json
import tempfile
import unittest
from datetime import datetime, timezone
from pathlib import Path

from mirvmon_agent.client import ApiClient, DeliveryResult, build_envelope
from mirvmon_agent.config import AgentConfig


class FakeResponse:
    def __init__(self, status_code, payload=None):
        self.status_code = status_code
        self._payload = payload or {}

    def json(self):
        return self._payload


class FakeSession:
    def __init__(self):
        self.trust_env = False
        self.calls = []
        self.responses = []

    def post(self, url, **kwargs):
        self.calls.append(("POST", url, kwargs))
        return self.responses.pop(0)

    def get(self, url, **kwargs):
        self.calls.append(("GET", url, kwargs))
        return self.responses.pop(0)


class ClientTest(unittest.TestCase):
    def config(self):
        return AgentConfig(
            api_url="https://monitor.example/api/v1/metrics",
            config_url="https://monitor.example/api/v1/agent/config",
            token="a" * 64,
            queue_path=Path("/tmp/mirvmon-queue.json"),
        )

    def test_session_uses_environment_proxy_and_tls_verification(self):
        session = FakeSession()
        session.responses.append(FakeResponse(202))
        client = ApiClient(self.config(), session=session)

        result = client.send({"version": 2})

        self.assertEqual(DeliveryResult.ACCEPTED, result)
        self.assertTrue(session.trust_env)
        self.assertTrue(session.calls[0][2]["verify"])
        self.assertEqual((5, 15), session.calls[0][2]["timeout"])

    def test_permanent_bad_sample_is_discarded_but_auth_failure_is_retried(self):
        session = FakeSession()
        session.responses.extend([FakeResponse(422), FakeResponse(401)])
        client = ApiClient(self.config(), session=session)

        self.assertEqual(DeliveryResult.DISCARD, client.send({}))
        self.assertEqual(DeliveryResult.RETRY, client.send({}))
        self.assertEqual(300, client.retry_delay(20))

    def test_config_is_pulled_with_bearer_token(self):
        session = FakeSession()
        session.responses.append(FakeResponse(200, {
            "enabled": True,
            "interval_seconds": 30,
            "monitor_services": ["postgresql.service"],
        }))
        client = ApiClient(self.config(), session=session)

        config = client.pull_config()

        self.assertEqual(30, config["interval_seconds"])
        self.assertEqual(
            "Bearer " + "a" * 64,
            session.calls[0][2]["headers"]["Authorization"],
        )

    def test_envelope_contains_v2_identity_and_utc_time(self):
        envelope = build_envelope(
            "a" * 64,
            {"cpu_load": 10.0},
            [],
            None,
            now=datetime(2026, 7, 30, 12, 0, tzinfo=timezone.utc),
        )

        self.assertEqual(2, envelope["version"])
        self.assertRegex(
            envelope["sample_id"],
            r"^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$",
        )
        self.assertEqual("2026-07-30T12:00:00Z", envelope["sample_time"])
        self.assertEqual("a" * 64, envelope["token"])


if __name__ == "__main__":
    unittest.main()
