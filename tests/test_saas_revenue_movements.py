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


class SaasRevenueMovementsTests(unittest.TestCase):
    def test_revenue_movements_are_derived_from_canonical_subscription_changes(self) -> None:
        observed = run_php(
            r'''
require 'vendor/autoload.php';

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
$lean = new PlanVersion(
    new Plan('lean', 'Lean'),
    1,
    120000,
    1200000,
    false,
    [],
    new DateTimeImmutable('2026-01-01T00:00:00Z'),
);

$lifecycle = new SubscriptionLifecycle(
    'tenant-a',
    $basic,
    SubscriptionState::Active,
    new DateTimeImmutable('2026-10-01T10:00:00Z'),
);
$lifecycle->transitionTo(
    SubscriptionState::Cancelled,
    new DateTimeImmutable('2026-10-05T10:00:00Z'),
);
$subscription = Subscription::fromLifecycle(
    $lifecycle,
    new DateTimeImmutable('2026-10-05T10:00:01Z'),
);

$upgrade = SubscriptionChange::upgrade(
    'tenant-a',
    $basic,
    $growth,
    new DateTimeImmutable('2026-10-02T10:00:00Z'),
);
$downgrade = SubscriptionChange::downgrade(
    'tenant-a',
    $growth,
    $lean,
    new DateTimeImmutable('2026-10-03T10:00:00Z'),
    new DateTimeImmutable('2026-10-04T10:00:00Z'),
    true,
);

print json_encode(
    SaasRevenueMovements::derive([$subscription], [$upgrade, $downgrade]),
    JSON_THROW_ON_ERROR,
);
'''
        )

        self.assertEqual("valid", observed["status"])
        self.assertEqual("COP", observed["currency"])
        self.assertEqual(
            [
                {
                    "tenant_id": "tenant-a",
                    "type": "new",
                    "amount": 100000,
                    "mrr_delta": 100000,
                    "occurred_at": "2026-10-01T10:00:00.000000Z",
                    "recognition": "effective",
                },
                {
                    "tenant_id": "tenant-a",
                    "type": "expansion",
                    "amount": 60000,
                    "mrr_delta": 60000,
                    "occurred_at": "2026-10-02T10:00:00.000000Z",
                    "recognition": "effective",
                },
                {
                    "tenant_id": "tenant-a",
                    "type": "contraction",
                    "amount": 40000,
                    "mrr_delta": -40000,
                    "occurred_at": "2026-10-04T10:00:00.000000Z",
                    "recognition": "scheduled",
                },
                {
                    "tenant_id": "tenant-a",
                    "type": "churn",
                    "amount": 160000,
                    "mrr_delta": -160000,
                    "occurred_at": "2026-10-05T10:00:00.000000Z",
                    "recognition": "effective",
                },
            ],
            observed["movements"],
        )

    def test_incomplete_or_ambiguous_changes_fail_closed_without_fabricating_mrr(self) -> None:
        observed = run_php(
            r'''
require 'vendor/autoload.php';

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
$other = new PlanVersion(
    new Plan('other', 'Otro'),
    1,
    110000,
    1100000,
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
$quote = new PlanVersion(
    new Plan('quote', 'Cotizable'),
    1,
    null,
    null,
    true,
    [],
    new DateTimeImmutable('2026-01-01T00:00:00Z'),
);

$lifecycle = new SubscriptionLifecycle(
    'tenant-a',
    $basic,
    SubscriptionState::Active,
    new DateTimeImmutable('2026-10-01T10:00:00Z'),
);
$subscription = Subscription::fromLifecycle(
    $lifecycle,
    new DateTimeImmutable('2026-10-01T10:00:01Z'),
);

$ambiguous = SubscriptionChange::upgrade(
    'tenant-a',
    $other,
    $growth,
    new DateTimeImmutable('2026-10-02T10:00:00Z'),
);
$incomplete = SubscriptionChange::upgrade(
    'tenant-a',
    $basic,
    $quote,
    new DateTimeImmutable('2026-10-02T11:00:00Z'),
);

print json_encode([
    'ambiguous' => SaasRevenueMovements::derive([$subscription], [$ambiguous]),
    'incomplete' => SaasRevenueMovements::derive([$subscription], [$incomplete]),
], JSON_THROW_ON_ERROR);
'''
        )

        for case in ("ambiguous", "incomplete"):
            result = observed[case]
            self.assertEqual("unavailable", result["status"])
            self.assertEqual(
                "invalid_or_ambiguous_commercial_evidence",
                result["reason"],
            )
            self.assertIsNone(result["currency"])
            self.assertIsNone(result["movements"])


if __name__ == "__main__":
    unittest.main()
