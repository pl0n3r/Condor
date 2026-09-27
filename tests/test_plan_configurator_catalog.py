#!/usr/bin/env python3
"""Aceptación ejecutable de Plan Configurator #277A."""

from __future__ import annotations

import re
import subprocess
import unittest
from pathlib import Path

ROOT = Path(__file__).resolve().parents[1]
PHP_TEST = ROOT / "tests/php/Application/Commercial/PlanConfiguratorCatalogTest.php"
MIGRATION = ROOT / "migrations/Version20260927060000.php"


class PlanConfiguratorCatalogTests(unittest.TestCase):
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

    def test_configurator_reads_canonical_catalog(self) -> None:
        self.phpunit("testConfiguratorReadsCanonicalCatalog")

    def test_vertical_compatibility_uses_stable_relations(self) -> None:
        self.phpunit("testVerticalCompatibilityUsesStableRelations")

    def test_legal_vertical_excludes_inventory_and_manufacturing(self) -> None:
        self.phpunit("testLegalVerticalExcludesInventoryAndManufacturing")

    def test_switching_vertical_changes_relevant_options_without_plan_fork(self) -> None:
        self.phpunit("testSwitchingVerticalChangesRelevantOptionsWithoutPlanFork")

    def test_addons_come_from_plan_version_relations(self) -> None:
        self.phpunit("testAddonsComeFromPlanVersionRelations")

    def test_compatibility_migration_and_seed_are_idempotent(self) -> None:
        source = MIGRATION.read_text(encoding="utf-8")
        up = source.split("public function up", 1)[1].split("public function down", 1)[0]
        for pattern in (
            r"\bDROP\s+",
            r"\bDELETE\s+FROM\b",
            r"\bTRUNCATE\b",
            r"\bRENAME\s+",
            r"\bDROP\s+COLUMN\b",
        ):
            with self.subTest(pattern=pattern):
                self.assertIsNone(re.search(pattern, up, flags=re.IGNORECASE))
        self.phpunit("testCompatibilityMigrationAndSeedAreIdempotent")


if __name__ == "__main__":
    unittest.main()
