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


class SaasMetricsHistoryTests(unittest.TestCase):
    def test_owner_reads_time_series_with_source_window_and_freshness(self) -> None:
        observed = run_php(
            r"""
require 'vendor/autoload.php';

use App\Application\Commercial\SaasMetricsHistory;

function point(
    int $baseline,
    int $ending,
    string $type,
    int $amount,
    string $occurredAt,
    string $windowStart,
    string $windowEnd,
    string $observedAt,
): array {
    $delta = in_array($type, ['contraction', 'churn'], true) ? -$amount : $amount;

    return [
        'baseline_snapshot' => [
            'status' => 'valid', 'currency' => 'COP',
            'mrr' => $baseline, 'active_customers' => 1,
        ],
        'ending_snapshot' => [
            'status' => 'valid', 'currency' => 'COP',
            'mrr' => $ending, 'active_customers' => 1,
        ],
        'revenue_movements' => [
            'status' => 'valid', 'currency' => 'COP',
            'movements' => [[
                'tenant_id' => 'tenant-redacted',
                'type' => $type,
                'amount' => $amount,
                'mrr_delta' => $delta,
                'occurred_at' => $occurredAt,
                'recognition' => 'effective',
            ]],
        ],
        'window_start' => $windowStart,
        'window_end' => $windowEnd,
        'observed_at' => $observedAt,
    ];
}

$history = SaasMetricsHistory::derive(
    true,
    [
        point(
            100000, 150000, 'expansion', 50000,
            '2026-10-01T12:00:00.000000Z',
            '2026-10-01T00:00:00.000000Z',
            '2026-10-02T00:00:00.000000Z',
            '2026-10-02T00:10:00.000000Z',
        ),
        point(
            150000, 120000, 'contraction', 30000,
            '2026-10-02T12:00:00.000000Z',
            '2026-10-02T00:00:00.000000Z',
            '2026-10-03T00:00:00.000000Z',
            '2026-10-03T00:10:00.000000Z',
        ),
    ],
    new DateTimeImmutable('2026-10-03T12:00:00Z'),
    86400,
);

print json_encode($history, JSON_THROW_ON_ERROR);
"""
        )

        self.assertEqual("valid", observed["status"])
        self.assertEqual("COP", observed["currency"])
        self.assertEqual(2, len(observed["points"]))

        first, second = observed["points"]
        expected_source = {
            "baseline": "App\\Application\\Commercial\\PlatformCommercialMetrics",
            "movements": "App\\Application\\Commercial\\SaasRevenueMovements",
            "retention": "App\\Application\\Commercial\\SaasRetentionMetrics",
        }
        self.assertEqual(expected_source, first["source"])
        self.assertEqual(expected_source, second["source"])
        self.assertEqual(
            {
                "start": "2026-10-01T00:00:00.000000Z",
                "end": "2026-10-02T00:00:00.000000Z",
            },
            first["source_window"],
        )
        self.assertEqual("stale", first["freshness"]["status"])
        self.assertEqual("fresh", second["freshness"]["status"])
        self.assertGreater(first["freshness"]["age_seconds"], 86400)
        self.assertLessEqual(second["freshness"]["age_seconds"], 86400)
        self.assertEqual(150000, first["metrics"]["ending_mrr"])
        self.assertEqual(150, first["metrics"]["nrr"])
        self.assertEqual(120000, second["metrics"]["ending_mrr"])
        self.assertEqual(80, second["metrics"]["nrr"])

    def test_non_owner_or_incoherent_history_fails_closed(self) -> None:
        observed = run_php(
            r"""
require 'vendor/autoload.php';

use App\Application\Commercial\SaasMetricsHistory;

$base = [
    'baseline_snapshot' => [
        'status' => 'valid', 'currency' => 'COP',
        'mrr' => 100000, 'active_customers' => 1,
    ],
    'ending_snapshot' => [
        'status' => 'valid', 'currency' => 'COP',
        'mrr' => 100000, 'active_customers' => 1,
    ],
    'revenue_movements' => [
        'status' => 'valid', 'currency' => 'COP', 'movements' => [],
    ],
    'window_start' => '2026-10-01T00:00:00.000000Z',
    'window_end' => '2026-10-02T00:00:00.000000Z',
    'observed_at' => '2026-10-02T00:05:00.000000Z',
];

$overlap = $base;
$overlap['window_start'] = '2026-10-01T12:00:00.000000Z';
$overlap['window_end'] = '2026-10-02T12:00:00.000000Z';
$overlap['observed_at'] = '2026-10-02T12:05:00.000000Z';

$broken = $base;
$broken['baseline_snapshot'] = [
    'status' => 'unavailable', 'currency' => null,
    'mrr' => null, 'active_customers' => null,
];

$now = new DateTimeImmutable('2026-10-03T00:00:00Z');

print json_encode([
    'non_owner' => SaasMetricsHistory::derive(false, [$base], $now),
    'overlap' => SaasMetricsHistory::derive(true, [$base, $overlap], $now),
    'broken' => SaasMetricsHistory::derive(true, [$broken], $now),
], JSON_THROW_ON_ERROR);
"""
        )

        self.assertEqual("owner_required", observed["non_owner"]["reason"])
        self.assertEqual("overlapping_source_windows", observed["overlap"]["reason"])
        self.assertEqual("invalid_window_metrics", observed["broken"]["reason"])
        for result in observed.values():
            self.assertEqual("unavailable", result["status"])
            self.assertIsNone(result["currency"])
            self.assertIsNone(result["points"])


if __name__ == "__main__":
    unittest.main()
