#!/usr/bin/env python3
"""Aceptación ejecutable Compras Lite V1: proveedores tenant-scoped (#654)."""

from __future__ import annotations

import re
import subprocess
import unittest
from pathlib import Path

ROOT = Path(__file__).resolve().parents[1]
SUPPLIER = ROOT / "src/Domain/Purchasing/Entity/Supplier.php"
SERVICE = ROOT / "src/Application/Purchasing/SupplierService.php"
MIGRATION = ROOT / "migrations/Version20261012120000.php"
PHP_TEST = ROOT / "tests/php/Domain/Purchasing/SupplierDomainTest.php"


class PurchasingSuppliersTests(unittest.TestCase):
    def phpunit(self, test: str) -> None:
        runner = ROOT / "vendor/bin/simple-phpunit"
        if not runner.exists():
            self.fail("vendor/bin/simple-phpunit no está disponible")
        result = subprocess.run(
            [str(runner), "--filter", test, str(PHP_TEST)],
            cwd=ROOT,
            text=True,
            capture_output=True,
            check=False,
        )
        self.assertEqual(0, result.returncode, result.stdout + result.stderr)

    def test_supplier_is_tenant_scoped_with_unique_code(self) -> None:
        source = SUPPLIER.read_text(encoding="utf-8")
        self.assertIn("tenant_id", source)
        self.assertIn("uniq_purchasing_supplier_tenant_code", source)
        self.assertIn("uniq_purchasing_supplier_tenant_id", source)
        self.assertIn("Tenant $tenant", source)
        self.assertIn("canonicalCode", source)
        self.assertIn("validPaymentTerms", source)
        self.phpunit("testSupplierNormalizesCodeAndKeepsTenantScope")
        self.phpunit("testSupplierRejectsInvalidCodeNameAndPaymentTerms")
        self.phpunit("testSupplierRejectsInvisibleAndMalformedUnicodeNames")
        self.phpunit("testSupplierTrimsUnicodeWithoutMbstringAndPreservesVisibleNames")

    def test_service_rejects_duplicate_code_within_tenant_but_allows_other_tenant(self) -> None:
        source = SERVICE.read_text(encoding="utf-8")
        self.assertIn("'tenant' => $tenant", source)
        self.assertIn("'code' => $code", source)
        self.assertIn("assertCodeAvailable", source)
        self.assertIn("$supplier->tenant()->id() !== $tenant->id()", source)
        self.phpunit("testServiceRejectsDuplicateCodeWithinTenantAndAllowsOtherTenant")
        self.phpunit("testServiceRejectsCrossTenantMutationAndAllowsOwnedDeactivate")

    def test_migration_is_additive_and_rollback_is_guarded(self) -> None:
        source = MIGRATION.read_text(encoding="utf-8")
        up = source.split("public function up", 1)[1].split("public function down", 1)[0]
        down = source.split("public function down", 1)[1]
        self.assertEqual(
            ["condor_purchasing_supplier"],
            re.findall(r"CREATE\s+TABLE\s+([a-z0-9_]+)", up, flags=re.IGNORECASE),
        )
        for pattern in (r"\bALTER\s+TABLE\b", r"\bDROP\s+", r"\bDELETE\s+FROM\b", r"\bTRUNCATE\b", r"\bRENAME\s+"):
            with self.subTest(pattern=pattern):
                self.assertIsNone(re.search(pattern, up, flags=re.IGNORECASE))
        for required in (
            "REFERENCES condor_tenant (id)",
            "UNIQUE INDEX uniq_purchasing_supplier_tenant_code (tenant_id, code)",
            "UNIQUE INDEX uniq_purchasing_supplier_tenant_id (tenant_id, id)",
            "CHECK (payment_terms_days BETWEEN 0 AND 365)",
        ):
            self.assertIn(required, up)
        self.assertIn("SELECT COUNT(*) FROM condor_purchasing_supplier", down)
        self.assertIn("$this->abortIf(", down)
        self.assertIn("DROP TABLE condor_purchasing_supplier", down)


if __name__ == "__main__":
    unittest.main()
