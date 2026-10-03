#!/usr/bin/env python3
from __future__ import annotations

import json
import subprocess
import unittest
from pathlib import Path

ROOT = Path(__file__).resolve().parents[1]
UNAVAILABLE_VALUE_KEYS = (
    "tenant_ref",
    "currency",
    "source_window",
    "observed_at",
    "revenue_mrr",
    "cogs",
    "support_cost",
    "gross_margin_amount",
    "gross_margin_percent",
    "contribution_margin_amount",
    "contribution_margin_percent",
    "provenance",
)

PHP_BOOTSTRAP = r"""
require 'vendor/autoload.php';

use App\Application\Commercial\SaasUnitEconomics;
use App\Domain\Commercial\Entity\Plan;
use App\Domain\Commercial\Entity\PlanVersion;
use App\Domain\Commercial\Entity\Subscription;
use App\Domain\Commercial\SubscriptionLifecycle;
use App\Domain\Commercial\SubscriptionState;

function pricedPlan(): PlanVersion {
    return new PlanVersion(
        new Plan('unit', 'Unit'),
        1,
        200000,
        2000000,
        false,
        [],
        new DateTimeImmutable('2026-01-01T00:00:00Z'),
    );
}

function quotePlan(): PlanVersion {
    return new PlanVersion(
        new Plan('quote', 'Quote'),
        1,
        null,
        null,
        true,
        [],
        new DateTimeImmutable('2026-01-01T00:00:00Z'),
    );
}

function subscriptionFor(
    string $tenant,
    PlanVersion $version,
    SubscriptionState $state,
    string $at,
): Subscription {
    $lifecycle = new SubscriptionLifecycle(
        $tenant,
        $version,
        $state,
        new DateTimeImmutable($at),
    );
    return Subscription::fromLifecycle(
        $lifecycle,
        new DateTimeImmutable($at)->modify('+1 second'),
    );
}
"""


def run_php(script: str) -> dict[str, object]:
    raw = subprocess.check_output(
        ["php", "-r", script],
        cwd=ROOT,
        text=True,
        stderr=subprocess.STDOUT,
    )
    return json.loads(raw)


def canonical_costs(currency: str = "COP") -> list[dict[str, object]]:
    amounts = {
        "support": 20000,
        "storage": 10000,
        "ai_compute": 20000,
        "integration": 20000,
        "infrastructure": 40000,
        "messaging": 10000,
    }
    return [
        {
            "category": category,
            "amount": amount,
            "currency": currency,
            "source_ref": f"cost:{category}:202610",
        }
        for category, amount in amounts.items()
    ]


def cost_snapshot(
    *,
    tenant_ref: str = "tenant:unit-a",
    currency: str = "COP",
    costs: list[dict[str, object]] | None = None,
) -> dict[str, object]:
    return {
        "version": 1,
        "tenant_ref": tenant_ref,
        "window_start": "2026-10-01T00:00:00.000000Z",
        "window_end": "2026-11-01T00:00:00.000000Z",
        "observed_at": "2026-11-01T00:05:00.000000Z",
        "costs": canonical_costs(currency) if costs is None else costs,
    }


class UnitEconomicsTests(unittest.TestCase):
    def test_gross_and_contribution_margin_are_derived_from_canonical_revenue_and_costs(self) -> None:
        payload = json.dumps(cost_snapshot(), separators=(",", ":"))
        observed = run_php(
            f"""
{PHP_BOOTSTRAP}
$subscription = subscriptionFor(
    'tenant:unit-a',
    pricedPlan(),
    SubscriptionState::Active,
    '2026-09-01T00:00:00Z',
);
$costs = json_decode({json.dumps(payload)}, true, 512, JSON_THROW_ON_ERROR);
print json_encode(SaasUnitEconomics::derive($subscription, $costs), JSON_THROW_ON_ERROR);
"""
        )

        expected_scalars = {
            "status": "valid",
            "reason": None,
            "tenant_ref": "tenant:unit-a",
            "currency": "COP",
            "observed_at": "2026-11-01T00:05:00.000000Z",
            "revenue_mrr": 200000,
            "cogs": 100000,
            "support_cost": 20000,
            "gross_margin_amount": 100000,
            "gross_margin_percent": 50.0,
            "contribution_margin_amount": 80000,
            "contribution_margin_percent": 40.0,
        }
        self.assertEqual(
            expected_scalars,
            {key: observed[key] for key in expected_scalars},
        )
        self.assertEqual(
            {
                "start": "2026-10-01T00:00:00.000000Z",
                "end": "2026-11-01T00:00:00.000000Z",
            },
            observed["source_window"],
        )

        provenance = observed["provenance"]
        self.assertEqual("subscription_plan_version", provenance["revenue"]["kind"])
        for key in ("subscription_ref", "plan_version_ref"):
            self.assertRegex(provenance["revenue"][key], r"^[0-9A-HJKMNP-TV-Z]{26}$")
        self.assertEqual(
            sorted(row["category"] for row in canonical_costs()),
            [entry["category"] for entry in provenance["costs"]],
        )
        self.assertTrue(
            all(entry["source_ref"].startswith("cost:") for entry in provenance["costs"])
        )

    def test_missing_cost_coverage_mixed_currency_or_ambiguous_revenue_is_unavailable(self) -> None:
        payloads = json.dumps(
            {
                "missing": cost_snapshot(costs=canonical_costs()[:-1]),
                "mixed": cost_snapshot(currency="USD"),
                "tenant": cost_snapshot(tenant_ref="tenant:other"),
                "valid": cost_snapshot(),
            },
            separators=(",", ":"),
        )
        observed = run_php(
            f"""
{PHP_BOOTSTRAP}
$priced = pricedPlan();
$active = subscriptionFor(
    'tenant:unit-a',
    $priced,
    SubscriptionState::Active,
    '2026-09-01T00:00:00Z',
);
$ambiguousRevenue = subscriptionFor(
    'tenant:unit-a',
    quotePlan(),
    SubscriptionState::Active,
    '2026-09-01T00:00:00Z',
);

$partialLifecycle = new SubscriptionLifecycle(
    'tenant:unit-a',
    $priced,
    SubscriptionState::Trialing,
    new DateTimeImmutable('2026-09-01T00:00:00Z'),
);
$partialLifecycle->transitionTo(
    SubscriptionState::Active,
    new DateTimeImmutable('2026-10-15T00:00:00Z'),
);
$partial = Subscription::fromLifecycle(
    $partialLifecycle,
    new DateTimeImmutable('2026-10-15T00:00:01Z'),
);

$payloads = json_decode({json.dumps(payloads)}, true, 512, JSON_THROW_ON_ERROR);
print json_encode([
    'missing' => SaasUnitEconomics::derive($active, $payloads['missing']),
    'mixed' => SaasUnitEconomics::derive($active, $payloads['mixed']),
    'tenant' => SaasUnitEconomics::derive($active, $payloads['tenant']),
    'quote' => SaasUnitEconomics::derive($ambiguousRevenue, $payloads['valid']),
    'partial_window' => SaasUnitEconomics::derive($partial, $payloads['valid']),
], JSON_THROW_ON_ERROR);
"""
        )

        expected_reasons = {
            "missing": "cost_incomplete_cost_coverage",
            "mixed": "mixed_revenue_cost_currency",
            "tenant": "tenant_mismatch",
            "quote": "ambiguous_revenue",
            "partial_window": "ambiguous_revenue_window",
        }
        self.assertEqual(
            expected_reasons,
            {key: result["reason"] for key, result in observed.items()},
        )
        for result in observed.values():
            self.assertEqual("unavailable", result["status"])
            self.assertTrue(all(result[key] is None for key in UNAVAILABLE_VALUE_KEYS))


if __name__ == "__main__":
    unittest.main()
