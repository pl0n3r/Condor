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
        stdout=subprocess.PIPE,
        stderr=subprocess.STDOUT,
        check=False,
    )
    if result.returncode:
        raise AssertionError(result.stdout)
    return json.loads(result.stdout)


PHP_FIXTURE = r"""
require 'vendor/autoload.php';

use App\Application\Commercial\PlatformCommercialUnitEconomics;
use App\Application\Commercial\SaasUnitEconomics;
use App\Domain\Commercial\Entity\Plan;
use App\Domain\Commercial\Entity\PlanVersion;
use App\Domain\Commercial\Entity\Subscription;
use App\Domain\Commercial\SubscriptionLifecycle;
use App\Domain\Commercial\SubscriptionState;

function ownerUnitEconomicsSnapshot(): array {
    $planVersion = new PlanVersion(
        new Plan('unit-owner', 'Unit Owner'),
        1,
        200000,
        2000000,
        false,
        [],
        new DateTimeImmutable('2026-01-01T00:00:00Z'),
    );
    $lifecycle = new SubscriptionLifecycle(
        'tenant:owner-a',
        $planVersion,
        SubscriptionState::Active,
        new DateTimeImmutable('2026-09-01T00:00:00Z'),
    );
    $subscription = Subscription::fromLifecycle(
        $lifecycle,
        new DateTimeImmutable('2026-09-01T00:00:01Z'),
    );

    return SaasUnitEconomics::derive($subscription, [
        'version' => 1,
        'tenant_ref' => 'tenant:owner-a',
        'window_start' => '2026-10-01T00:00:00.000000Z',
        'window_end' => '2026-11-01T00:00:00.000000Z',
        'observed_at' => '2026-11-01T00:05:00.000000Z',
        'costs' => [
            ['category' => 'support', 'amount' => 20000, 'currency' => 'COP', 'source_ref' => 'cost:support:202610'],
            ['category' => 'storage', 'amount' => 10000, 'currency' => 'COP', 'source_ref' => 'cost:storage:202610'],
            ['category' => 'ai_compute', 'amount' => 20000, 'currency' => 'COP', 'source_ref' => 'cost:ai:202610'],
            ['category' => 'integration', 'amount' => 20000, 'currency' => 'COP', 'source_ref' => 'cost:integration:202610'],
            ['category' => 'infrastructure', 'amount' => 40000, 'currency' => 'COP', 'source_ref' => 'cost:infra:202610'],
            ['category' => 'messaging', 'amount' => 10000, 'currency' => 'COP', 'source_ref' => 'cost:messaging:202610'],
        ],
    ]);
}
"""


class OwnerUnitEconomicsTests(unittest.TestCase):
    def test_owner_reads_tenant_unit_economics_with_source_window_and_freshness(self) -> None:
        observed = run_php(
            PHP_FIXTURE
            + r"""
$snapshot = ownerUnitEconomicsSnapshot();
print json_encode(
    PlatformCommercialUnitEconomics::read(
        true,
        'tenant:owner-a',
        'COP',
        $snapshot,
        new DateTimeImmutable('2026-11-01T00:30:00Z'),
        86400,
    ),
    JSON_THROW_ON_ERROR,
);
"""
        )

        self.assertEqual("valid", observed["status"])
        self.assertIsNone(observed["reason"])
        self.assertEqual("tenant:owner-a", observed["tenant_ref"])
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
            {
                "age_seconds": 1500,
                "status": "fresh",
                "threshold_seconds": 86400,
            },
            observed["freshness"],
        )
        self.assertEqual(
            {
                "gross_margin_amount": 100000,
                "gross_margin_percent": "50",
                "contribution_margin_amount": 80000,
                "contribution_margin_percent": "40",
            },
            observed["metrics"],
        )
        self.assertEqual("subscription_plan_version", observed["provenance"]["revenue"]["kind"])
        self.assertEqual(6, len(observed["provenance"]["costs"]))
        self.assertTrue(
            all(row["source_ref"].startswith("cost:") for row in observed["provenance"]["costs"])
        )

    def test_non_owner_stale_or_incoherent_evidence_fails_closed(self) -> None:
        observed = run_php(
            PHP_FIXTURE
            + r"""
$valid = ownerUnitEconomicsSnapshot();

$tenantMismatch = $valid;
$tenantMismatch['tenant_ref'] = 'tenant:other';

$currencyMismatch = $valid;
$currencyMismatch['currency'] = 'USD';

$incoherent = $valid;
$incoherent['source_window'] = [
    'start' => '2026-11-02T00:00:00.000000Z',
    'end' => '2026-11-01T00:00:00.000000Z',
];

$incomplete = $valid;
unset($incomplete['provenance']['costs']);

$now = new DateTimeImmutable('2026-11-01T00:30:00Z');

print json_encode([
    'non_owner' => PlatformCommercialUnitEconomics::read(
        false, 'tenant:owner-a', 'COP', $valid, $now, 86400,
    ),
    'stale' => PlatformCommercialUnitEconomics::read(
        true,
        'tenant:owner-a',
        'COP',
        $valid,
        new DateTimeImmutable('2026-11-03T00:30:00Z'),
        86400,
    ),
    'tenant' => PlatformCommercialUnitEconomics::read(
        true, 'tenant:owner-a', 'COP', $tenantMismatch, $now, 86400,
    ),
    'currency' => PlatformCommercialUnitEconomics::read(
        true, 'tenant:owner-a', 'COP', $currencyMismatch, $now, 86400,
    ),
    'window' => PlatformCommercialUnitEconomics::read(
        true, 'tenant:owner-a', 'COP', $incoherent, $now, 86400,
    ),
    'incomplete' => PlatformCommercialUnitEconomics::read(
        true, 'tenant:owner-a', 'COP', $incomplete, $now, 86400,
    ),
], JSON_THROW_ON_ERROR);
"""
        )

        self.assertEqual(
            {
                "non_owner": "owner_required",
                "stale": "stale_evidence",
                "tenant": "tenant_mismatch",
                "currency": "currency_mismatch",
                "window": "incoherent_source_window",
                "incomplete": "incomplete_provenance",
            },
            {key: value["reason"] for key, value in observed.items()},
        )
        for result in observed.values():
            self.assertEqual("unavailable", result["status"])
            self.assertIsNone(result["tenant_ref"])
            self.assertIsNone(result["currency"])
            self.assertIsNone(result["source_window"])
            self.assertIsNone(result["observed_at"])
            self.assertIsNone(result["freshness"])
            self.assertIsNone(result["metrics"])
            self.assertIsNone(result["provenance"])


if __name__ == "__main__":
    unittest.main()
