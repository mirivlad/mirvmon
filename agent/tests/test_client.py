import unittest
import os
import ssl
from datetime import datetime, timezone
from pathlib import Path
from urllib.error import URLError
from urllib.request import HTTPSHandler, ProxyHandler, Request
from unittest import mock

from mirvmon_agent.client import (
    ApiClient,
    DeliveryResult,
    HttpResponse,
    HttpTransport,
    _SameOriginRedirectHandler,
    build_envelope,
)
from mirvmon_agent.config import AgentConfig


class FakeTransport:
    def __init__(self):
        self.calls = []
        self.responses = []

    def request(self, method, url, payload, headers):
        self.calls.append((method, url, payload, headers))
        return self.responses.pop(0)


class ClientTest(unittest.TestCase):
    def config(self):
        return AgentConfig(
            api_url="https://monitor.example/api/v1/metrics",
            config_url="https://monitor.example/api/v1/agent/config",
            token="a" * 64,
            queue_path=Path("/tmp/mirvmon-queue.json"),
        )

    def test_post_uses_standard_transport_and_classifies_accepted_response(self):
        transport = FakeTransport()
        transport.responses.append(HttpResponse(202, b"{}"))
        client = ApiClient(self.config(), transport=transport)

        result = client.send({"version": 2})

        self.assertEqual(DeliveryResult.ACCEPTED, result)
        self.assertEqual("POST", transport.calls[0][0])
        self.assertEqual(b'{"version":2}', transport.calls[0][2])
        self.assertNotIn("Authorization", transport.calls[0][3])

    def test_permanent_bad_sample_is_discarded_but_auth_failure_is_retried(self):
        transport = FakeTransport()
        transport.responses.extend([HttpResponse(422, b""), HttpResponse(401, b"")])
        client = ApiClient(self.config(), transport=transport)

        self.assertEqual(DeliveryResult.DISCARD, client.send({}))
        self.assertEqual(DeliveryResult.RETRY, client.send({}))
        self.assertEqual(300, client.retry_delay(20))

    def test_config_is_pulled_with_bearer_token(self):
        transport = FakeTransport()
        transport.responses.append(HttpResponse(
            200,
            b'{"enabled":true,"interval_seconds":30,"monitor_services":["postgresql.service"]}',
        ))
        client = ApiClient(self.config(), transport=transport)

        config = client.pull_config()

        self.assertEqual(30, config["interval_seconds"])
        self.assertEqual(
            "Bearer " + "a" * 64,
            transport.calls[0][3]["Authorization"],
        )

    def test_transport_treats_timeout_and_network_errors_as_retryable(self):
        class FailingOpener:
            def open(self, request, timeout):
                raise URLError("timed out")

        transport = HttpTransport(opener=FailingOpener())

        with self.assertRaises(URLError):
            transport.request("GET", "https://monitor.example/config", None, {})

    def test_transport_does_not_follow_cross_origin_redirects(self):
        transport = HttpTransport()

        self.assertFalse(
            transport.is_same_origin(
                "https://monitor.example/api/v1/agent/config",
                "https://other.example/config",
            )
        )
        self.assertTrue(
            transport.is_same_origin(
                "https://monitor.example/api/v1/agent/config",
                "https://monitor.example/next",
            )
        )

        redirect = _SameOriginRedirectHandler()
        request = Request("https://monitor.example/api/v1/agent/config")
        self.assertIsNone(
            redirect.redirect_request(
                request, None, 302, "Found", {}, "https://other.example/config"
            )
        )

    def test_transport_uses_system_tls_verification_and_proxy_environment(self):
        with mock.patch.dict(
            os.environ,
            {"HTTPS_PROXY": "http://proxy.example:3128", "NO_PROXY": ""},
            clear=True,
        ):
            transport = HttpTransport()

        handlers = transport._opener.handlers
        https = next(handler for handler in handlers if isinstance(handler, HTTPSHandler))
        proxy = next(handler for handler in handlers if isinstance(handler, ProxyHandler))
        self.assertEqual(ssl.CERT_REQUIRED, https._context.verify_mode)
        self.assertTrue(https._context.check_hostname)
        self.assertEqual("http://proxy.example:3128", proxy.proxies["https"])

    def test_transport_enforces_timeout_and_response_size_limit(self):
        class Response:
            def getcode(self):
                return 200

            def read(self, length):
                self.length = length
                return b"x" * length

        class RecordingOpener:
            def __init__(self):
                self.response = Response()

            def open(self, request, timeout):
                self.request = request
                self.timeout = timeout
                return self.response

        opener = RecordingOpener()
        transport = HttpTransport(opener=opener, timeout_seconds=12, max_response_bytes=8)
        response = transport.request("POST", "https://monitor.example/metrics", b"{}", {})

        self.assertEqual(12, opener.timeout)
        self.assertEqual("POST", opener.request.get_method())
        self.assertEqual("application/json", opener.request.get_header("Content-type"))
        self.assertEqual(9, opener.response.length)
        self.assertEqual(b"x" * 8, response.body)

    def test_agent_source_has_no_requests_dependency_or_python37_syntax(self):
        root = Path(__file__).resolve().parents[2]
        sources = [root / "agent.py"] + sorted((root / "agent" / "mirvmon_agent").glob("*.py"))
        for source in sources:
            content = source.read_text(encoding="utf-8")
            self.assertNotIn("from __future__ import annotations", content)
            self.assertNotIn("import requests", content)
            self.assertNotIn("dataclasses", content)
        requirements = (root / "agent" / "requirements.txt").read_text(encoding="utf-8")
        self.assertNotIn("requests", requirements)

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
