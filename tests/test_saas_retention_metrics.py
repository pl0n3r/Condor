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

use App\Application\Commercial\PlatformCommercialMetrics;
use App\Application\Commercial\SaasRetentionMetrics;
use App\Application\Commercial\SaasRevenueMovements;
use App\Domain\Commercial\Entity\Plan;
use App\Domain\Commercial\Entity\PlanVersion;
use App\Domain\Commercial\Entity\Subscription;
use App\Domain\Commercial\SubscriptionChange;
use App\Domain\Commercial\SubscriptionLifecycle;
use App\Domain\Commercial\SubscriptionState;

$basic = new PlanVersion(
    new Plan('basic', 'Básico'),
    1,
    100000,
    1000000,
    false,
    [],
    new DateTimeImmutable('2026-01-01T00:00:00Z'),
);
$growth = new PlanVersion(
    new Plan('growth', 'Growth'),
    1,
    160000,
    1600000,
    false,
    [],
    new DateTimeImmutable('2026-01-01T00:00:00Z'),
);

$baselineLifecycleA = new SubscriptionLifecycle(
    'tenant-a',
    $basic,
    SubscriptionState::Active,
    new DateTimeImmutable('2026-09-01T00:00:00Z'),
);
$baselineLifecycleB = new SubscriptionLifecycle(
    'tenant-b',
    $basic,
    SubscriptionState::Active,
    new DateTimeImmutable('2026-09-01T00:00:00Z'),
);
$baselineA = Subscription::fromLifecycle(
    $baselineLifecycleA,
    new DateTimeImmutable('2026-09-01T00:00:01Z'),
);
$baselineB = Subscription::fromLifecycle(
    $baselineLifecycleB,
    new DateTimeImmutable('2026-09-01T00:00:01Z'),
);

$endingLifecycleA = new SubscriptionLifecycle(
    'tenant-a',
    $basic,
    SubscriptionState::Active,
    new DateTimeImmutable('2026-09-01T00:00:00Z'),
);
$endingLifecycleB = new SubscriptionLifecycle(
    'tenant-b',
    $growth,
    SubscriptionState::Active,
    new DateTimeImmutable('2026-09-01T00:00:00Z'),
);
$endingA = Subscription::fromLifecycle(
    $endingLifecycleA,
    new DateTimeImmutable('2026-10-31T23:59:58Z'),
);
$endingB = Subscription::fromLifecycle(
    $endingLifecycleB,
    new DateTimeImmutable('2026-10-31T23:59:58Z'),
);

$movementLifecycleA = new SubscriptionLifecycle(
    'tenant-a',
    $basic,
    SubscriptionState::Active,
    new DateTimeImmutable('2026-09-01T00:00:00Z'),
);
$movementLifecycleB = new SubscriptionLifecycle(
    'tenant-b',
    $basic,
    SubscriptionState::Active,
    new DateTimeImmutable('2026-09-01T00:00:00Z'),
);
$movementA = Subscription::fromLifecycle(
    $movementLifecycleA,
    new DateTimeImmutable('2026-10-31T23:59:59Z'),
);
$movementB = Subscription::fromLifecycle(
    $movementLifecycleB,
    new DateTimeImmutable('2026-10-31T23:59:59Z'),
);

$upgrade = SubscriptionChange::upgrade(
    'tenant-b',
    $basic,
    $growth,
    new DateTimeImmutable('2026-10-15T12:00:00Z'),
);
$scheduledDowngrade = SubscriptionChange::downgrade(
    'tenant-b',
    $growth,
    $basic,
    new DateTimeImmutable('2026-10-20T12:00:00Z'),
    new DateTimeImmutable('2026-10-25T12:00:00Z'),
    true,
);

$baseline = PlatformCommercialMetrics::derive([$baselineA, $baselineB]);
$ending = PlatformCommercialMetrics::derive([$endingA, $endingB]);
$movements = SaasRevenueMovements::derive(
    [$movementA, $movementB],
    [$upgrade, $scheduledDowngrade],
);

print json_encode(
    SaasRetentionMetrics::derive(
        $baseline,
        $ending,
        $movements,
        new DateTimeImmutable('2026-10-01T00:00:00Z'),
        new DateTimeImmutable('2026-11-01T00:00:00Z'),
    ),
    JSON_THROW_ON_ERROR,
);
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

$baseline = [
    'status' => 'valid',
    'reason' => null,
    'currency' => 'COP',
    'mrr' => 100000,
    'arr' => 1200000,
    'active_customers' => 1,
    'trials' => 0,
];
$missingBaseline = [
    'status' => 'unavailable',
    'reason' => 'incomplete_active_pricing',
    'currency' => null,
    'mrr' => null,
    'arr' => null,
    'active_customers' => null,
    'trials' => null,
];
$ending = $baseline;
$movements = [
    'status' => 'valid',
    'reason' => null,
    'currency' => 'COP',
    'movements' => [],
];

print json_encode([
    'invalid_window' => SaasRetentionMetrics::derive(
        $baseline,
        $ending,
        $movements,
        new DateTimeImmutable('2026-11-01T00:00:00Z'),
        new DateTimeImmutable('2026-11-01T00:00:00Z'),
    ),
    'missing_baseline' => SaasRetentionMetrics::derive(
        $missingBaseline,
        $ending,
        $movements,
        new DateTimeImmutable('2026-10-01T00:00:00Z'),
        new DateTimeImmutable('2026-11-01T00:00:00Z'),
    ),
], JSON_THROW_ON_ERROR);
"""
        )

        self.assertEqual("invalid_window", observed["invalid_window"]["reason"])
        self.assertEqual("missing_baseline", observed["missing_baseline"]["reason"])
        for case in ("invalid_window", "missing_baseline"):
            result = observed[case]
            self.assertEqual("unavailable", result["status"])
            self.assertIsNone(result["currency"])
            self.assertIsNone(result["baseline_mrr"])
            self.assertIsNone(result["ending_mrr"])
            self.assertIsNone(result["arpa"])
            self.assertIsNone(result["nrr"])


if __name__ == "__main__":
    unittest.main()
