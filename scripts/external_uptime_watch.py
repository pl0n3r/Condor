#!/usr/bin/env python3
"""External, read-only uptime probe and GitHub alert reconciler for Condor."""

from __future__ import annotations

import argparse
import json
import re
import socket
import ssl
import sys
from datetime import datetime, timezone
from pathlib import Path
from typing import Any, Callable
from urllib.error import HTTPError, URLError
from urllib.request import HTTPRedirectHandler, Request, build_opener

ORIGIN = "https://www.condorapp.com.co"
HEALTH_PATH = "/health"
HOME_PATH = "/"
ALERT_MARKER = "<!-- condor-external-uptime-alert:v1 -->"
ALERT_TITLE = "[AUTO] Uptime externo de Condor requiere atención"
STATES = {"HEALTHY", "DEGRADED", "UNKNOWN"}
TIMEOUT_SECONDS = 5.0
MAX_HEALTH_BYTES = 64 * 1024
MAX_HOME_BYTES = 512 * 1024
MAX_AGE_SECONDS = 20 * 60
MAX_INPUT_BYTES = 4 * 1024 * 1024
WORK_DIR = Path(".external-uptime-work")
FIRST_SAMPLE = WORK_DIR / "sample-1.json"
SECOND_SAMPLE = WORK_DIR / "sample-2.json"
OPEN_ISSUES = WORK_DIR / "open-issues.json"
VERSION_RE = re.compile(r"\d+\.\d+\.\d+\Z", re.ASCII)
SHA_RE = re.compile(r"[0-9a-f]{40}\Z", re.ASCII)
STATE_LINE_RE = re.compile(r"^Current state: `(HEALTHY|DEGRADED|UNKNOWN)`$", re.MULTILINE)


class ProbeUnknown(RuntimeError):
    """Expected network/TLS/timeout failure with a safe allowlisted reason."""

    def __init__(self, reason: str) -> None:
        super().__init__(reason)
        self.reason = reason


class NoRedirect(HTTPRedirectHandler):
    """Refuse redirects so the fixed production origin cannot be escaped."""

    def redirect_request(
        self,
        request: Request,
        fp: Any,
        code: int,
        msg: str,
        headers: Any,
        newurl: str,
    ) -> None:
        return None


def utc_now() -> datetime:
    """Return current UTC time as an aware datetime."""

    return datetime.now(timezone.utc)


def format_time(value: datetime) -> str:
    """Render an aware datetime as canonical second-resolution UTC."""

    return value.astimezone(timezone.utc).replace(microsecond=0).isoformat().replace("+00:00", "Z")


def parse_time(value: object) -> datetime | None:
    """Parse canonical UTC timestamps and reject malformed or naive values."""

    if not isinstance(value, str) or len(value) > 40:
        return None
    try:
        parsed = datetime.fromisoformat(value.replace("Z", "+00:00"))
    except ValueError:
        return None
    if parsed.tzinfo is None:
        return None
    return parsed.astimezone(timezone.utc)


def read_bounded(response: Any, limit: int) -> bytes:
    """Read at most limit bytes and classify oversized payloads without logging content."""

    body = response.read(limit + 1)
    if len(body) > limit:
        raise ValueError("payload_too_large")
    return body


def fetch_endpoint(path: str, *, timeout: float, limit: int) -> dict[str, object]:
    """Fetch one fixed-origin endpoint without redirects and return safe metadata."""

    if path not in {HEALTH_PATH, HOME_PATH}:
        raise ValueError("unsupported_path")
    url = ORIGIN + path
    request = Request(
        url,
        headers={"User-Agent": "Condor-External-Uptime/1"},
        method="GET",
    )
    opener = build_opener(NoRedirect)
    try:
        with opener.open(request, timeout=timeout) as response:
            body = read_bounded(response, limit)
            content_type = (response.headers.get_content_type() or "").lower()
            return {
                "status": int(response.status),
                "content_type": content_type,
                "body": body,
            }
    except HTTPError as error:
        return {
            "status": int(error.code),
            "content_type": "",
            "body": b"",
        }
    except (TimeoutError, socket.timeout):
        raise ProbeUnknown("timeout") from None
    except ssl.SSLError:
        raise ProbeUnknown("tls") from None
    except URLError as error:
        if isinstance(getattr(error, "reason", None), ssl.SSLError):
            raise ProbeUnknown("tls") from None
        raise ProbeUnknown("network") from None


def endpoint_evidence(
    endpoint: str,
    outcome: str,
    reason: str,
    http_status: int | None = None,
) -> dict[str, object]:
    """Build allowlisted endpoint evidence without remote content or headers."""

    result: dict[str, object] = {
        "endpoint": endpoint,
        "outcome": outcome,
        "reason": reason,
    }
    if http_status is not None:
        result["http_status"] = http_status
    return result


def probe_health(fetcher: Callable[..., dict[str, object]]) -> dict[str, object]:
    """Validate the public health contract and return sanitized evidence."""

    try:
        response = fetcher(
            HEALTH_PATH,
            timeout=TIMEOUT_SECONDS,
            limit=MAX_HEALTH_BYTES,
        )
    except ProbeUnknown as error:
        return endpoint_evidence("readiness", "unknown", error.reason)
    except ValueError as error:
        reason = str(error)
        if reason not in {"payload_too_large", "unsupported_path"}:
            reason = "contract"
        return endpoint_evidence("readiness", "degraded", reason)

    status = response.get("status")
    if not isinstance(status, int) or status != 200:
        return endpoint_evidence(
            "readiness",
            "degraded",
            "http_status",
            status if isinstance(status, int) else None,
        )
    if response.get("content_type") != "application/json":
        return endpoint_evidence("readiness", "degraded", "content_type", status)
    body = response.get("body")
    if not isinstance(body, (bytes, bytearray)):
        return endpoint_evidence("readiness", "degraded", "contract", status)

    try:
        payload = json.loads(bytes(body).decode("utf-8"))
    except (UnicodeDecodeError, ValueError):
        return endpoint_evidence("readiness", "degraded", "invalid_json", status)

    valid = (
        isinstance(payload, dict)
        and payload.get("status") == "ok"
        and isinstance(payload.get("version"), str)
        and VERSION_RE.fullmatch(payload["version"]) is not None
        and isinstance(payload.get("release_sha"), str)
        and SHA_RE.fullmatch(payload["release_sha"]) is not None
        and payload.get("schema_up_to_date") is True
    )
    if not valid:
        return endpoint_evidence("readiness", "degraded", "contract", status)
    return endpoint_evidence("readiness", "ok", "ok", status)


def probe_home(fetcher: Callable[..., dict[str, object]]) -> dict[str, object]:
    """Validate basic public-home availability without persisting or exposing HTML."""

    try:
        response = fetcher(
            HOME_PATH,
            timeout=TIMEOUT_SECONDS,
            limit=MAX_HOME_BYTES,
        )
    except ProbeUnknown as error:
        return endpoint_evidence("home", "unknown", error.reason)
    except ValueError as error:
        reason = str(error)
        if reason not in {"payload_too_large", "unsupported_path"}:
            reason = "contract"
        return endpoint_evidence("home", "degraded", reason)

    status = response.get("status")
    if not isinstance(status, int) or status != 200:
        return endpoint_evidence(
            "home",
            "degraded",
            "http_status",
            status if isinstance(status, int) else None,
        )
    if response.get("content_type") != "text/html":
        return endpoint_evidence("home", "degraded", "content_type", status)
    return endpoint_evidence("home", "ok", "ok", status)


def probe(
    fetcher: Callable[..., dict[str, object]] = fetch_endpoint,
    *,
    now: datetime | None = None,
) -> dict[str, object]:
    """Probe health and home, classifying only safe high-level state."""

    observed_at = now or utc_now()
    health = probe_health(fetcher)
    home = probe_home(fetcher)
    outcomes = {str(health["outcome"]), str(home["outcome"])}
    if "unknown" in outcomes:
        state = "UNKNOWN"
    elif "degraded" in outcomes:
        state = "DEGRADED"
    else:
        state = "HEALTHY"
    return {
        "state": state,
        "observed_at": format_time(observed_at),
        "endpoints": [health, home],
    }


def effective_state(
    observation: dict[str, object],
    *,
    now: datetime,
    max_age_seconds: int = MAX_AGE_SECONDS,
) -> str:
    """Return UNKNOWN for stale/future/malformed evidence; otherwise its declared state."""

    state = observation.get("state")
    timestamp = parse_time(observation.get("observed_at"))
    if state not in STATES or timestamp is None:
        return "UNKNOWN"
    age = (now - timestamp).total_seconds()
    if age < -60 or age > max_age_seconds:
        return "UNKNOWN"
    endpoints = observation.get("endpoints")
    if not isinstance(endpoints, list) or len(endpoints) != 2:
        return "UNKNOWN"
    return str(state)


def persistent_state(
    first: dict[str, object],
    second: dict[str, object],
    *,
    now: datetime,
) -> str:
    """Aggregate two samples into HEALTHY, DEGRADED, UNKNOWN, or TRANSIENT."""

    states = [
        effective_state(first, now=now),
        effective_state(second, now=now),
    ]
    if states == ["HEALTHY", "HEALTHY"]:
        return "HEALTHY"
    if all(state != "HEALTHY" for state in states):
        return "UNKNOWN" if "UNKNOWN" in states else "DEGRADED"
    return "TRANSIENT"


def open_alerts(issues: object) -> list[dict[str, object]]:
    """Find open non-PR alert Issues carrying the canonical marker."""

    if not isinstance(issues, list):
        raise ValueError("issues_not_list")
    found: list[dict[str, object]] = []
    for item in issues:
        if not isinstance(item, dict):
            continue
        if item.get("state") != "open" or "pull_request" in item:
            continue
        body = item.get("body")
        if isinstance(body, str) and ALERT_MARKER in body:
            found.append(item)
    return found


def safe_endpoint_line(endpoint: object) -> str:
    """Render one endpoint evidence line from allowlisted fields only."""

    if not isinstance(endpoint, dict):
        return "unknown"
    name = endpoint.get("endpoint")
    outcome = endpoint.get("outcome")
    reason = endpoint.get("reason")
    status = endpoint.get("http_status")
    if name not in {"readiness", "home"}:
        name = "unknown"
    if outcome not in {"ok", "degraded", "unknown"}:
        outcome = "unknown"
    if reason not in {
        "ok",
        "timeout",
        "tls",
        "network",
        "http_status",
        "content_type",
        "payload_too_large",
        "invalid_json",
        "contract",
    }:
        reason = "contract"
    suffix = f", http={status}" if isinstance(status, int) and 100 <= status <= 599 else ""
    return f"{name}: {outcome} ({reason}{suffix})"


def alert_body(state: str, observation: dict[str, object]) -> str:
    """Build a minimal actionable alert body without remote payloads."""

    if state not in {"DEGRADED", "UNKNOWN"}:
        raise ValueError("invalid_alert_state")
    observed_at = observation.get("observed_at")
    if parse_time(observed_at) is None:
        observed_at = "unknown"
    endpoints = observation.get("endpoints")
    safe = endpoints if isinstance(endpoints, list) else []
    health = safe_endpoint_line(safe[0] if len(safe) > 0 else None)
    home = safe_endpoint_line(safe[1] if len(safe) > 1 else None)
    return (
        f"{ALERT_MARKER}\n"
        "Alerta automática del probe externo de Condor.\n\n"
        f"Current state: `{state}`\n"
        f"Observed at: `{observed_at}`\n"
        f"- {health}\n"
        f"- {home}\n\n"
        "Acción del owner: revisar el run de `External uptime Condor`, "
        "el `/health` público y la home antes de cualquier mutación productiva.\n\n"
        "Esta evidencia no incluye bodies remotos, cookies, headers, SQL, PII ni secretos. "
        "No autoriza go-live ni cambios de producción.\n"
    )


def last_recorded_state(issue: dict[str, object]) -> str | None:
    """Extract the last alert state from the canonical Issue body."""

    body = issue.get("body")
    if not isinstance(body, str):
        return None
    match = STATE_LINE_RE.search(body)
    return match.group(1) if match else None


def decide(
    first: dict[str, object],
    second: dict[str, object],
    issues: object,
    *,
    now: datetime,
) -> dict[str, object]:
    """Choose one fail-closed GitHub Issue mutation from two probe samples."""

    alerts = open_alerts(issues)
    if len(alerts) > 1:
        return {
            "action": "error",
            "reason": "duplicate_open_alerts",
            "state": "UNKNOWN",
        }

    aggregate = persistent_state(first, second, now=now)
    existing = alerts[0] if alerts else None

    if aggregate == "TRANSIENT":
        return {"action": "noop", "state": "TRANSIENT", "reason": "not_persistent"}

    if aggregate == "HEALTHY":
        if existing is None:
            return {"action": "noop", "state": "HEALTHY", "reason": "already_clear"}
        number = existing.get("number")
        if not isinstance(number, int) or number < 1:
            return {"action": "error", "state": "UNKNOWN", "reason": "invalid_issue"}
        return {
            "action": "close",
            "state": "HEALTHY",
            "issue_number": number,
            "comment": (
                "<!-- condor-external-uptime-recovery:v1 -->\n"
                f"Recuperación confirmada por dos muestras externas HEALTHY. "
                f"Observed at: `{second.get('observed_at', 'unknown')}`.\n"
                "Se cierra la alerta; esto no equivale a autorización de go-live."
            ),
        }

    body = alert_body(aggregate, second)
    if existing is None:
        return {
            "action": "create",
            "state": aggregate,
            "title": ALERT_TITLE,
            "body": body,
        }

    number = existing.get("number")
    if not isinstance(number, int) or number < 1:
        return {"action": "error", "state": "UNKNOWN", "reason": "invalid_issue"}
    if last_recorded_state(existing) == aggregate:
        return {
            "action": "noop",
            "state": aggregate,
            "issue_number": number,
            "reason": "same_open_state",
        }
    return {
        "action": "update",
        "state": aggregate,
        "issue_number": number,
        "body": body,
    }


def read_work_json(file_path: Path) -> object:
    """Read one fixed workflow evidence file under the repository workspace."""

    resolved_root = WORK_DIR.resolve()
    resolved_file = file_path.resolve()
    if resolved_file.parent != resolved_root:
        raise ValueError("input_outside_workdir")
    if resolved_file.stat().st_size > MAX_INPUT_BYTES:
        raise ValueError("input_too_large")
    return json.loads(resolved_file.read_text(encoding="utf-8"))


def parse_now(value: str | None) -> datetime:
    """Parse an optional test timestamp or use the real UTC clock."""

    if value is None:
        return utc_now()
    parsed = parse_time(value)
    if parsed is None:
        raise ValueError("invalid_now")
    return parsed


def main() -> int:
    """CLI entry point for probe and deterministic alert reconciliation."""

    parser = argparse.ArgumentParser()
    sub = parser.add_subparsers(dest="command", required=True)

    sub.add_parser("probe")

    decide_parser = sub.add_parser("decide")
    decide_parser.add_argument("--now")

    args = parser.parse_args()
    try:
        if args.command == "probe":
            result = probe()
        else:
            first = read_work_json(FIRST_SAMPLE)
            second = read_work_json(SECOND_SAMPLE)
            issues = read_work_json(OPEN_ISSUES)
            if not isinstance(first, dict) or not isinstance(second, dict):
                raise ValueError("observation_not_object")
            result = decide(first, second, issues, now=parse_now(args.now))
    except (OSError, ValueError, json.JSONDecodeError) as error:
        print(f"external_uptime_watch: {error}", file=sys.stderr)
        return 2

    print(json.dumps(result, ensure_ascii=False, sort_keys=True))
    return 1 if result.get("action") == "error" else 0


if __name__ == "__main__":
    raise SystemExit(main())
