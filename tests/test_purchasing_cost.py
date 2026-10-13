#!/usr/bin/env python3
"""Contrato de aceptación Compras Lite 3/3: costo histórico (#656)."""
from pathlib import Path
import subprocess
import unittest

ROOT = Path(__file__).resolve().parents[1]
SERVICE = ROOT / "src/Application/Purchasing/MaterialCostService.php"
PHP_TEST = ROOT / "tests/php/Domain/Purchasing/MaterialCostServiceTest.php"


class PurchasingCostTests(unittest.TestCase):
    def phpunit(self, name: str) -> None:
        runner = ROOT / "vendor/bin/simple-phpunit"
        if not runner.exists():
            self.fail("PHPUnit no disponible; no se puede afirmar aceptación de costo histórico")
        result = subprocess.run(
            [str(runner), "--filter", name, str(PHP_TEST)],
            cwd=ROOT, capture_output=True, text=True, check=False,
        )
        self.assertEqual(result.returncode, 0, result.stdout + result.stderr)

    def test_weighted_average_cost_is_deterministic(self) -> None:
        service = SERVICE.read_text(encoding="utf-8")
        tests = PHP_TEST.read_text(encoding="utf-8")
        self.assertIn("$order->isReceived()", service)
        self.assertIn("$order->lines()", service)
        self.assertIn("$weightedTotal", service)
        self.assertIn("intdiv($weightedTotal, $quantityTotal)", service)
        self.assertIn("testWeightedAverageCostIsDeterministic", tests)
        self.phpunit("testWeightedAverageCostIsDeterministic")

    def test_material_without_receipts_is_unknown_not_zero(self) -> None:
        service = SERVICE.read_text(encoding="utf-8")
        self.assertIn("'state' => 'UNKNOWN'", service)
        self.assertIn("'average_unit_cost' => null", service)
        self.assertIn("'state' => 'KNOWN'", service)
        self.phpunit("testMaterialWithoutReceiptsIsUnknownNotZero")

    def test_cost_is_tenant_isolated(self) -> None:
        service = SERVICE.read_text(encoding="utf-8")
        self.assertIn("$material->tenant()->id() !== $tenant->id()", service)
        self.assertIn("$order->tenant()->id() !== $tenant->id()", service)
        self.assertIn("$line->tenant()->id() !== $tenant->id()", service)
        self.phpunit("testCostIsTenantIsolated")
        self.phpunit("testExtremeValuesFailClosedRatherThanOverflow")


if __name__ == "__main__":
    unittest.main()
