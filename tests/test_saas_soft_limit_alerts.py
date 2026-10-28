#!/usr/bin/env python3
"""Aceptación AC-01..04 de avisos SaaS sintéticos y sin enforcement (#1069)."""
from __future__ import annotations

import json
import subprocess
import unittest
from pathlib import Path

ROOT = Path(__file__).resolve().parents[1]
SOURCE = ROOT / "src/Application/Commercial/SoftLimitAlertProjection.php"

PHP_FIXTURE = r"""
require 'vendor/autoload.php';

use App\Application\Commercial\EntitlementSnapshot;
use App\Application\Commercial\SoftLimitAlertProjection;
use App\Domain\Commercial\UsageLedger;
use App\Domain\Commercial\UsageMetric;
use App\Domain\Commercial\UsageRecord;

function syntheticProjection(
    string $tenant,
    array $limits,
    ?int $used,
    bool $canView = true,
    string $classification = 'synthetic',
    ?string $usageTenant = null,
    bool $validWindow = true,
): array {
    $start = new DateTimeImmutable('2026-10-01T00:00:00Z');
    $end = new DateTimeImmutable('2026-11-01T00:00:00Z');
    $at = new DateTimeImmutable('2026-10-15T00:00:00Z');
    $entitlements = new EntitlementSnapshot(
        $tenant, 'business', 1, 'retail', [], [], $limits, [], $at,
    );
    $ledger = new UsageLedger($usageTenant ?? $tenant);
    if ($used !== null) {
        $ledger->add(new UsageRecord(
            $usageTenant ?? $tenant, UsageMetric::Users, $used,
            $start, $end, $at,
        ));
    }

    return SoftLimitAlertProjection::derive(
        $ledger, $entitlements, $validWindow ? $start : $end,
        $validWindow ? $end : $start, $canView, $classification,
    );
}
"""


def run_php(source: str) -> dict[str, object]:
    completed = subprocess.run(
        ["php", "-r", PHP_FIXTURE + source],
        cwd=ROOT,
        text=True,
        capture_output=True,
        check=False,
    )
    if completed.returncode:
        raise AssertionError(completed.stdout + completed.stderr)
    return json.loads(completed.stdout.strip())


class SaasTests(unittest.TestCase):
    def test_aislamiento_por_empresa(self) -> None:
        result = run_php(r"""
print json_encode([
    'tenant_a' => syntheticProjection('tenant-a', ['users' => 10], 8),
    'tenant_b' => syntheticProjection('tenant-b', ['users' => 20], 20),
    'mixed' => syntheticProjection('tenant-a', ['users' => 10], 8, true, 'synthetic', 'tenant-b'),
], JSON_THROW_ON_ERROR);
""")
        a, b, mixed = result["tenant_a"], result["tenant_b"], result["mixed"]
        self.assertEqual("valid", a["status"])
        self.assertEqual("valid", b["status"])
        self.assertEqual("tenant-a", a["tenant_id"])
        self.assertEqual("tenant-b", b["tenant_id"])
        self.assertEqual(80, a["alerts"][0]["threshold_percent"])
        self.assertEqual(100, b["alerts"][0]["threshold_percent"])
        self.assertEqual("unavailable", mixed["status"])
        self.assertEqual("tenant_mismatch", mixed["reason"])
        self.assertIsNone(mixed["tenant_id"])
        self.assertIsNone(mixed["usage"])
        self.assertIsNone(mixed["alerts"])

    def test_auditoria_y_permisos(self) -> None:
        result = run_php(r"""
print json_encode([
    'denied' => syntheticProjection('tenant-a', ['users' => 10], 8, false),
    'allowed' => syntheticProjection('tenant-a', ['users' => 10], 8, true),
], JSON_THROW_ON_ERROR);
""")
        self.assertEqual("permission_denied", result["denied"]["reason"])
        self.assertIsNone(result["denied"]["alerts"])
        self.assertEqual("valid", result["allowed"]["status"])
        self.assertFalse(result["allowed"]["enforcement"])
        # El slice solo proyecta. Los cambios comerciales siguen bajo RBAC y
        # auditoría del backend canónico, no son ejecutados por esta clase.
        source = SOURCE.read_text(encoding="utf-8")
        for forbidden in ("->persist(", "->flush(", "->remove(", "sendEmail(",
                          "requestPlanChange(", "UPDATE ", "INSERT ", "stripe",
                          "mercadopago", "curl_exec("):
            self.assertNotIn(forbidden, source)

    def test_sin_datos_reales(self) -> None:
        result = run_php(r"""
print json_encode([
    'real' => syntheticProjection('tenant-a', ['users' => 10], 9, true, 'real'),
    'identity' => syntheticProjection('someone@example.com', ['users' => 10], 9),
    'synthetic' => syntheticProjection('tenant-a', ['users' => 10], 9),
], JSON_THROW_ON_ERROR);
""")
        self.assertEqual("synthetic_only", result["real"]["reason"])
        self.assertEqual("tenant_mismatch", result["identity"]["reason"])
        for key in ("real", "identity"):
            self.assertIsNone(result[key]["tenant_id"])
            self.assertIsNone(result[key]["alerts"])
        synthetic = result["synthetic"]
        self.assertEqual("synthetic", synthetic["classification"])
        self.assertEqual("usage-ledger+entitlement-snapshot", synthetic["source"])
        serialized = json.dumps(synthetic).lower()
        for forbidden in ("email", "phone", "nit", "payment_method", "card_number",
                          "address", "customer_name", "password"):
            self.assertNotIn(forbidden, serialized)

    def test_fail_closed(self) -> None:
        result = run_php(r"""
print json_encode([
    'below' => syntheticProjection('tenant-a', ['users' => 100], 79),
    'at_80' => syntheticProjection('tenant-a', ['users' => 100], 80),
    'at_99' => syntheticProjection('tenant-a', ['users' => 100], 99),
    'at_100' => syntheticProjection('tenant-a', ['users' => 100], 100),
    'missing_usage' => syntheticProjection('tenant-a', ['users' => 10], null),
    'missing_limit' => syntheticProjection('tenant-a', [], 5),
    'ambiguous_limit' => syntheticProjection('tenant-a', ['users' => null], 5),
    'unknown_metric' => syntheticProjection('tenant-a', ['other' => 10], 5),
    'protected' => syntheticProjection('tenant-a', ['security' => 10], 5),
    'invalid_window' => syntheticProjection('tenant-a', ['users' => 10], 5, true, 'synthetic', null, false),
], JSON_THROW_ON_ERROR);
""")
        self.assertEqual([], result["below"]["alerts"])
        self.assertEqual(80, result["at_80"]["alerts"][0]["threshold_percent"])
        self.assertEqual(80, result["at_99"]["alerts"][0]["threshold_percent"])
        self.assertEqual(100, result["at_100"]["alerts"][0]["threshold_percent"])
        for key in ("below", "at_80", "at_99", "at_100"):
            self.assertFalse(result[key]["enforcement"])
        for key in ("missing_usage", "missing_limit", "ambiguous_limit",
                    "unknown_metric", "protected", "invalid_window"):
            self.assertEqual("unavailable", result[key]["status"], key)
            self.assertIsNone(result[key]["alerts"], key)
            self.assertIsNone(result[key]["usage"], key)
            self.assertFalse(result[key]["enforcement"], key)


if __name__ == "__main__":
    unittest.main()
