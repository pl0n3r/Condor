#!/usr/bin/env python3
from __future__ import annotations

import json
import subprocess
import unittest
from pathlib import Path

ROOT = Path(__file__).resolve().parents[1]


def run_php(source: str) -> dict[str, object]:
    result = subprocess.run(
        ["php", "-r", source], cwd=ROOT, capture_output=True, text=True, check=False,
    )
    if result.returncode:
        raise AssertionError(result.stdout + result.stderr)
    return json.loads(result.stdout.strip())


FIXTURE = r"""
require 'vendor/autoload.php';
use App\Application\Commercial\SaasMetricsProjection;

$start = new DateTimeImmutable('2026-10-01T00:00:00Z');
$end = new DateTimeImmutable('2026-11-01T00:00:00Z');
$baseline = ['status' => 'valid', 'currency' => 'COP', 'mrr' => 200000, 'arr' => 2400000, 'active_customers' => 2, 'trials' => 0];
$ending = ['status' => 'valid', 'currency' => 'COP', 'mrr' => 260000, 'arr' => 3120000, 'active_customers' => 2, 'trials' => 1];
$revenue = ['status' => 'valid', 'currency' => 'COP', 'movements' => [
    [
        'tenant_id' => 'synthetic-tenant-alpha', 'type' => 'new',
        'amount' => 200000, 'mrr_delta' => 200000,
        'occurred_at' => '2026-09-01T12:00:00.000000Z', 'recognition' => 'effective',
    ],
    [
        'tenant_id' => 'synthetic-tenant-beta', 'type' => 'expansion',
        'amount' => 60000, 'mrr_delta' => 60000,
        'occurred_at' => '2026-10-15T12:00:00.000000Z', 'recognition' => 'effective',
    ],
]];
$audits = [
    [
        'tenant_id' => 'synthetic-tenant-alpha', 'type' => 'new',
        'occurred_at' => '2026-09-01T12:00:00.000000Z', 'recognition' => 'effective',
        'audit_id' => 'synthetic-audit-alpha', 'actor_role' => 'saas_owner',
    ],
    [
        'tenant_id' => 'synthetic-tenant-beta', 'type' => 'expansion',
        'occurred_at' => '2026-10-15T12:00:00.000000Z', 'recognition' => 'effective',
        'audit_id' => 'synthetic-audit-beta', 'actor_role' => 'saas_owner',
    ],
];
"""


class SaasTests(unittest.TestCase):
    def test_aislamiento_por_empresa(self) -> None:
        observed = run_php(FIXTURE + r"""
$ok = SaasMetricsProjection::derive(true, $baseline, $ending, $revenue, $audits, $start, $end);
$wrong = $revenue;
$wrong['movements'][1]['tenant_id'] = 'synthetic-tenant-alpha';
$duplicate = $revenue;
$duplicate['movements'][] = $duplicate['movements'][1];
print json_encode([
    'ok' => $ok,
    'wrong_tenant' => SaasMetricsProjection::derive(true, $baseline, $ending, $wrong, $audits, $start, $end),
    'duplicate' => SaasMetricsProjection::derive(true, $baseline, $ending, $duplicate, $audits, $start, $end),
], JSON_THROW_ON_ERROR);
""")
        ok = observed["ok"]
        self.assertEqual("valid", ok["status"])
        self.assertEqual("COP", ok["currency"])
        self.assertEqual(260000, ok["mrr_minor"])
        self.assertEqual(3120000, ok["arr_minor"])
        self.assertEqual(2, ok["active_customers"])
        self.assertEqual(1, ok["trials"])
        self.assertEqual({"numerator": 130000, "denominator": 1}, ok["arpa_minor_exact"])
        self.assertEqual({"numerator": 130, "denominator": 1}, ok["nrr_percent_exact"])
        self.assertEqual(60000, ok["expansion_mrr_minor"])
        self.assertEqual(0, ok["new_mrr_minor"])
        self.assertEqual(2, ok["audit_count"])
        self.assertNotIn("synthetic-tenant-alpha", json.dumps(ok))
        self.assertNotIn("synthetic-tenant-beta", json.dumps(ok))
        self.assertEqual("unavailable", observed["wrong_tenant"]["status"])
        self.assertEqual("unavailable", observed["duplicate"]["status"])

    def test_todos_los_movimientos_y_fracciones_exactas(self) -> None:
        observed = run_php(FIXTURE + r"""
$ending['mrr'] = 215000;
$ending['arr'] = 2580000;
$revenue['movements'] = [
    [
        'tenant_id' => 'synthetic-tenant-alpha', 'type' => 'new',
        'amount' => 5000, 'mrr_delta' => 5000,
        'occurred_at' => '2026-10-02T00:00:00.000000Z', 'recognition' => 'effective',
    ],
    [
        'tenant_id' => 'synthetic-tenant-beta', 'type' => 'expansion',
        'amount' => 60000, 'mrr_delta' => 60000,
        'occurred_at' => '2026-10-03T00:00:00.000000Z', 'recognition' => 'effective',
    ],
    [
        'tenant_id' => 'synthetic-tenant-alpha', 'type' => 'contraction',
        'amount' => 20000, 'mrr_delta' => -20000,
        'occurred_at' => '2026-10-04T00:00:00.000000Z', 'recognition' => 'effective',
    ],
    [
        'tenant_id' => 'synthetic-tenant-gamma', 'type' => 'churn',
        'amount' => 30000, 'mrr_delta' => -30000,
        'occurred_at' => '2026-10-05T00:00:00.000000Z', 'recognition' => 'effective',
    ],
];
$audits = [];
foreach ($revenue['movements'] as $index => $m) {
    $audits[] = [
        'tenant_id' => $m['tenant_id'], 'type' => $m['type'],
        'occurred_at' => $m['occurred_at'], 'recognition' => 'effective',
        'actor_role' => 'saas_owner',
        'audit_id' => 'synthetic-audit-movement-'.$index,
    ];
}
print json_encode(SaasMetricsProjection::derive(
    true, $baseline, $ending, $revenue, $audits, $start, $end
), JSON_THROW_ON_ERROR);
""")
        self.assertEqual("valid", observed["status"])
        self.assertEqual(5000, observed["new_mrr_minor"])
        self.assertEqual(60000, observed["expansion_mrr_minor"])
        self.assertEqual(20000, observed["contraction_mrr_minor"])
        self.assertEqual(30000, observed["churned_mrr_minor"])
        self.assertEqual(4, observed["audit_count"])
        self.assertEqual({"numerator": 105, "denominator": 1},
                         observed["nrr_percent_exact"])
        self.assertEqual({"numerator": 107500, "denominator": 1},
                         observed["arpa_minor_exact"])

    def test_auditoria_y_permisos(self) -> None:
        observed = run_php(FIXTURE + r"""
$unauthorized = $audits;
$unauthorized[1]['actor_role'] = 'reader';
$duplicate = $audits;
$duplicate[] = $audits[1];
$missing = [$audits[0]];
print json_encode([
    'valid' => SaasMetricsProjection::derive(true, $baseline, $ending, $revenue, $audits, $start, $end),
    'non_owner' => SaasMetricsProjection::derive(false, $baseline, $ending, $revenue, $audits, $start, $end),
    'unauthorized' => SaasMetricsProjection::derive(true, $baseline, $ending, $revenue, $unauthorized, $start, $end),
    'duplicate' => SaasMetricsProjection::derive(true, $baseline, $ending, $revenue, $duplicate, $start, $end),
    'missing' => SaasMetricsProjection::derive(true, $baseline, $ending, $revenue, $missing, $start, $end),
], JSON_THROW_ON_ERROR);
""")
        self.assertEqual("valid", observed["valid"]["status"])
        self.assertEqual("owner_required", observed["non_owner"]["reason"])
        self.assertEqual("unauthorized_or_real_audit", observed["unauthorized"]["reason"])
        self.assertEqual("invalid_audit_evidence", observed["duplicate"]["reason"])
        self.assertEqual("missing_audit", observed["missing"]["reason"])
        for key in ("non_owner", "unauthorized", "duplicate", "missing"):
            self.assertEqual("unavailable", observed[key]["status"])
            self.assertIsNone(observed[key]["mrr_minor"])

    def test_sin_datos_reales(self) -> None:
        observed = run_php(FIXTURE + r"""
use App\Domain\Commercial\Entity\Plan;
use App\Domain\Commercial\Entity\PlanVersion;
use App\Domain\Commercial\Entity\Subscription;
use App\Domain\Commercial\SubscriptionLifecycle;
use App\Domain\Commercial\SubscriptionState;

$version = new PlanVersion(
    new Plan('synthetic-starter', 'Synthetic Starter'),
    1, 100000, 1200000, false, [],
    new DateTimeImmutable('2026-01-01T00:00:00Z'),
);
$active = Subscription::fromLifecycle(new SubscriptionLifecycle(
    'synthetic-tenant-alpha', $version, SubscriptionState::Active,
    new DateTimeImmutable('2026-09-01T00:00:00Z')
), new DateTimeImmutable('2026-09-01T00:00:01Z'));
$trial = Subscription::fromLifecycle(new SubscriptionLifecycle(
    'synthetic-tenant-beta', $version, SubscriptionState::Trialing,
    new DateTimeImmutable('2026-09-05T00:00:00Z')
), new DateTimeImmutable('2026-09-05T00:00:01Z'));
$receipt = [[
    'tenant_id' => 'synthetic-tenant-alpha', 'type' => 'new',
    'occurred_at' => '2026-09-01T00:00:00.000000Z',
    'recognition' => 'effective', 'actor_role' => 'saas_owner',
    'audit_id' => 'synthetic-audit-new',
]];
$ok = SaasMetricsProjection::fromSubscriptions(
    true, [$active, $trial], [$active, $trial], [$active, $trial],
    [], $receipt, $start, $end
);
$real = Subscription::fromLifecycle(new SubscriptionLifecycle(
    'customer@example.com', $version, SubscriptionState::Active,
    new DateTimeImmutable('2026-09-01T00:00:00Z')
), new DateTimeImmutable('2026-09-01T00:00:01Z'));
$foreign = Subscription::fromLifecycle(new SubscriptionLifecycle(
    'synthetic-tenant-foreign', $version, SubscriptionState::Active,
    new DateTimeImmutable('2026-09-01T00:00:00Z')
), new DateTimeImmutable('2026-09-01T00:00:01Z'));
print json_encode([
    'valid' => $ok,
    'real' => SaasMetricsProjection::fromSubscriptions(
        true, [$real], [$real], [$real], [], $receipt, $start, $end
    ),
    'foreign' => SaasMetricsProjection::fromSubscriptions(
        true, [$active, $trial], [$active, $trial], [$active, $trial, $foreign],
        [], $receipt, $start, $end
    ),
], JSON_THROW_ON_ERROR);
""")
        self.assertEqual("valid", observed["valid"]["status"])
        self.assertEqual(100000, observed["valid"]["mrr_minor"])
        self.assertEqual(1200000, observed["valid"]["arr_minor"])
        self.assertEqual(1, observed["valid"]["trials"])
        self.assertEqual({"numerator": 100, "denominator": 1},
                         observed["valid"]["nrr_percent_exact"])
        self.assertEqual("real_or_invalid_tenant", observed["real"]["reason"])
        self.assertEqual("invalid_tenant_scope", observed["foreign"]["reason"])
        self.assertNotIn("customer@example.com", json.dumps(observed))

    def test_fail_closed(self) -> None:
        observed = run_php(FIXTURE + r"""
$missing = $baseline;
$missing['status'] = 'unavailable';
$badCurrency = $ending;
$badCurrency['currency'] = 'USD';
$badArr = $ending;
$badArr['arr'] = 1;
$realAudit = $audits;
$realAudit[0]['audit_id'] = 'email@example.com';
$badWindow = $start;
$badSource = $revenue;
$badSource['status'] = 'unavailable';
print json_encode([
    'missing' => SaasMetricsProjection::derive(true, $missing, $ending, $revenue, $audits, $start, $end),
    'currency' => SaasMetricsProjection::derive(true, $baseline, $badCurrency, $revenue, $audits, $start, $end),
    'arr' => SaasMetricsProjection::derive(true, $baseline, $badArr, $revenue, $audits, $start, $end),
    'real_audit' => SaasMetricsProjection::derive(true, $baseline, $ending, $revenue, $realAudit, $start, $end),
    'window' => SaasMetricsProjection::derive(true, $baseline, $ending, $revenue, $audits, $badWindow, $badWindow),
    'source' => SaasMetricsProjection::derive(true, $baseline, $ending, $badSource, $audits, $start, $end),
], JSON_THROW_ON_ERROR);
""")
        for key, value in observed.items():
            with self.subTest(case=key):
                self.assertEqual("unavailable", value["status"])
                self.assertIsNone(value["mrr_minor"])
                self.assertIsNone(value["nrr_percent_exact"])
        self.assertEqual("invalid_window", observed["window"]["reason"])


if __name__ == "__main__":
    unittest.main()
