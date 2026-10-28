#!/usr/bin/env python3
"""Condor #1071: executable offline per-tenant unit economics acceptance."""
from __future__ import annotations

import json
import subprocess
import unittest
from pathlib import Path

ROOT = Path(__file__).resolve().parents[1]

PHP_FIXTURE = r"""
require 'vendor/autoload.php';
use App\Application\Commercial\UnitEconomicsProjection;
use App\Application\Commercial\EntitlementSnapshot;
use App\Domain\Commercial\Entity\Plan;
use App\Domain\Commercial\Entity\PlanVersion;
use App\Domain\Commercial\Entity\Subscription;
use App\Domain\Commercial\SubscriptionLifecycle;
use App\Domain\Commercial\SubscriptionState;

function syntheticRecord(string $tenant, int $revenue, int $infrastructure, array $audits = []): array {
    $version = new PlanVersion(
        new Plan('biz', 'Business'), 1, $revenue, $revenue * 10,
        false, [], new DateTimeImmutable('2026-01-01T00:00:00Z'),
    );
    $lifecycle = new SubscriptionLifecycle(
        $tenant, $version, SubscriptionState::Active,
        new DateTimeImmutable('2026-09-01T00:00:00Z'),
    );
    $subscription = Subscription::fromLifecycle(
        $lifecycle, new DateTimeImmutable('2026-09-01T00:00:01Z'),
    );
    $entitlements = new EntitlementSnapshot(
        $tenant, 'biz', 1, 'retail', [], [], [], $audits,
        new DateTimeImmutable('2026-11-01T00:00:00Z'),
    );
    $costs = [];
    foreach (['support' => 20000, 'storage' => 10000, 'ai_compute' => 20000,
        'integration' => 20000, 'infrastructure' => $infrastructure,
        'messaging' => 10000] as $category => $amount) {
        $costs[] = [
            'category' => $category, 'amount' => $amount, 'currency' => 'COP',
            'source_ref' => 'cost:synthetic-'.$category,
        ];
    }
    return [
        'subscription' => $subscription, 'entitlements' => $entitlements,
        'cost_evidence' => [
            'version' => 1, 'tenant_ref' => $tenant,
            'window_start' => '2026-10-01T00:00:00.000000Z',
            'window_end' => '2026-11-01T00:00:00.000000Z',
            'observed_at' => '2026-11-01T00:05:00.000000Z',
            'costs' => $costs,
        ],
    ];
}
$now = new DateTimeImmutable('2026-11-01T00:30:00Z');
"""


def run_php(source: str) -> dict[str, object]:
    completed = subprocess.run(
        ["php", "-r", PHP_FIXTURE + source],
        cwd=ROOT, capture_output=True, text=True, check=False,
    )
    if completed.returncode != 0:
        raise AssertionError(completed.stdout + completed.stderr)
    return json.loads(completed.stdout)


class SaasTests(unittest.TestCase):
    def test_aislamiento_por_empresa(self) -> None:
        actual = run_php(r"""
$a = syntheticRecord('tenant:synthetic-alpha', 200000, 40000);
$b = syntheticRecord('tenant:synthetic-beta', 300000, 90000);
print json_encode(UnitEconomicsProjection::project(true, [$b, $a], $now), JSON_THROW_ON_ERROR);
""")
        self.assertEqual("valid", actual["status"])
        self.assertEqual(["tenant:synthetic-alpha", "tenant:synthetic-beta"],
                         [row["tenant_ref"] for row in actual["rows"]])
        alpha, beta = actual["rows"]
        self.assertEqual((100000, 80000),
                         (alpha["gross_margin_amount"], alpha["contribution_margin_amount"]))
        self.assertEqual((150000, 130000),
                         (beta["gross_margin_amount"], beta["contribution_margin_amount"]))
        self.assertEqual("biz", alpha["plan_key"])
        self.assertEqual("retail", beta["vertical_key"])
        self.assertNotEqual(alpha["provenance"]["revenue"]["subscription_ref"],
                            beta["provenance"]["revenue"]["subscription_ref"])
        self.assertEqual({"tenant:synthetic-alpha", "tenant:synthetic-beta"},
                         {r["tenant_ref"] for r in actual["rows"]})

    def test_auditoria_y_permisos(self) -> None:
        actual = run_php(r"""
$audit = [[
    'namespace' => 'limit', 'key' => 'seats', 'value' => 3,
    'reason' => 'synthetic_adjustment', 'actor' => 'actor:synthetic-owner',
    'created_at' => '2026-10-01T00:00:00Z',
]];
$row = syntheticRecord('tenant:synthetic-alpha', 200000, 40000, $audit);
$without = UnitEconomicsProjection::project(false, [$row], $now);
$with = UnitEconomicsProjection::project(true, [$row], $now);
$invalid = $audit;
$invalid[0]['actor'] = 'person@example.com';
$invalidRow = syntheticRecord('tenant:synthetic-alpha', 200000, 40000, $invalid);
$duplicateAudits = syntheticRecord('tenant:synthetic-alpha', 200000, 40000, [$audit[0], $audit[0]]);
print json_encode([
    'without' => $without,
    'with' => $with,
    'invalid' => UnitEconomicsProjection::project(true, [$invalidRow], $now),
    'duplicate' => UnitEconomicsProjection::project(true, [$duplicateAudits], $now),
], JSON_THROW_ON_ERROR);
""")
        self.assertEqual("UNKNOWN", actual["without"]["status"])
        self.assertEqual("owner_required", actual["without"]["reason"])
        self.assertEqual([], actual["without"]["rows"])
        self.assertEqual("valid", actual["with"]["status"])
        self.assertEqual(1, actual["with"]["rows"][0]["audit_count"])
        self.assertEqual("UNKNOWN", actual["invalid"]["status"])
        self.assertEqual("invalid_audit", actual["invalid"]["reason"])
        self.assertEqual("duplicate_audit", actual["duplicate"]["reason"])

    def test_sin_datos_reales(self) -> None:
        actual = run_php(r"""
$realTenant = syntheticRecord('person@example.com', 200000, 40000);
$realSource = syntheticRecord('tenant:synthetic-alpha', 200000, 40000);
$realSource['cost_evidence']['costs'][0]['source_ref'] = 'person@example.com';
$extra = syntheticRecord('tenant:synthetic-alpha', 200000, 40000);
$extra['cost_evidence']['email'] = 'person@example.com';
print json_encode([
    'tenant' => UnitEconomicsProjection::project(true, [$realTenant], $now),
    'source' => UnitEconomicsProjection::project(true, [$realSource], $now),
    'extra' => UnitEconomicsProjection::project(true, [$extra], $now),
], JSON_THROW_ON_ERROR);
""")
        for result in actual.values():
            self.assertEqual("UNKNOWN", result["status"])
            self.assertNotIn("person@example.com", json.dumps(result))
            if result["reason"] == "incomplete_evidence":
                self.assertIsNone(result["rows"][0]["gross_margin_amount"])
            else:
                self.assertEqual([], result["rows"])

    def test_fail_closed(self) -> None:
        actual = run_php(r"""
$valid = syntheticRecord('tenant:synthetic-alpha', 200000, 40000);
$missing = $valid;
array_pop($missing['cost_evidence']['costs']);
$inconsistent = $valid;
$inconsistent['cost_evidence']['tenant_ref'] = 'tenant:synthetic-beta';
$duplicate = UnitEconomicsProjection::project(true, [$valid, $valid], $now);
$missingResult = UnitEconomicsProjection::project(true, [$missing], $now);
print json_encode([
    'missing' => $missingResult,
    'inconsistent' => UnitEconomicsProjection::project(true, [$inconsistent], $now),
    'duplicate' => $duplicate,
], JSON_THROW_ON_ERROR);
""")
        missing = actual["missing"]
        self.assertEqual("UNKNOWN", missing["status"])
        self.assertEqual("UNKNOWN", missing["rows"][0]["status"])
        self.assertIsNone(missing["rows"][0]["gross_margin_amount"])
        self.assertIsNone(missing["rows"][0]["contribution_margin_amount"])
        self.assertEqual([], actual["inconsistent"]["rows"])
        self.assertEqual("cost_tenant_mismatch", actual["inconsistent"]["reason"])
        self.assertEqual([], actual["duplicate"]["rows"])
        self.assertEqual("tenant_or_plan_mismatch", actual["duplicate"]["reason"])


if __name__ == "__main__":
    unittest.main()
