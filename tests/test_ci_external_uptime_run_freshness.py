"""Contractual offline checks for Condor #643 monitor freshness projection."""
from __future__ import annotations

import importlib.util
import json
import unittest
from datetime import datetime, timedelta, timezone
from pathlib import Path
from unittest.mock import patch

SCRIPT = Path(__file__).resolve().parents[1] / "scripts/external_uptime_run_freshness.py"
SPEC = importlib.util.spec_from_file_location("external_uptime_run_freshness", SCRIPT)
assert SPEC is not None and SPEC.loader is not None
freshness = importlib.util.module_from_spec(SPEC)
SPEC.loader.exec_module(freshness)
NOW = datetime(2026, 10, 10, 22, 30, tzinfo=timezone.utc)


def record(run_id=42, minutes_ago=5, conclusion="success", **changes):
    run = {
        "id": run_id, "path": freshness.WORKFLOW_PATH,
        "run_started_at": (NOW - timedelta(minutes=minutes_ago)).isoformat().replace("+00:00", "Z"),
        "head_sha": "a" * 40, "event": "schedule", "head_branch": "main",
        "status": "completed", "conclusion": conclusion,
    }
    run.update(changes)
    return run


def classify(runs, *, now=NOW, max_age_seconds=1800, snapshot_complete=True,
             snapshot_total_count=None):
    if snapshot_total_count is None and isinstance(runs, list):
        snapshot_total_count = len(runs)
    return freshness.project_run_freshness(
        runs, now=now, max_age_seconds=max_age_seconds,
        snapshot_complete=snapshot_complete, snapshot_total_count=snapshot_total_count,
    )


class ExternalUptimeRunFreshnessTests(unittest.TestCase):
    def test_latest_valid_run_and_bounded_age(self):
        a, b = record(1, minutes_ago=60), record(2, minutes_ago=5)
        for ordered in ([a, b], [b, a]):
            with self.subTest(order=[r["id"] for r in ordered]):
                got = classify(ordered, now=NOW)
                self.assertEqual(got["monitor_freshness"], "FRESH")
                self.assertEqual(got["run_id"], 2)
                self.assertEqual(got["age_seconds"], 300)
        at_boundary = classify([record(3, minutes_ago=30)], now=NOW)
        self.assertEqual(at_boundary["monitor_freshness"], "FRESH")
        past_boundary = classify([record(4, minutes_ago=31)], now=NOW)
        self.assertEqual(past_boundary["monitor_freshness"], "STALE")
        self.assertEqual(past_boundary["reason"], "monitor_run_stale")
        self.assertEqual(classify([b, b], now=NOW)["run_id"], 2)

    def test_missing_conflicting_and_future_evidence_is_unknown(self):
        invalid = [
            None, [], {}, ["invalid"], [record(path=".github/workflows/other.yml")],
            [record(id=0)], [record(id=True)], [record(head_sha="not-a-sha")],
            [record(run_started_at="2026-10-10T22:25:00")],
            [record(run_started_at="not-a-date")],
            [record(run_started_at=(NOW + timedelta(minutes=4)).isoformat())],
            [record(run_started_at=(NOW + timedelta(seconds=30)).isoformat())],
            [record(event="workflow_dispatch")], [record(head_branch="feature")],
            [record(conclusion="skipped")], [record(conclusion="neutral")],
            [record(conclusion="action_required")], [record(conclusion="cancelled")],
            [record(conclusion="timed_out")],
            [record(1), record(1, minutes_ago=6)],
            [record(1, run_started_at="2026-10-10T22:25:00.100Z"),
             record(1, run_started_at="2026-10-10T22:25:00.900Z")],
            [record(status="completed", conclusion=None)],
            [record(status="completed", conclusion=[])],
            [record(status="completed", conclusion={})],
            [record(status="in_progress", conclusion="success")],
            [record(status="in_progress", conclusion=None)],
            [record(), record(2, minutes_ago=1, status="queued", conclusion=None)],
        ]
        for item in invalid:
            with self.subTest(case=repr(item)[:110]):
                got = classify(item, now=NOW)
                self.assertEqual(got["monitor_freshness"], "UNKNOWN")
                self.assertIsNone(got["run_id"])
        self.assertEqual(classify([record()], now=NOW.replace(tzinfo=None))["monitor_freshness"], "UNKNOWN")
        self.assertEqual(classify([record()], now=NOW, max_age_seconds=0)["monitor_freshness"], "UNKNOWN")
        self.assertEqual(classify([record()] * 101, now=NOW)["monitor_freshness"], "UNKNOWN")
        # Sin certificación del roster completo: no hay FRESH inferible.
        self.assertEqual(classify([record()], snapshot_complete=False)["monitor_freshness"], "UNKNOWN")
        self.assertEqual(classify([record()], snapshot_total_count=2)["monitor_freshness"], "UNKNOWN")
        self.assertEqual(freshness.project_run_freshness([record()], now=NOW)["monitor_freshness"], "UNKNOWN")

    def test_successful_run_does_not_imply_healthy_product(self):
        for outcome in ("success", "failure"):
            with self.subTest(conclusion=outcome):
                got = classify([record(conclusion=outcome)], now=NOW)
                self.assertEqual(got["monitor_freshness"], "FRESH")
                self.assertEqual(got["run_conclusion"], outcome)
                self.assertEqual(got["product_health"], "UNKNOWN")
                self.assertNotIn("HEALTHY", json.dumps(got))
        self.assertEqual(classify([record(minutes_ago=50)], now=NOW)["product_health"], "UNKNOWN")

    def test_output_is_safe_and_no_network_access(self):
        danger = "secret=example-only token=example-only user@example.org"
        clean = record(note=danger, html_url="https://example.org/" + danger)
        with patch("socket.socket", side_effect=AssertionError("network forbidden")):
            result = classify([clean], now=NOW)
        self.assertEqual(result["monitor_freshness"], "FRESH")
        self.assertNotIn(danger, json.dumps(result))
        polluted = record(id=danger, head_sha=danger)
        rejected = classify([polluted], now=NOW)
        self.assertEqual(rejected["monitor_freshness"], "UNKNOWN")
        self.assertNotIn(danger, json.dumps(rejected))
        self.assertEqual(set(result), {
            "monitor_freshness", "product_health", "reason", "workflow_path", "run_id",
            "run_started_at", "age_seconds", "head_sha", "run_conclusion",
        })


if __name__ == "__main__":
    unittest.main()
