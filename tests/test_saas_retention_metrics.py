#!/usr/bin/env python3
from __future__ import annotations

import json
import subprocess
import unittest
from pathlib import Path

ROOT = Path(__file__).resolve().parents[1]


def run_php(script: str) -> dict[str, object]:
    result = subprocess.run(
        ["php", "-r", script],
        cwd=ROOT,
        text=True,
        capture_output=True,
        check=False,
    )
    if result.returncode != 0:
        raise AssertionError(result.stdout + result.stderr)
    return json.loads(result.stdout.strip())


class SaasRetentionMetricsTests(unittest.TestCase):
    def test_arpa_and_nrr_are_derived_from_canonical_mrr_and_movements(self) -> None:
        observed = run_php(
            r"""
require 'vendor/autoload.php';

use App\Application\Commercial\SaasRetentionMetrics;

$baseline = ['status' => 'valid', 'currency' => 'COP', 'mrr' => 200000, 'active_customers' => 2];
$ending = ['status' => 'valid', 'currency' => 'COP', 'mrr' => 260000, 'active_customers' => 2];
$movements = [
    'status' => 'valid',
    'currency' => 'COP',
    'movements' => [
        [
            'tenant_id' => 'tenant-a',
            'type' => 'new',
            'amount' => 200000,
            'mrr_delta' => 200000,
            'occurred_at' => '2026-09-01T00:00:00.000000Z',
            'recognition' => 'effective',
        ],
        [
            'tenant_id' => 'tenant-b',
            'type' => 'expansion',
            'amount' => 60000,
            'mrr_delta' => 60000,
            'occurred_at' => '2026-10-15T12:00:00.000000Z',
            'recognition' => 'effective',
        ],
        [
            'tenant_id' => 'tenant-b',
            'type' => 'contraction',
            'amount' => 60000,
            'mrr_delta' => -60000,
            'occurred_at' => '2026-10-25T12:00:00.000000Z',
            'recognition' => 'scheduled',
        ],
    ],
];

print json_encode(SaasRetentionMetrics::derive(
    $baseline,
    $ending,
    $movements,
    new DateTimeImmutable('2026-10-01T00:00:00Z'),
    new DateTimeImmutable('2026-11-01T00:00:00Z'),
), JSON_THROW_ON_ERROR);
"""
        )

        self.assertEqual("valid", observed["status"])
        self.assertEqual("COP", observed["currency"])
        self.assertEqual(200000, observed["baseline_mrr"])
        self.assertEqual(260000, observed["ending_mrr"])
        self.assertEqual(130000, observed["arpa"])
        self.assertEqual(130, observed["nrr"])

    def test_invalid_window_or_missing_baseline_is_unknown_not_zero(self) -> None:
        observed = run_php(
            r"""
require 'vendor/autoload.php';

use App\Application\Commercial\SaasRetentionMetrics;

$valid = ['status' => 'valid', 'currency' => 'COP', 'mrr' => 100000, 'active_customers' => 1];
$missing = ['status' => 'unavailable', 'currency' => null, 'mrr' => null, 'active_customers' => null];
$movements = ['status' => 'valid', 'currency' => 'COP', 'movements' => []];

print json_encode([
    'window' => SaasRetentionMetrics::derive(
        $valid, $valid, $movements,
        new DateTimeImmutable('2026-11-01T00:00:00Z'),
        new DateTimeImmutable('2026-11-01T00:00:00Z'),
    ),
    'baseline' => SaasRetentionMetrics::derive(
        $missing, $valid, $movements,
        new DateTimeImmutable('2026-10-01T00:00:00Z'),
        new DateTimeImmutable('2026-11-01T00:00:00Z'),
    ),
], JSON_THROW_ON_ERROR);
"""
        )

        self.assertEqual("invalid_window", observed["window"]["reason"])
        self.assertEqual("missing_baseline", observed["baseline"]["reason"])
        for result in observed.values():
            self.assertEqual("unavailable", result["status"])
            self.assertIsNone(result["arpa"])
            self.assertIsNone(result["nrr"])


if __name__ == "__main__":
    unittest.main()
