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

    def test_duplicate_health_json_keys_are_never_healthy(self) -> None:
        now = datetime(2026, 10, 11, 15, 0, tzinfo=timezone.utc)
        valid = ('"version":"0.1.194","release_sha":"' + "a" * 40
                 + '","schema_up_to_date":true')
        payloads = (
            ('{"status":"degraded","status":"ok",' + valid + '}').encode(),
            ('{"status":"ok","release_sha":"broken",' + valid + '}').encode(),
            ('{"status":"ok",' + valid + ',"extra":{"id":1,"id":2}}').encode(),
        )
        alert = {"number": 77, "state": "open",
                 "body": uptime.alert_body("DEGRADED", observation("DEGRADED", now))}
        for body in payloads:
            with self.subTest(payload=body[:85]):
                def fake_fetch(path: str, *, timeout: float, limit: int) -> dict[str, object]:
                    if path == uptime.HEALTH_PATH:
                        return {"status": 200, "content_type": "application/json",
                                "body": body}
                    return {"status": 200, "content_type": "text/html",
                            "body": b"<html></html>"}
                evidence = uptime.probe(fake_fetch, now=now)
                self.assertEqual("DEGRADED", evidence["state"])
                self.assertEqual("invalid_json", evidence["endpoints"][0]["reason"])
                action = uptime.decide(evidence, evidence, [alert], now=now)
                self.assertNotEqual("close", action["action"])
                self.assertNotIn("duplicate_json_key", json.dumps(action))
        normal = {"status": 200, "content_type": "application/json",
                  "body": ('{"status":"ok",' + valid + '}').encode()}
        self.assertEqual("ok", uptime.probe_health(
            lambda path, **_: normal)["outcome"])

    def test_boolean_http_status_does_not_imply_health(self) -> None:
        payload = json.dumps({"status": "ok", "version": "0.1.194",
                              "release_sha": "a" * 40,
                              "schema_up_to_date": True}).encode()
        for candidate in (True, False, 200.0, "200"):
            with self.subTest(status=repr(candidate)):
                def fake_fetch(path: str, **kwargs: object) -> dict[str, object]:
                    return {"status": candidate, "content_type":
                            "application/json" if path == uptime.HEALTH_PATH else "text/html",
                            "body": payload if path == uptime.HEALTH_PATH else b"<html/>"}
                evidence = uptime.probe(fake_fetch)
                self.assertEqual("DEGRADED", evidence["state"])
                self.assertEqual("degraded", evidence["endpoints"][0]["outcome"])
                self.assertEqual("degraded", evidence["endpoints"][1]["outcome"])

    def test_empty_home_200_is_not_healthy_and_cannot_close_alert(self) -> None:
        now = datetime(2026, 10, 11, 15, 0, tzinfo=timezone.utc)
        def fake_fetch(path: str, *, timeout: float, limit: int) -> dict[str, object]:
            if path == uptime.HEALTH_PATH:
                return {"status": 200, "content_type": "application/json",
                        "body": json.dumps({
                            "status": "ok", "version": "0.1.194",
                            "release_sha": "a" * 40, "schema_up_to_date": True
                        }).encode()}
            return {"status": 200, "content_type": "text/html", "body": b""}

        observed = uptime.probe(fake_fetch, now=now)
        self.assertEqual("DEGRADED", observed["state"])
        self.assertNotEqual("ok", observed["endpoints"][1]["outcome"])
        open_alert = {"number": 77, "state": "open",
                      "body": uptime.alert_body("DEGRADED", observation("DEGRADED", now))}
        result = uptime.decide(observed, observed, [open_alert], now=now)
        self.assertNotEqual("close", result["action"])
        self.assertEqual("DEGRADED", result["state"])
        self.assertEqual("degraded", uptime.probe_home(fake_fetch)["outcome"])

    def test_boolean_issue_id_fails_closed_for_close_and_update(self) -> None:
        now = datetime(2026, 10, 11, 15, 0, tzinfo=timezone.utc)
        existing = {"number": True, "state": "open",
                    "body": uptime.alert_body("DEGRADED", observation("DEGRADED", now))}
        for state in ("HEALTHY", "UNKNOWN"):
            with self.subTest(state=state):
                sample = observation(state, now)
                result = uptime.decide(sample, sample, [existing], now=now)
                self.assertEqual("error", result["action"])
                self.assertEqual("UNKNOWN", result["state"])
                self.assertNotIn("issue_number", result)

    def test_extreme_utc_offsets_fail_closed_without_overflow(self) -> None:
        now = datetime(2026, 10, 11, 15, 0, tzinfo=timezone.utc)
        for text in ("0001-01-01T00:00:00+01:00",
                     "9999-12-31T23:59:59-01:00"):
            with self.subTest(value=text):
                self.assertIsNone(uptime.parse_time(text))
                altered = observation("HEALTHY", now)
                altered["observed_at"] = text
                self.assertEqual("UNKNOWN", uptime.effective_state(altered, now=now))
                body = uptime.alert_body("UNKNOWN", altered)
                self.assertIn("Observed at: `unknown`", body)

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
        self.assertNotIn("--first", workflow)
        self.assertNotIn("--second", workflow)
        self.assertNotIn("--issues", workflow)
        self.assertIn(".external-uptime-work/sample-1.json", workflow)
        self.assertIn(".external-uptime-work/sample-2.json", workflow)
        self.assertIn(".external-uptime-work/open-issues.json", workflow)
        script = SCRIPT.read_text(encoding="utf-8")
        self.assertNotIn('add_argument("--first"', script)
        self.assertNotIn('add_argument("--second"', script)
        self.assertNotIn('add_argument("--issues"', script)
        self.assertIn('WORK_DIR = Path(".external-uptime-work")', script)
        self.assertIn("input_outside_workdir", script)
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


    def test_contradictory_healthy_evidence_cannot_close_alert(self) -> None:
        now = datetime(2026, 10, 11, 15, 0, tzinfo=timezone.utc)
        contradictory = observation("HEALTHY", now)
        contradictory["endpoints"] = [
            {"endpoint": name, "outcome": "degraded", "reason": "http_status",
             "http_status": 500}
            for name in ("readiness", "home")
        ]
        self.assertEqual("UNKNOWN", uptime.effective_state(contradictory, now=now))
        existing = {
            "number": 88,
            "state": "open",
            "body": uptime.alert_body("DEGRADED", observation("DEGRADED", now)),
        }
        result = uptime.decide(contradictory, contradictory, [existing], now=now)
        self.assertEqual("UNKNOWN", result["state"])
        self.assertNotEqual("close", result["action"])
        self.assertEqual("update", result["action"])

    def test_malformed_endpoint_evidence_is_unknown(self) -> None:
        now = datetime(2026, 10, 11, 15, 0, tzinfo=timezone.utc)
        good = observation("HEALTHY", now)
        for bad_state in ([], {}, 0, False):
            with self.subTest(state=repr(bad_state)):
                altered = dict(good, state=bad_state)
                self.assertEqual("UNKNOWN", uptime.effective_state(altered, now=now))

        for invalid in (
            [good["endpoints"][0], good["endpoints"][0]],
            [{"endpoint": "unknown", "outcome": "ok", "reason": "ok", "http_status": 200},
             good["endpoints"][1]],
            [{}, {}],
            [[], {}],
            "not-an-endpoint-list",
        ):
            with self.subTest(invalid=repr(invalid)[:80]):
                altered = dict(good, endpoints=invalid)
                self.assertEqual("UNKNOWN", uptime.effective_state(altered, now=now))

        for field, bad in (
            ("endpoint", []),
            ("outcome", []),
            ("reason", {}),
            ("http_status", True),
            ("http_status", 999),
            ("extra", "PRIVATE-CASE-SECRET"),
            ("outcome", "unexpected"),
        ):
            with self.subTest(field=field, value=repr(bad)):
                endpoints = [dict(row) for row in good["endpoints"]]
                endpoints[0][field] = bad
                altered = dict(good, endpoints=endpoints)
                self.assertEqual("UNKNOWN", uptime.effective_state(altered, now=now))

    def test_future_observation_cannot_close_existing_alert(self) -> None:
        now = datetime(2026, 10, 11, 15, 0, tzinfo=timezone.utc)
        previous = {
            "number": 101, "state": "open",
            "body": uptime.alert_body("DEGRADED", observation("DEGRADED", now)),
        }
        for seconds in (1, 30):
            with self.subTest(future_seconds=seconds):
                sample = observation("HEALTHY", now + timedelta(seconds=seconds))
                self.assertEqual("UNKNOWN", uptime.effective_state(sample, now=now))
                decision = uptime.decide(sample, sample, [previous], now=now)
                self.assertNotEqual("close", decision["action"])
                self.assertEqual("UNKNOWN", decision["state"])
        self.assertEqual(
            "HEALTHY", uptime.effective_state(observation("HEALTHY", now), now=now)
        )

    def test_observation_envelope_rejects_unexpected_metadata(self) -> None:
        now = datetime(2026, 10, 11, 15, 0, tzinfo=timezone.utc)
        clean = observation("HEALTHY", now)
        self.assertEqual("HEALTHY", uptime.effective_state(clean, now=now))
        for unexpected in (
            {"unexpected_metadata": "PRIVATE-SENTINEL"},
            {"sensitive_metadata": "PRIVATE-SENTINEL", "extra": True},
        ):
            with self.subTest(keys=tuple(unexpected)):
                hostile = {**clean, **unexpected}
                self.assertEqual("UNKNOWN", uptime.effective_state(hostile, now=now))
                alert = {"number": 88, "state": "open",
                         "body": uptime.alert_body("DEGRADED", observation("DEGRADED", now))}
                action = uptime.decide(hostile, hostile, [alert], now=now)
                self.assertNotEqual("close", action["action"])
                self.assertEqual("UNKNOWN", action["state"])
                self.assertNotIn("PRIVATE-SENTINEL", json.dumps(action))
        for absent in ("state", "observed_at", "endpoints"):
            with self.subTest(missing=absent):
                incomplete = dict(clean)
                del incomplete[absent]
                self.assertEqual("UNKNOWN", uptime.effective_state(incomplete, now=now))

    def test_invalid_observer_clock_or_threshold_is_unknown(self) -> None:
        now = datetime(2026, 10, 11, 15, 0, tzinfo=timezone.utc)
        healthy = observation("HEALTHY", now)
        invalid_clocks = (
            now.replace(tzinfo=None),
            None,
            "2026-10-11T15:00:00Z",
            datetime(1, 1, 1, tzinfo=timezone(timedelta(hours=1))),
            datetime(9999, 12, 31, 23, 59, 59,
                     tzinfo=timezone(timedelta(hours=-1))),
        )
        for candidate in invalid_clocks:
            with self.subTest(now=repr(candidate)):
                self.assertEqual("UNKNOWN", uptime.effective_state(healthy, now=candidate))
        for limit in (True, False, 0, -1, "1200", None, []):
            with self.subTest(limit=repr(limit)):
                self.assertEqual("UNKNOWN", uptime.effective_state(
                    healthy, now=now, max_age_seconds=limit))
        self.assertEqual("HEALTHY", uptime.effective_state(healthy, now=now))

    def test_noncanonical_observed_time_is_unknown_and_never_rendered(self) -> None:
        now = datetime(2026, 10, 11, 15, 0, tzinfo=timezone.utc)
        valid = observation("HEALTHY", now)
        for bad in (
            "2026-10-11" + chr(10) + "15:00:00Z",
            "2026-10-11`15:00:00Z",
            "2026-10-11 15:00:00Z",
            "2026-10-11T15:00:00+00:00",
            "2026-10-11T15:00:00.500Z",
            [],
            {},
        ):
            with self.subTest(timestamp=repr(bad)):
                sample = dict(valid, observed_at=bad)
                self.assertEqual("UNKNOWN", uptime.effective_state(sample, now=now))
                rendered = uptime.alert_body("UNKNOWN", sample)
                self.assertIn("Observed at: `unknown`", rendered)
                if isinstance(bad, str):
                    self.assertNotIn(bad, rendered)
        self.assertIn(
            "Observed at: `2026-10-11T15:00:00Z`",
            uptime.alert_body("UNKNOWN", observation("UNKNOWN", now)),
        )

    def test_consistent_samples_preserve_probe_recovery_contract(self) -> None:
        now = datetime(2026, 10, 11, 15, 0, tzinfo=timezone.utc)
        for state in ("HEALTHY", "DEGRADED", "UNKNOWN"):
            with self.subTest(state=state):
                self.assertEqual(
                    state, uptime.effective_state(observation(state, now), now=now)
                )
        degraded = observation("DEGRADED", now)
        degraded["endpoints"][0].pop("http_status")
        self.assertEqual("DEGRADED", uptime.effective_state(degraded, now=now))

        http_failure = observation("DEGRADED", now)
        http_failure["endpoints"][0] = {
            "endpoint": "readiness", "outcome": "degraded",
            "reason": "http_status", "http_status": 503,
        }
        self.assertEqual("DEGRADED", uptime.effective_state(http_failure, now=now))
        recovered = observation("HEALTHY", now)
        self.assertEqual(
            "TRANSIENT",
            uptime.persistent_state(recovered, degraded, now=now),
        )
        active_alert = {"number": 89, "state": "open",
                        "body": uptime.alert_body("UNKNOWN", observation("UNKNOWN", now))}
        self.assertEqual(
            "close",
            uptime.decide(recovered, recovered, [active_alert], now=now)["action"],
        )
        self.assertEqual(
            "UNKNOWN",
            uptime.effective_state(
                observation("HEALTHY", now - timedelta(seconds=uptime.MAX_AGE_SECONDS + 1)),
                now=now,
            ),
        )

    def test_endpoint_consistency_is_private_and_offline(self) -> None:
        from unittest.mock import patch
        now = datetime(2026, 10, 11, 15, 0, tzinfo=timezone.utc)
        sample = observation("UNKNOWN", now)
        sample["sensitive_metadata"] = "PRIVATE-CASE-SECRET"
        sample["endpoints"] = [
            {"endpoint": [], "outcome": {}, "reason": {"secret": "PRIVATE-CASE-SECRET"}},
            {"endpoint": "home", "outcome": "unknown",
             "reason": "network", "private": "PRIVATE-CASE-SECRET"},
        ]
        original = json.dumps(sample, sort_keys=True)
        with patch.object(
            uptime, "fetch_endpoint",
            side_effect=AssertionError("offline classification must not fetch"),
        ):
            self.assertEqual("UNKNOWN", uptime.effective_state(sample, now=now))
            self.assertEqual("UNKNOWN", uptime.persistent_state(sample, sample, now=now))
            rendered = uptime.alert_body("UNKNOWN", sample)
            self.assertNotIn("PRIVATE-CASE-SECRET", rendered)
            self.assertNotIn("['", rendered)
            self.assertIn("No autoriza go-live", rendered)
        self.assertEqual(original, json.dumps(sample, sort_keys=True))


if __name__ == "__main__":
    unittest.main()
