#!/usr/bin/env python3
"""#1492: ensayo técnico local de rollback y contención; nunca accede a Hostinger."""
from __future__ import annotations

import http.client
import importlib.util
import json
import re
import subprocess
import tempfile
import time
from contextlib import contextmanager
from datetime import datetime, timezone
from http.server import BaseHTTPRequestHandler, ThreadingHTTPServer
from pathlib import Path
from threading import Thread
from unittest.mock import patch

ROOT = Path(__file__).resolve().parents[1]
GOOD_SHA = "a" * 40
BAD_SHA = "b" * 40
SENTINEL = "condor-1492-sandbox-only-v1"


class DrillError(RuntimeError):
    """Error fail-closed del ensayo sin efectos remotos."""


def utc_now() -> str:
    """Devuelve la hora UTC observada, nunca una duración inventada."""
    return datetime.now(timezone.utc).isoformat(timespec="milliseconds").replace("+00:00", "Z")


def prepare_releases(root: Path) -> None:
    """Crea exclusivamente releases ficticios en una raíz inicialmente vacía."""
    if root.exists() or root.is_symlink():
        raise DrillError("sandbox_root_not_empty")
    root.mkdir(parents=True)
    (root / ".synthetic-only").write_text(SENTINEL, encoding="utf-8")
    for commit in (GOOD_SHA, BAD_SHA):
        release = root / "releases" / commit
        release.mkdir(parents=True)
        (release / ".release-sha").write_text(commit + "\n", encoding="utf-8")
    (root / ".previous").write_text(str(root / "releases" / GOOD_SHA) + "\n", encoding="utf-8")
    (root / "current").symlink_to(root / "releases" / BAD_SHA)


def local_rollback(root: Path) -> None:
    """Ejecuta el shell real del adapter mediante transporte local interceptado."""
    if root.is_symlink() or not root.is_dir():
        raise DrillError("sandbox_root_invalid")
    marker = root / ".synthetic-only"
    if marker.is_symlink() or not marker.is_file() or marker.read_text(encoding="utf-8") != SENTINEL:
        raise DrillError("sandbox_authority_missing")
    expected_releases = root / "releases"
    current = root / "current"
    if not current.is_symlink() or current.resolve(strict=True).parent != expected_releases:
        raise DrillError("sandbox_current_invalid")
    spec = importlib.util.spec_from_file_location("condor_factory_adapter_drill", ROOT / "ops/factory/adapter.py")
    if spec is None or spec.loader is None:
        raise DrillError("adapter_unavailable")
    adapter = importlib.util.module_from_spec(spec)
    spec.loader.exec_module(adapter)

    @contextmanager
    def fake_ssh():
        yield (["SANDBOX_ONLY"],)

    def fake_remote(base, command, source, *args):
        if base != ["SANDBOX_ONLY"] or command != "sh -c" or args != ("condor-rollback", str(root)):
            raise DrillError("remote_attempt_rejected")
        result = subprocess.run(
            ["sh", "-c", source, *args],
            stdout=subprocess.DEVNULL,
            stderr=subprocess.DEVNULL,
            timeout=10,
            check=False,
        )
        if result.returncode:
            raise subprocess.CalledProcessError(result.returncode, ["sh", "<isolated rollback>"])

    with patch.object(adapter, "release_root", return_value=str(root)), \
         patch.object(adapter, "ssh_material", fake_ssh), \
         patch.object(adapter, "remote", fake_remote):
        adapter.rollback()


class SyntheticHandler(BaseHTTPRequestHandler):
    """Frontera HTTP ficticia; nunca es código de control de Condor productivo."""

    def do_GET(self):
        self.dispatch()

    def do_POST(self):
        self.dispatch()

    def dispatch(self):
        match = re.fullmatch(r"/tenant/(a|b)/(view|write)", self.path)
        if match is None:
            self.respond(404)
            return
        tenant, action = match.groups()
        if (self.command, action) not in (("GET", "view"), ("POST", "write")):
            self.respond(405)
        elif tenant in self.server.blocked:
            self.respond(503, retry_after="60")
        elif self.command == "POST":
            self.server.writes[tenant] += 1
            self.respond(201)
        else:
            self.respond(200)

    def respond(self, status: int, *, retry_after: str | None = None):
        self.send_response(status)
        if retry_after is not None:
            self.send_header("Retry-After", retry_after)
        self.send_header("Content-Type", "text/plain; charset=utf-8")
        self.end_headers()
        self.wfile.write(b"synthetic-fixture")

    def log_message(self, *_args):
        return


def request(server: ThreadingHTTPServer, method: str, route: str) -> tuple[int, dict[str, str]]:
    """Solo el servidor loopback creado por este mismo ensayo."""
    host, port = server.server_address
    if host != "127.0.0.1" or method not in {"GET", "POST"}:
        raise DrillError("external_or_unsafe_request")
    connection = http.client.HTTPConnection(host, port, timeout=3)
    try:
        connection.request(method, route)
        response = connection.getresponse()
        result = response.status, {key.lower(): value for key, value in response.getheaders()}
        response.read()
        return result
    finally:
        connection.close()


def perform_drill() -> dict:
    """Mide contención, rollback y recuperación de datos totalmente ficticios."""
    started_at = utc_now()
    with tempfile.TemporaryDirectory(prefix="condor-1492-synthetic-") as tmp:
        root = Path(tmp) / "isolated-releases"
        prepare_releases(root)
        with ThreadingHTTPServer(("127.0.0.1", 0), SyntheticHandler) as server:
            server.blocked = set()
            server.writes = {"a": 0, "b": 0}
            runner = Thread(target=server.serve_forever, daemon=True)
            runner.start()
            try:
                before = root / "current"
                checks = {
                    "initial_release_is_bad": before.resolve() == root / "releases" / BAD_SHA,
                    "tenant_a_initially_up": request(server, "GET", "/tenant/a/view")[0] == 200,
                    "tenant_b_initially_up": request(server, "GET", "/tenant/b/view")[0] == 200,
                }

                clock = time.perf_counter_ns()
                server.blocked.add("a")
                denied_view, headers = request(server, "GET", "/tenant/a/view")
                denied_write, _ = request(server, "POST", "/tenant/a/write")
                allowed_b, _ = request(server, "GET", "/tenant/b/view")
                write_b, _ = request(server, "POST", "/tenant/b/write")
                containment_ms = round((time.perf_counter_ns() - clock) / 1_000_000, 3)
                checks["tenant_a_503_and_retry_after"] = (
                    denied_view == 503 and denied_write == 503 and headers.get("retry-after") == "60"
                )
                checks["tenant_a_no_writes"] = server.writes["a"] == 0
                checks["tenant_b_remains_available"] = (
                    allowed_b == 200 and write_b == 201 and server.writes["b"] == 1
                )

                clock = time.perf_counter_ns()
                local_rollback(root)
                rollback_ms = round((time.perf_counter_ns() - clock) / 1_000_000, 3)
                checks["rollback_matches_previous_sha"] = (
                    before.is_symlink() and before.resolve() == root / "releases" / GOOD_SHA
                )

                clock = time.perf_counter_ns()
                server.blocked.remove("a")
                recovered, _ = request(server, "GET", "/tenant/a/view")
                write_a, _ = request(server, "POST", "/tenant/a/write")
                recovery_ms = round((time.perf_counter_ns() - clock) / 1_000_000, 3)
                checks["tenant_a_recovered"] = (
                    recovered == 200 and write_a == 201 and server.writes["a"] == 1
                )
            finally:
                server.shutdown()
                runner.join(timeout=5)
    if not all(checks.values()):
        raise DrillError("synthetic_drill_failed")
    return {
        "version": 1,
        "issue": 1492,
        "status": "PASSED_SYNTHETIC_ONLY",
        "environment": "ephemeral_local_loopback",
        "authority": "unchanged",
        "started_at_utc": started_at,
        "completed_at_utc": utc_now(),
        "elapsed_ms": {
            "containment": containment_ms,
            "rollback": rollback_ms,
            "recovery": recovery_ms,
        },
        "release": {"before": BAD_SHA, "after": GOOD_SHA, "real_service": False},
        "checks": checks,
        "operators_by_role": {
            "incident_decision": "OWNER_REQUIRED_FOR_REAL_SERVICE",
            "exercise_operator": "SRE_SYNTHETIC",
            "verification": "QA_SYNTHETIC",
            "communications": "OWNER_APPROVAL_REQUIRED",
        },
        "communications": {
            "internal_draft": "Ensayo #1492: contencion y rollback sinteticos completados; no hubo efecto productivo.",
            "external_draft": "Estamos evaluando un evento de disponibilidad; comunicaremos novedades por el canal oficial aprobado.",
            "sent": False,
        },
        "corrective_actions": [
            "Implementar y comprobar un control real server-side de contencion por tenant y global.",
            "Validar en una ventana autorizada el transporte Hostinger y la compatibilidad del esquema.",
            "Designar responsables y canal real de incidente con aprobacion del OWNER.",
        ],
        "production_rollback": "NOT_EXECUTED",
        "production_shutdown": "NOT_EXECUTED",
        "production_validation": "NOT_VERIFIED",
        "go_live": "NOT_AUTHORIZED",
    }


if __name__ == "__main__":
    print(json.dumps(perform_drill(), ensure_ascii=False, sort_keys=True))
