#!/usr/bin/env python3
"""Aceptación ejecutable de Commercial Catalog #269."""

from __future__ import annotations

import subprocess
import unittest
from pathlib import Path

ROOT = Path(__file__).resolve().parents[1]
TEST = ROOT / "tests/php/Application/Commercial/CommercialCatalogSeedTest.php"


class CommercialCatalogSeedTests(unittest.TestCase):
    def phpunit(self, pattern: str) -> None:
        runner = ROOT / "vendor/bin/simple-phpunit"
        if not runner.exists():
            self.fail("vendor/bin/simple-phpunit no está disponible")
        result = subprocess.run(
            [str(runner), "--filter", pattern, str(TEST)],
            cwd=ROOT, text=True, capture_output=True, check=False,
        )
        self.assertEqual(0, result.returncode, result.stdout + result.stderr)

    def test_capabilities_and_addons_use_stable_keys(self) -> None:
        self.phpunit("testCapabilitiesAndAddOnsUseStableKeys")

    def test_initial_catalog_matches_approved_plans_and_prices(self) -> None:
        self.phpunit("testInitialCatalogMatchesApprovedPlansAndPrices")

    def test_production_lite_is_business_addon(self) -> None:
        self.phpunit("testProductionLiteIsBusinessAddOn")

    def test_seed_is_idempotent_and_reader_resolves_effective_catalog(self) -> None:
        self.phpunit("testSeedIsIdempotentAndReaderResolvesEffectiveCatalog")


if __name__ == "__main__":
    unittest.main()
