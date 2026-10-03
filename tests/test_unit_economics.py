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


def canonical_costs(currency: str = "COP") -> list[dict[str, object]]:
    return [
        {"category": "support", "amount": 20000, "currency": currency, "source_ref": "cost:support:202610"},
        {"category": "storage", "amount": 10000, "currency": currency, "source_ref": "cost:storage:202610"},
        {"category": "ai_compute", "amount": 20000, "currency": currency, "source_ref": "cost:ai:202610"},
        {"category": "integration", "amount": 20000, "currency": currency, "source_ref": "cost:integration:202610"},
        {"category": "infrastructure", "amount": 40000, "currency": currency, "source_ref": "cost:infra:202610"},
        {"category": "messaging", "amount": 10000, "currency": currency, "source_ref": "cost:messaging:202610"},
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
require 'vendor/autoload.php';

use App\\Application\\Commercial\\SaasUnitEconomics;
use App\\Domain\\Commercial\\Entity\\Plan;
use App\\Domain\\Commercial\\Entity\\PlanVersion;
use App\\Domain\\Commercial\\Entity\\Subscription;
use App\\Domain\\Commercial\\SubscriptionLifecycle;
use App\\Domain\\Commercial\\SubscriptionState;

$version = new PlanVersion(
    new Plan('unit', 'Unit'),
    1,
    200000,
    2000000,
    false,
    [],
    new DateTimeImmutable('2026-01-01T00:00:00Z'),
);
$lifecycle = new SubscriptionLifecycle(
    'tenant:unit-a',
    $version,
    SubscriptionState::Active,
    new DateTimeImmutable('2026-09-01T00:00:00Z'),
);
$subscription = Subscription::fromLifecycle(
    $lifecycle,
    new DateTimeImmutable('2026-09-01T00:00:01Z'),
);
$costs = json_decode({json.dumps(payload)}, true, 512, JSON_THROW_ON_ERROR);
print json_encode(SaasUnitEconomics::derive($subscription, $costs), JSON_THROW_ON_ERROR);
"""
        )

        self.assertEqual("valid", observed["status"])
        self.assertIsNone(observed["reason"])
        self.assertEqual("tenant:unit-a", observed["tenant_ref"])
        self.assertEqual("COP", observed["currency"])
        self.assertEqual(
            {
                "start": "2026-10-01T00:00:00.000000Z",
                "end": "2026-11-01T00:00:00.000000Z",
            },
            observed["source_window"],
        )
        self.assertEqual("2026-11-01T00:05:00.000000Z", observed["observed_at"])
        self.assertEqual(200000, observed["revenue_mrr"])
        self.assertEqual(100000, observed["cogs"])
        self.assertEqual(20000, observed["support_cost"])
        self.assertEqual(100000, observed["gross_margin_amount"])
        self.assertEqual(50.0, observed["gross_margin_percent"])
        self.assertEqual(80000, observed["contribution_margin_amount"])
        self.assertEqual(40.0, observed["contribution_margin_percent"])

        provenance = observed["provenance"]
        self.assertEqual("subscription_plan_version", provenance["revenue"]["kind"])
        self.assertRegex(provenance["revenue"]["subscription_ref"], r"^[0-9A-HJKMNP-TV-Z]{26}$")
        self.assertRegex(provenance["revenue"]["plan_version_ref"], r"^[0-9A-HJKMNP-TV-Z]{26}$")
        self.assertEqual(
            [
                "ai_compute",
                "infrastructure",
                "integration",
                "messaging",
                "storage",
                "support",
            ],
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
require 'vendor/autoload.php';

use App\\Application\\Commercial\\SaasUnitEconomics;
use App\\Domain\\Commercial\\Entity\\Plan;
use App\\Domain\\Commercial\\Entity\\PlanVersion;
use App\\Domain\\Commercial\\Entity\\Subscription;
use App\\Domain\\Commercial\\SubscriptionLifecycle;
use App\\Domain\\Commercial\\SubscriptionState;

$priced = new PlanVersion(
    new Plan('unit', 'Unit'),
    1,
    200000,
    2000000,
    false,
    [],
    new DateTimeImmutable('2026-01-01T00:00:00Z'),
);
$activeLifecycle = new SubscriptionLifecycle(
    'tenant:unit-a',
    $priced,
    SubscriptionState::Active,
    new DateTimeImmutable('2026-09-01T00:00:00Z'),
);
$active = Subscription::fromLifecycle(
    $activeLifecycle,
    new DateTimeImmutable('2026-09-01T00:00:01Z'),
);

$quote = new PlanVersion(
    new Plan('quote', 'Quote'),
    1,
    null,
    null,
    true,
    [],
    new DateTimeImmutable('2026-01-01T00:00:00Z'),
);
$quoteLifecycle = new SubscriptionLifecycle(
    'tenant:unit-a',
    $quote,
    SubscriptionState::Active,
    new DateTimeImmutable('2026-09-01T00:00:00Z'),
);
$ambiguousRevenue = Subscription::fromLifecycle(
    $quoteLifecycle,
    new DateTimeImmutable('2026-09-01T00:00:01Z'),
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

        self.assertEqual("cost_incomplete_cost_coverage", observed["missing"]["reason"])
        self.assertEqual("mixed_revenue_cost_currency", observed["mixed"]["reason"])
        self.assertEqual("tenant_mismatch", observed["tenant"]["reason"])
        self.assertEqual("ambiguous_revenue", observed["quote"]["reason"])
        self.assertEqual("ambiguous_revenue_window", observed["partial_window"]["reason"])

        for result in observed.values():
            self.assertEqual("unavailable", result["status"])
            self.assertIsNone(result["tenant_ref"])
            self.assertIsNone(result["currency"])
            self.assertIsNone(result["source_window"])
            self.assertIsNone(result["observed_at"])
            self.assertIsNone(result["revenue_mrr"])
            self.assertIsNone(result["cogs"])
            self.assertIsNone(result["support_cost"])
            self.assertIsNone(result["gross_margin_amount"])
            self.assertIsNone(result["gross_margin_percent"])
            self.assertIsNone(result["contribution_margin_amount"])
            self.assertIsNone(result["contribution_margin_percent"])
            self.assertIsNone(result["provenance"])


if __name__ == "__main__":
    unittest.main()
