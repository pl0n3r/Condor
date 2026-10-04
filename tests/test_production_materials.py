#!/usr/bin/env python3
"""Aceptación ejecutable de Producción Lite V1 / materiales (#565)."""

from __future__ import annotations

import re
import subprocess
import unittest
from pathlib import Path

ROOT = Path(__file__).resolve().parents[1]
PHP_TEST = ROOT / "tests/php/Domain/Production/MaterialDomainTest.php"
MATERIAL = ROOT / "src/Domain/Production/Entity/Material.php"
UNIT = ROOT / "src/Domain/Production/ValueObject/UnitOfMeasure.php"
SERVICE = ROOT / "src/Application/Production/MaterialService.php"
MIGRATION = ROOT / "migrations/Version20261004120000.php"
VERSION = ROOT / "config/version.php"


class ProductionMaterialsTests(unittest.TestCase):
    def phpunit(self, pattern: str) -> None:
        runner = ROOT / "vendor/bin/simple-phpunit"
        if not runner.exists():
            self.fail("vendor/bin/simple-phpunit no está disponible")
        result = subprocess.run(
            [str(runner), "--filter", pattern, str(PHP_TEST)],
            cwd=ROOT,
            text=True,
            capture_output=True,
            check=False,
        )
        self.assertEqual(0, result.returncode, result.stdout + result.stderr)

    def test_material_is_tenant_scoped_with_unique_code_and_canonical_unit(self) -> None:
        material = MATERIAL.read_text(encoding="utf-8")
        self.assertIn("organization_id", material)
        self.assertIn("uniq_production_material_organization_code", material)
        self.assertIn("UnitOfMeasure", material)
        self.assertIn("Tenant $organization", material)
        self.phpunit("testMaterialNormalizesCodeAndKeepsTenantScope")

    def test_unit_conversion_rejects_incompatible_magnitudes_and_negative_quantities(self) -> None:
        unit = UNIT.read_text(encoding="utf-8")
        self.assertIn("'kg'", unit)
        self.assertIn("'g'", unit)
        self.assertIn("'l'", unit)
        self.assertIn("'ml'", unit)
        self.assertNotIn("float $", unit)
        self.phpunit("testUnitConversionIsExactAcrossSameMagnitude")
        self.phpunit("testUnitRejectsNegativeIncompatibleAndUnknownValues")

    def test_service_requires_production_lite_capability_and_rejects_cross_tenant_access(self) -> None:
        service = SERVICE.read_text(encoding="utf-8")
        self.assertIn("$entitlements->tenantId() !== $tenant->id()", service)
        self.assertIn("$capabilities['manufacturing']", service)
        self.assertIn("$addOns['production-lite']", service)
        self.assertIn("$material->tenant()->id() !== $tenant->id()", service)
        self.assertIn("assertCodeAvailable", service)
        self.assertIn("deactivate(", service)

    def test_migration_is_additive_and_creates_only_new_tables(self) -> None:
        source = MIGRATION.read_text(encoding="utf-8")
        up = source.split("public function up", 1)[1].split("public function down", 1)[0]

        self.assertEqual(
            ["condor_production_material"],
            re.findall(r"CREATE\s+TABLE\s+([a-z0-9_]+)", up, flags=re.IGNORECASE),
        )
        for pattern in (
            r"\bALTER\s+TABLE\b",
            r"\bDROP\s+",
            r"\bDELETE\s+FROM\b",
            r"\bTRUNCATE\b",
            r"\bRENAME\s+",
        ):
            with self.subTest(pattern=pattern):
                self.assertIsNone(re.search(pattern, up, flags=re.IGNORECASE))

        self.assertIn("organization_id", up)
        self.assertIn("REFERENCES condor_tenant (id)", up)
        self.assertIn("CHK_PRODUCTION_MATERIAL_UNIT", up)
        self.assertIn("'version' => '0.1.169'", VERSION.read_text(encoding="utf-8"))


if __name__ == "__main__":
    unittest.main()
