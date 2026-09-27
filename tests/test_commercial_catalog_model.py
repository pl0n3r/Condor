#!/usr/bin/env python3
"""Aceptación ejecutable del modelo Commercial Catalog #268."""

from __future__ import annotations

import subprocess
import unittest
from pathlib import Path

ROOT = Path(__file__).resolve().parents[1]
TEST = ROOT / "tests/php/Domain/Commercial/CommercialCatalogModelTest.php"


class CommercialCatalogModelTests(unittest.TestCase):
    def phpunit(self, pattern: str) -> None:
        runner = ROOT / "vendor/bin/simple-phpunit"
        if not runner.exists():
            self.fail("vendor/bin/simple-phpunit no está disponible")
        result = subprocess.run(
            [str(runner), "--filter", pattern, str(TEST)],
            cwd=ROOT,
            text=True,
            capture_output=True,
            check=False,
        )
        self.assertEqual(0, result.returncode, result.stdout + result.stderr)

    def test_plan_versions_preserve_history_and_reject_overlap(self) -> None:
        self.phpunit("testPlanVersionsPreserveHistoryAndRejectOverlap")

    def test_plan_and_vertical_are_independent(self) -> None:
        self.phpunit("testPlanAndVerticalAreIndependent")

    def test_enterprise_price_is_unknown_not_zero(self) -> None:
        self.phpunit("testEnterprisePriceIsUnknownNotZero")


if __name__ == "__main__":
    unittest.main()
