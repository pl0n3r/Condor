#!/usr/bin/env python3
"""Smoke autenticado sintético de Condor: solo lectura salvo POST de login."""
from __future__ import annotations

import http.cookiejar
import json
import os
import re
import sys
from dataclasses import dataclass
from datetime import datetime, timezone
from html.parser import HTMLParser
from urllib.error import HTTPError
from urllib.parse import urlencode, urljoin, urlsplit
from urllib.request import HTTPCookieProcessor, HTTPRedirectHandler, Request, build_opener

ORIGIN = "https://www.condorapp.com.co"
LOGIN = "/admin/login"
BAD_BRANCH = "/api/v1/context?branch=00000000000000000000000000"
GET_PATHS = frozenset({"/health", LOGIN, "/admin", "/adminpl0n3r",
                       "/api/v1/context", BAD_BRANCH})
MAX_BYTES = 256 * 1024
SHA_PATTERN = re.compile(r"[0-9a-f]{40}\Z", re.ASCII)
VERSION_PATTERN = re.compile(r"[0-9]+\.[0-9]+\.[0-9]+\Z", re.ASCII)


class SmokeFailure(ValueError):
    """Error con código constante apto para logs, sin evidencia privada."""


@dataclass(frozen=True)
class Reply:
    status: int
    body: bytes = b""
    location: str = ""


class NoRedirect(HTTPRedirectHandler):
    def redirect_request(self, request, fp, code, msg, headers, newurl):
        return None


class CSRFInput(HTMLParser):
    def __init__(self):
        super().__init__()
        self.token = None

    def handle_starttag(self, tag, attrs):
        if tag != "input":
            return
        attributes = dict(attrs)
        if attributes.get("name") == "_csrf_token":
            self.token = attributes.get("value")


class Client:
    def __init__(self):
        self.opener = build_opener(
            HTTPCookieProcessor(http.cookiejar.CookieJar()), NoRedirect()
        )

    def request(self, method: str, path: str, data: dict[str, str] | None = None) -> Reply:
        if (method == "GET" and path in GET_PATHS and data is None):
            payload = None
        elif method == "POST" and path == LOGIN and data is not None:
            if set(data) != {"_username", "_password", "_csrf_token"}:
                raise SmokeFailure("invalid_login_payload")
            payload = urlencode(data).encode("utf-8")
        else:
            raise SmokeFailure("method_or_path_not_allowed")
        headers = {"User-Agent": "Condor-Synthetic-Auth-Smoke/1"}
        if payload is not None:
            headers["Content-Type"] = "application/x-www-form-urlencoded"
        request = Request(ORIGIN + path, data=payload, headers=headers, method=method)
        try:
            response = self.opener.open(request, timeout=8)
        except HTTPError as error:
            response = error
        try:
            if urlsplit(response.geturl()).netloc != urlsplit(ORIGIN).netloc:
                raise SmokeFailure("cross_origin_response")
            body = response.read(MAX_BYTES + 1)
            if len(body) > MAX_BYTES:
                raise SmokeFailure("response_too_large")
            return Reply(int(response.status), body, response.headers.get("Location", ""))
        finally:
            response.close()


def target(location: str) -> str:
    if not location or any(char in location for char in "\r\n"):
        raise SmokeFailure("invalid_login_redirect")
    parsed = urlsplit(urljoin(ORIGIN, location))
    origin = urlsplit(ORIGIN)
    if (parsed.scheme, parsed.netloc) != (origin.scheme, origin.netloc):
        raise SmokeFailure("cross_origin_redirect")
    if parsed.path != "/admin" or parsed.query or parsed.fragment:
        raise SmokeFailure("unexpected_login_role")
    return parsed.path


def health(client, sha: str) -> str:
    if not SHA_PATTERN.fullmatch(sha):
        raise SmokeFailure("invalid_expected_sha")
    response = client.request("GET", "/health")
    if response.status != 200:
        raise SmokeFailure("health_unavailable")
    try:
        payload = json.loads(response.body)
    except (ValueError, UnicodeDecodeError):
        raise SmokeFailure("invalid_health") from None
    if not isinstance(payload, dict):
        raise SmokeFailure("invalid_health")
    version = payload.get("version")
    if (payload.get("status") != "ok"
            or payload.get("schema_up_to_date") is not True
            or not isinstance(version, str)
            or not VERSION_PATTERN.fullmatch(version)
            or payload.get("release_sha") != sha):
        raise SmokeFailure("release_identity_mismatch")
    return version


def context_safe(payload: bytes) -> bool:
    try:
        context = json.loads(payload)
    except (ValueError, UnicodeDecodeError):
        return False
    if not isinstance(context, dict):
        return False
    tenant = context.get("tenant")
    active = context.get("active_branch")
    branches = context.get("branches")
    if not isinstance(tenant, dict) or not isinstance(active, dict):
        return False
    if not isinstance(branches, list) or len(branches) < 1:
        return False
    if not isinstance(tenant.get("id"), str) or not tenant["id"]:
        return False
    identifier = active.get("id")
    return (isinstance(identifier, str) and bool(identifier)
            and any(isinstance(b, dict) and b.get("id") == identifier for b in branches))


def probe(client, email: str, password: str, sha: str, *,
          clock=None) -> dict[str, object]:
    if not email or not password:
        raise SmokeFailure("synthetic_identity_missing")
    version = health(client, sha)
    screen = client.request("GET", LOGIN)
    if screen.status != 200:
        raise SmokeFailure("login_screen_unavailable")
    csrf = CSRFInput()
    csrf.feed(screen.body.decode("utf-8", errors="replace"))
    if not csrf.token:
        raise SmokeFailure("csrf_missing")
    login = client.request("POST", LOGIN, {
        "_username": email, "_password": password, "_csrf_token": csrf.token,
    })
    if login.status not in (302, 303):
        raise SmokeFailure("login_not_authenticated")
    target(login.location)
    panel = client.request("GET", "/admin")
    if panel.status != 200 or b'id="condor-admin-root"' not in panel.body:
        raise SmokeFailure("tenant_dashboard_unavailable")
    context = client.request("GET", "/api/v1/context")
    if context.status != 200 or not context_safe(context.body):
        raise SmokeFailure("tenant_context_invalid")
    owner = client.request("GET", "/adminpl0n3r")
    if owner.status != 403:
        raise SmokeFailure("platform_owner_scope_not_denied")
    wrong_branch = client.request("GET", BAD_BRANCH)
    if wrong_branch.status != 403:
        raise SmokeFailure("unknown_branch_scope_not_denied")
    observed = clock() if clock else datetime.now(timezone.utc)
    if not isinstance(observed, datetime) or observed.tzinfo is None:
        raise SmokeFailure("invalid_clock")
    return {
        "state": "VALIDATED", "observed_at": observed.astimezone(timezone.utc)
        .replace(microsecond=0).isoformat().replace("+00:00", "Z"),
        "version": version, "release_sha": sha,
        "authenticated": True, "tenant_safe": True, "read_only": True,
    }


def main() -> int:
    email = os.environ.get("CONDOR_SMOKE_EMAIL", "")
    password = os.environ.get("CONDOR_SMOKE_PASSWORD", "")
    sha = os.environ.get("EXPECTED_MAIN_SHA", "")
    if not email or not password:
        print(json.dumps({"state": "SKIPPED", "reason": "identidad sintética no configurada"}))
        return 3
    try:
        print(json.dumps(probe(Client(), email, password, sha), sort_keys=True))
        return 0
    except Exception:
        # No registrar URLs, respuestas, excepciones del transporte o datos de sesión.
        print(json.dumps({"state": "UNKNOWN", "reason": "authenticated_probe_failed"}))
        return 1


if __name__ == "__main__":
    sys.exit(main())
