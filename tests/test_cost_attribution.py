#!/usr/bin/env python3
from __future__ import annotations

import json
import subprocess
import unittest
from pathlib import Path

ROOT = Path(__file__).resolve().parents[1]


def run_php(script: str) -> dict[str, object]:
    completed = subprocess.run(
        ("php", "-r", script),
        cwd=ROOT,
        text=True,
        stdout=subprocess.PIPE,
        stderr=subprocess.STDOUT,
        check=False,
    )
    if completed.returncode:
        raise AssertionError(completed.stdout)
    return json.loads(completed.stdout)


def canonical_costs() -> list[dict[str, object]]:
    return [
        {"category": "support", "amount": 12000, "currency": "COP", "source_ref": "cost:support:202610"},
        {"category": "storage", "amount": 4000, "currency": "COP", "source_ref": "cost:storage:202610"},
        {"category": "ai_compute", "amount": 8000, "currency": "COP", "source_ref": "cost:ai:202610"},
        {"category": "integration", "amount": 6000, "currency": "COP", "source_ref": "cost:integration:202610"},
        {"category": "infrastructure", "amount": 30000, "currency": "COP", "source_ref": "cost:infra:202610"},
        {"category": "messaging", "amount": 3000, "currency": "COP", "source_ref": "cost:messaging:202610"},
    ]


def snapshot(costs: list[dict[str, object]] | None = None) -> dict[str, object]:
    return {
        "version": 1,
        "tenant_ref": "tenant:synthetic-a",
        "window_start": "2026-10-01T00:00:00.000000Z",
        "window_end": "2026-11-01T00:00:00.000000Z",
        "observed_at": "2026-11-01T00:05:00.000000Z",
        "costs": canonical_costs() if costs is None else costs,
    }


class CostAttributionTests(unittest.TestCase):
    def test_snapshot_accepts_only_complete_canonical_cost_categories_with_provenance(self) -> None:
        payload = json.dumps(snapshot(), separators=(",", ":"))
        observed = run_php(
            f"""
require 'vendor/autoload.php';

use App\\Application\\Commercial\\CostAttributionSnapshot;

$input = json_decode({json.dumps(payload)}, true, 512, JSON_THROW_ON_ERROR);
print json_encode(CostAttributionSnapshot::derive($input), JSON_THROW_ON_ERROR);
"""
        )

        self.assertEqual("valid", observed["status"])
        self.assertIsNone(observed["reason"])
        self.assertEqual("tenant:synthetic-a", observed["tenant_ref"])
        self.assertEqual("COP", observed["currency"])
        self.assertEqual(
            {
                "start": "2026-10-01T00:00:00.000000Z",
                "end": "2026-11-01T00:00:00.000000Z",
            },
            observed["source_window"],
        )
        self.assertEqual("2026-11-01T00:05:00.000000Z", observed["observed_at"])
        self.assertEqual(
            [
                "ai_compute",
                "infrastructure",
                "integration",
                "messaging",
                "storage",
                "support",
            ],
            [item["category"] for item in observed["costs"]],
        )
        self.assertEqual(63000, observed["total_cost"])
        self.assertTrue(all(item["source_ref"].startswith("cost:") for item in observed["costs"]))

    def test_invalid_currency_window_tenant_or_sensitive_payload_fails_closed(self) -> None:
        missing = snapshot(canonical_costs()[:-1])

        mixed_costs = canonical_costs()
        mixed_costs[0] = {**mixed_costs[0], "currency": "USD"}
        mixed = snapshot(mixed_costs)

        invalid_window = snapshot()
        invalid_window["window_end"] = invalid_window["window_start"]

        personal_tenant = snapshot()
        personal_tenant["tenant_ref"] = "person@example.com"

        sensitive = snapshot()
        sensitive["email"] = "private@example.com"

        duplicate_costs = canonical_costs()
        duplicate_costs[-1] = {**duplicate_costs[-1], "category": "storage"}
        duplicate = snapshot(duplicate_costs)

        provider_payload_costs = canonical_costs()
        provider_payload_costs[0] = {
            **provider_payload_costs[0],
            "provider_payload": {"invoice": "secret-free-but-out-of-contract"},
        }
        provider_payload = snapshot(provider_payload_costs)

        cases = json.dumps(
            {
                "missing": missing,
                "mixed": mixed,
                "window": invalid_window,
                "tenant": personal_tenant,
                "sensitive": sensitive,
                "duplicate": duplicate,
                "provider_payload": provider_payload,
            },
            separators=(",", ":"),
        )
        observed = run_php(
            f"""
require 'vendor/autoload.php';

use App\\Application\\Commercial\\CostAttributionSnapshot;

$cases = json_decode({json.dumps(cases)}, true, 512, JSON_THROW_ON_ERROR);
$out = [];
foreach ($cases as $key => $input) {{
    $out[$key] = CostAttributionSnapshot::derive($input);
}}
print json_encode($out, JSON_THROW_ON_ERROR);
"""
        )

        self.assertEqual(
            {"missing", "mixed", "window", "tenant", "sensitive", "duplicate", "provider_payload"},
            set(observed),
        )
        for result in observed.values():
            self.assertEqual("unavailable", result["status"])
            self.assertIsNotNone(result["reason"])
            self.assertIsNone(result["tenant_ref"])
            self.assertIsNone(result["currency"])
            self.assertIsNone(result["source_window"])
            self.assertIsNone(result["observed_at"])
            self.assertIsNone(result["costs"])
            self.assertIsNone(result["total_cost"])


if __name__ == "__main__":
    unittest.main()
