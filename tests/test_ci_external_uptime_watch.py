#!/usr/bin/env python3
from __future__ import annotations

import importlib.util
import json
import unittest
from datetime import datetime, timedelta, timezone
from pathlib import Path

ROOT = Path(__file__).resolve().parents[1]
SCRIPT = ROOT / "scripts" / "external_uptime_watch.py"
WORKFLOW = ROOT / ".github" / "workflows" / "external-uptime.yml"
RUNBOOK = ROOT / "docs" / "LIVE-UPTIME-RUNBOOK.md"

SPEC = importlib.util.spec_from_file_location("external_uptime_watch", SCRIPT)
assert SPEC is not None and SPEC.loader is not None
uptime = importlib.util.module_from_spec(SPEC)
SPEC.loader.exec_module(uptime)


def ts(value: datetime) -> str:
    return value.astimezone(timezone.utc).replace(microsecond=0).isoformat().replace("+00:00", "Z")


def observation(state: str, when: datetime) -> dict[str, object]:
    endpoint_outcome = {
        "HEALTHY": "ok",
        "DEGRADED": "degraded",
        "UNKNOWN": "unknown",
    }[state]
    reason = {
        "HEALTHY": "ok",
        "DEGRADED": "contract",
        "UNKNOWN": "network",
    }[state]
    status = 200 if state != "UNKNOWN" else None
    endpoints: list[dict[str, object]] = []
    for endpoint in ("readiness", "home"):
        item: dict[str, object] = {
            "endpoint": endpoint,
            "outcome": endpoint_outcome,
            "reason": reason,
        }
        if status is not None:
            item["http_status"] = status
        endpoints.append(item)
    return {
        "state": state,
        "observed_at": ts(when),
        "endpoints": endpoints,
    }


class ExternalUptimeWatchTests(unittest.TestCase):
    def test_probe_contract_is_fixed_https_bounded_and_cross_origin_fail_closed(self) -> None:
        self.assertEqual("https://www.condorapp.com.co", uptime.ORIGIN)
        self.assertEqual("/health", uptime.HEALTH_PATH)
        self.assertEqual("/", uptime.HOME_PATH)
        self.assertGreater(uptime.TIMEOUT_SECONDS, 0)
        self.assertLessEqual(uptime.TIMEOUT_SECONDS, 10)
        self.assertLessEqual(uptime.MAX_HEALTH_BYTES, 64 * 1024)
        self.assertLessEqual(uptime.MAX_HOME_BYTES, 512 * 1024)

        seen: list[tuple[str, float, int]] = []

        def fake_fetch(path: str, *, timeout: float, limit: int) -> dict[str, object]:
            seen.append((path, timeout, limit))
            if path == "/health":
                return {
                    "status": 200,
                    "content_type": "application/json",
                    "body": json.dumps(
                        {
                            "status": "ok",
                            "version": "0.1.192",
                            "release_sha": "a" * 40,
                            "schema_up_to_date": True,
                        }
                    ).encode(),
                }
            return {
                "status": 200,
                "content_type": "text/html",
                "body": b"<html></html>",
            }

        result = uptime.probe(fake_fetch, now=datetime(2026, 10, 6, 13, 0, tzinfo=timezone.utc))
        self.assertEqual("HEALTHY", result["state"])
        self.assertEqual(
            [
                ("/health", uptime.TIMEOUT_SECONDS, uptime.MAX_HEALTH_BYTES),
                ("/", uptime.TIMEOUT_SECONDS, uptime.MAX_HOME_BYTES),
            ],
            seen,
        )
        self.assertIsNone(
            uptime.NoRedirect().redirect_request(
                None, None, 302, "redirect", {}, "https://example.test/"
            )
        )

    def test_states_and_freshness_are_explicit(self) -> None:
        now = datetime(2026, 10, 6, 13, 0, tzinfo=timezone.utc)
        for state in ("HEALTHY", "DEGRADED", "UNKNOWN"):
            with self.subTest(state=state):
                self.assertEqual(
                    state,
                    uptime.effective_state(observation(state, now), now=now),
                )
        stale = observation("HEALTHY", now - timedelta(seconds=uptime.MAX_AGE_SECONDS + 1))
        self.assertEqual("UNKNOWN", uptime.effective_state(stale, now=now))

    def test_persistent_failure_requires_two_non_healthy_samples(self) -> None:
        now = datetime(2026, 10, 6, 13, 0, tzinfo=timezone.utc)
        transient = uptime.decide(
            observation("HEALTHY", now),
            observation("DEGRADED", now),
            [],
            now=now,
        )
        self.assertEqual("noop", transient["action"])
        self.assertEqual("TRANSIENT", transient["state"])

        persistent = uptime.decide(
            observation("DEGRADED", now),
            observation("DEGRADED", now),
            [],
            now=now,
        )
        self.assertEqual("create", persistent["action"])
        self.assertEqual("DEGRADED", persistent["state"])

    def test_open_alert_is_deduplicated_by_marker(self) -> None:
        now = datetime(2026, 10, 6, 13, 0, tzinfo=timezone.utc)
        existing = {
            "number": 77,
            "state": "open",
            "body": uptime.alert_body("DEGRADED", observation("DEGRADED", now)),
        }
        same = uptime.decide(
            observation("DEGRADED", now),
            observation("DEGRADED", now),
            [existing],
            now=now,
        )
        self.assertEqual("noop", same["action"])
        self.assertEqual(77, same["issue_number"])

        changed = uptime.decide(
            observation("UNKNOWN", now),
            observation("UNKNOWN", now),
            [existing],
            now=now,
        )
        self.assertEqual("update", changed["action"])
        self.assertEqual(77, changed["issue_number"])
        self.assertNotEqual("create", changed["action"])

        duplicate = uptime.decide(
            observation("DEGRADED", now),
            observation("DEGRADED", now),
            [existing, {**existing, "number": 78}],
            now=now,
        )
        self.assertEqual("error", duplicate["action"])

    def test_recovery_closes_existing_alert_only(self) -> None:
        now = datetime(2026, 10, 6, 13, 0, tzinfo=timezone.utc)
        existing = {
            "number": 77,
            "state": "open",
            "body": uptime.alert_body("UNKNOWN", observation("UNKNOWN", now)),
        }
        close = uptime.decide(
            observation("HEALTHY", now),
            observation("HEALTHY", now),
            [existing],
            now=now,
        )
        self.assertEqual("close", close["action"])
        self.assertEqual(77, close["issue_number"])
        self.assertIn("dos muestras externas HEALTHY", close["comment"])

        clear = uptime.decide(
            observation("HEALTHY", now),
            observation("HEALTHY", now),
            [],
            now=now,
        )
        self.assertEqual("noop", clear["action"])
        self.assertEqual("already_clear", clear["reason"])

    def test_workflow_alert_contract_is_minimal_and_owner_actionable(self) -> None:
        workflow = WORKFLOW.read_text(encoding="utf-8")
        runbook = RUNBOOK.read_text(encoding="utf-8")

        self.assertIn("schedule:", workflow)
        self.assertIn("workflow_dispatch:", workflow)
        self.assertIn("contents: read", workflow)
        self.assertIn("issues: write", workflow)
        self.assertIn("persist-credentials: false", workflow)
        self.assertIn("assignees:[$owner]", workflow)
        for label in (
            "tipo: incidente",
            "prioridad: alta",
            "estado: bloqueado",
            "rol: infraestructura",
            "rol: sre",
            "rol: seguridad",
        ):
            self.assertIn(label, workflow)
        self.assertNotIn("secrets.", workflow)
        self.assertIn("no autoriza go-live", runbook.lower())

        now = datetime(2026, 10, 6, 13, 0, tzinfo=timezone.utc)
        action = uptime.decide(
            observation("DEGRADED", now),
            observation("DEGRADED", now),
            [],
            now=now,
        )
        body = str(action["body"]).lower()
        for forbidden in (
            "authorization:",
            "set-cookie:",
            "password=",
            "database_url",
            "select ",
            "insert ",
            "customer_email",
        ):
            self.assertNotIn(forbidden, body)
        self.assertIn(uptime.ALERT_MARKER.lower(), body)


if __name__ == "__main__":
    unittest.main()
