#!/usr/bin/env python3
"""Verifica el cálculo decimal histórico real en PHP sin floats ni extensiones nuevas."""

import shutil
import subprocess
import unittest
from pathlib import Path

ROOT = Path(__file__).resolve().parents[1]
TEST = ROOT / "tests/php/Domain/Purchasing/MaterialCostServiceTest.php"
SERVICE = ROOT / "src/Application/Purchasing/MaterialCostService.php"


class PurchasingBigDecimalTests(unittest.TestCase):
    def run_php_case(self, method: str) -> None:
        php = shutil.which("php")
        self.assertIsNotNone(php, "PHP CLI requerido")
        syntax = subprocess.run(
            [php, "-l", str(SERVICE)], cwd=ROOT, text=True,
            capture_output=True, check=False,
        )
        self.assertEqual(0, syntax.returncode, syntax.stdout + syntax.stderr)
        runner = ROOT / "vendor/bin/simple-phpunit"
        self.assertTrue(runner.exists(), "PHPUnit instalado requerido")
        result = subprocess.run(
            [str(runner), str(TEST), "--filter", method], cwd=ROOT,
            text=True, capture_output=True, check=False,
        )
        self.assertEqual(0, result.returncode, result.stdout + result.stderr)

    def test_large_valid_historical_cost(self) -> None:
        source = TEST.read_text(encoding="utf-8")
        self.assertIn("function testLargeValidHistoricalCostIsExactBeyond64Bit()", source)
        self.run_php_case("testLargeValidHistoricalCostIsExactBeyond64Bit")

    def test_extreme_valid_historical_cost(self) -> None:
        source = TEST.read_text(encoding="utf-8")
        self.assertIn("function testExtremeValidHistoricalCostIsExactWithoutOverflow()", source)
        self.run_php_case("testExtremeValidHistoricalCostIsExactWithoutOverflow")


if __name__ == "__main__":
    unittest.main()
