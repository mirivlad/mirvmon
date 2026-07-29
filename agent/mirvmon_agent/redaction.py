from __future__ import annotations

import re
import shlex
from urllib.parse import parse_qsl, urlencode, urlsplit, urlunsplit


SENSITIVE_OPTIONS = {
    "--api-key",
    "--apikey",
    "--authorization",
    "--passwd",
    "--password",
    "--secret",
    "--token",
}


def redact_command(command: str) -> str:
    try:
        parts = shlex.split(command)
    except ValueError:
        return _fallback_redaction(command)

    redacted: list[str] = []
    redact_next = False
    for part in parts:
        if redact_next:
            redacted.append("[REDACTED]")
            redact_next = False
            continue

        lowered = part.lower()
        option, separator, _value = lowered.partition("=")
        if option in SENSITIVE_OPTIONS:
            if separator:
                redacted.append(part.split("=", 1)[0] + "=[REDACTED]")
            else:
                redacted.append(part)
                redact_next = True
            continue

        if re.match(r"^https?://", part, re.IGNORECASE):
            redacted.append(_redact_url(part))
        else:
            redacted.append(part)

    return shlex.join(redacted)


def _redact_url(value: str) -> str:
    parsed = urlsplit(value)
    host = parsed.hostname or ""
    if ":" in host:
        host = f"[{host}]"
    if parsed.port is not None:
        host += f":{parsed.port}"
    if parsed.username is not None or parsed.password is not None:
        host = "[REDACTED]@" + host
    query = urlencode(
        [(key, "[REDACTED]") for key, _value in parse_qsl(parsed.query, keep_blank_values=True)]
    )
    return urlunsplit((parsed.scheme, host, parsed.path, query, ""))


def _fallback_redaction(command: str) -> str:
    pattern = (
        r"(?i)(--(?:api-?key|authorization|passwd|password|secret|token))"
        r"(?:=|\s+)([^\s]+)"
    )
    return re.sub(pattern, r"\1=[REDACTED]", command)
