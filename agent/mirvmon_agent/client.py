import json
import ssl
import uuid
from datetime import datetime, timezone
from enum import Enum
from urllib.error import HTTPError
from urllib.parse import urlparse
from urllib.request import HTTPRedirectHandler, HTTPSHandler, ProxyHandler, Request, build_opener

from . import __version__
from .config import AgentConfig


class DeliveryResult(Enum):
    ACCEPTED = "accepted"
    DISCARD = "discard"
    RETRY = "retry"


class HttpResponse(object):
    def __init__(self, status_code, body):
        self.status_code = status_code
        self.body = body


class _SameOriginRedirectHandler(HTTPRedirectHandler):
    def redirect_request(self, request, file_pointer, code, message, headers, new_url):
        if not HttpTransport.is_same_origin(request.full_url, new_url):
            return None
        return HTTPRedirectHandler.redirect_request(
            self, request, file_pointer, code, message, headers, new_url
        )


class HttpTransport(object):
    """Small HTTPS transport that intentionally relies on the system CA store."""

    MAX_RESPONSE_BYTES = 65536

    def __init__(self, opener=None, timeout_seconds=15, max_response_bytes=None):
        self.timeout_seconds = timeout_seconds
        self.max_response_bytes = max_response_bytes or self.MAX_RESPONSE_BYTES
        if opener is None:
            context = ssl.create_default_context()
            opener = build_opener(
                ProxyHandler(),
                HTTPSHandler(context=context),
                _SameOriginRedirectHandler(),
            )
        self._opener = opener

    @staticmethod
    def is_same_origin(source, destination):
        first = urlparse(source)
        second = urlparse(destination)
        first_port = first.port or (443 if first.scheme == "https" else 80)
        second_port = second.port or (443 if second.scheme == "https" else 80)
        return (
            first.scheme == second.scheme
            and first.hostname == second.hostname
            and first_port == second_port
        )

    def request(self, method, url, payload, headers):
        request_headers = dict(headers)
        if payload is not None:
            request_headers["Content-Type"] = "application/json"
        request = Request(url, data=payload, headers=request_headers, method=method)
        try:
            response = self._opener.open(request, timeout=self.timeout_seconds)
        except HTTPError as error:
            return HttpResponse(error.code, self._read_response(error))
        return HttpResponse(response.getcode(), self._read_response(response))

    def _read_response(self, response):
        return response.read(self.max_response_bytes + 1)[:self.max_response_bytes]


class ApiClient(object):
    def __init__(self, config, transport=None):
        self.config = config
        self.transport = transport or HttpTransport()

    def send(self, envelope):
        try:
            response = self.transport.request(
                "POST",
                self.config.api_url,
                json.dumps(envelope, separators=(",", ":")).encode("utf-8"),
                {"Accept": "application/json"},
            )
        except (OSError, ValueError):
            return DeliveryResult.RETRY

        if response.status_code in (200, 202):
            return DeliveryResult.ACCEPTED
        if response.status_code in (400, 413, 422):
            return DeliveryResult.DISCARD
        return DeliveryResult.RETRY

    def pull_config(self):
        try:
            response = self.transport.request(
                "GET",
                self.config.config_url,
                None,
                {
                    "Accept": "application/json",
                    "Authorization": "Bearer " + self.config.token,
                },
            )
        except (OSError, ValueError):
            return {}

        if response.status_code != 200:
            return {}
        try:
            payload = json.loads(response.body.decode("utf-8"))
        except (UnicodeDecodeError, ValueError, TypeError):
            return {}
        return payload if isinstance(payload, dict) else {}

    @staticmethod
    def retry_delay(attempt):
        return min(300, 2 ** max(0, min(attempt, 9)))


def build_envelope(token, metrics, services, process_snapshot, now=None):
    timestamp = now or datetime.now(timezone.utc)
    timestamp = timestamp.astimezone(timezone.utc).replace(microsecond=0)
    envelope = {
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
