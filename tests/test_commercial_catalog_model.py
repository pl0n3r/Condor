#!/usr/bin/env python3
"""Aceptación ejecutable del modelo Commercial Catalog #268."""

from __future__ import annotations

import re
import subprocess
import unittest
from pathlib import Path

ROOT = Path(__file__).resolve().parents[1]
DOMAIN_TEST = ROOT / "tests/php/Domain/Commercial/CommercialCatalogModelTest.php"
SCHEMA_TEST = ROOT / "tests/php/Infrastructure/Persistence/CommercialCatalogSchemaTest.php"
MIGRATION = ROOT / "migrations/Version20260927044500.php"


class CommercialCatalogModelTests(unittest.TestCase):
    def phpunit(self, pattern: str, path: Path = DOMAIN_TEST) -> None:
        runner = ROOT / "vendor/bin/simple-phpunit"
        if not runner.exists():
            self.fail("vendor/bin/simple-phpunit no está disponible")
        completed = subprocess.run(
            [str(runner), "--filter", pattern, str(path)],
            cwd=ROOT,
            text=True,
            capture_output=True,
            check=False,
        )
        self.assertEqual(0, completed.returncode, completed.stdout + completed.stderr)

    def test_plan_versions_preserve_history_and_reject_overlap(self) -> None:
        self.phpunit("testPlanVersionsPreserveHistoryAndRejectOverlap")

    def test_plan_and_vertical_are_independent(self) -> None:
        self.phpunit("testPlanAndVerticalAreIndependent")

    def test_enterprise_price_is_unknown_not_zero(self) -> None:
        self.phpunit("testEnterprisePriceIsUnknownNotZero")

    def test_migration_is_additive_and_schema_aligned(self) -> None:
        source = MIGRATION.read_text(encoding="utf-8")
        forbidden = (
            r"\bDROP\s+",
            r"\bDELETE\s+FROM\b",
            r"\bTRUNCATE\b",
            r"\bRENAME\s+",
            r"\bDROP\s+COLUMN\b",
        )
        for pattern in forbidden:
            with self.subTest(pattern=pattern):
                self.assertIsNone(re.search(pattern, up, flags=re.IGNORECASE))
        self.phpunit(
            "testGeneratedAndMigratedCommercialSchemaAlign",
            SCHEMA_TEST,
        )


if __name__ == "__main__":
    unittest.main()
