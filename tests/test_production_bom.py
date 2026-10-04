#!/usr/bin/env python3
"""Aceptación ejecutable de Producción Lite V1 / BOM (#567)."""

from __future__ import annotations

import re
import subprocess
import unittest
from pathlib import Path

ROOT = Path(__file__).resolve().parents[1]
PHP_TEST = ROOT / "tests/php/Domain/Production/BillOfMaterialsTest.php"
BOM = ROOT / "src/Domain/Production/Entity/BillOfMaterials.php"
LINE = ROOT / "src/Domain/Production/Entity/BillOfMaterialsLine.php"
SERVICE = ROOT / "src/Application/Production/BillOfMaterialsService.php"
MIGRATION = ROOT / "migrations/Version20261004143000.php"
VERSION = ROOT / "config/version.php"


class ProductionBomTests(unittest.TestCase):
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

    def test_versioned_bom_is_tenant_scoped_immutable_and_quantity_safe(self) -> None:
        bom = BOM.read_text(encoding="utf-8")
        line = LINE.read_text(encoding="utf-8")
        self.assertIn("uniq_production_bom_tenant_variant_version", bom)
        self.assertIn("ProductVariant", bom)
        self.assertIn("new BillOfMaterialsLine(", bom)
        self.assertNotIn("public function update(", bom)
        self.assertIn("$sourceUnit->convert($quantity, $targetUnit)", line)
        self.assertIn("$normalized === '0'", line)
        self.assertIn("!$material->isActive()", line)
        self.phpunit(
            "testBomVersionNormalizesQuantitiesAndKeepsPreviousVersionAuditable"
        )

    def test_bom_rejects_cross_tenant_duplicate_inactive_zero_and_incompatible_lines(self) -> None:
        bom = BOM.read_text(encoding="utf-8")
        line = LINE.read_text(encoding="utf-8")
        self.assertIn("$variant->tenant()->id() !== $tenant->id()", bom)
        self.assertIn("isset($seen[$material->id()])", bom)
        self.assertIn("$material->tenant()->id() !== $tenant->id()", line)
        self.phpunit(
            "testBomRejectsCrossTenantInactiveDuplicateZeroAndIncompatibleMaterial"
        )

    def test_service_serializes_versions_and_requires_production_lite(self) -> None:
        service = SERVICE.read_text(encoding="utf-8")
        self.assertIn("$entitlements->addOn('production-lite')", service)
        self.assertIn("wrapInTransaction(", service)
        self.assertIn("LockMode::PESSIMISTIC_WRITE", service)
        self.assertIn("['version' => 'DESC']", service)
        self.assertIn("'active' => true", service)
        self.assertIn("$current->retire()", service)
        self.assertIn("$latest->version() + 1", service)
        self.assertIn("$variant->tenant()->id() !== $tenant->id()", service)

    def test_migration_is_additive_and_version_is_0_1_170(self) -> None:
        source = MIGRATION.read_text(encoding="utf-8")
        up = source.split("public function up", 1)[1].split("public function down", 1)[0]

        self.assertEqual(
            ["condor_production_bom", "condor_production_bom_line"],
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

        self.assertIn("REFERENCES condor_product_variant (id)", up)
        self.assertIn("REFERENCES condor_production_material (id)", up)
        self.assertIn("CHECK (quantity > 0)", up)
        self.assertIn("uniq_production_bom_line_bom_material", up)
        self.assertIn(
            "'version' => '0.1.170'",
            VERSION.read_text(encoding="utf-8"),
        )


if __name__ == "__main__":
    unittest.main()
