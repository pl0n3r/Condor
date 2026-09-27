#!/usr/bin/env python3
"""Aceptación ejecutable de persistencia Commercial Catalog #270."""

from __future__ import annotations

import re
import subprocess
import unittest
from pathlib import Path

ROOT = Path(__file__).resolve().parents[1]
PHP_TEST = ROOT / "tests/php/Infrastructure/Persistence/CommercialCatalogPersistenceTest.php"
MIGRATION = ROOT / "migrations/Version20260927050500.php"


class CommercialCatalogPersistenceTests(unittest.TestCase):
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

    def test_doctrine_maps_commercial_catalog_separately(self) -> None:
        self.phpunit("testDoctrineMapsCommercialCatalogSeparately")

    def test_schema_contains_identity_version_and_temporal_constraints(self) -> None:
        self.phpunit("testSchemaContainsIdentityVersionAndTemporalConstraints")

    def test_migration_is_expand_compatible_and_schema_aligned(self) -> None:
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
        self.phpunit("testGeneratedAndMigratedSchemaAlign")


if __name__ == "__main__":
    unittest.main()
