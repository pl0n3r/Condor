#!/usr/bin/env python3
"""Discovery bridge: CI discovers tests/contract/test_*.py, not root acceptance."""
from __future__ import annotations

import importlib.util
import unittest
from pathlib import Path

ROOT = Path(__file__).resolve().parents[2]


class PedidosFiltrosContratoTests(unittest.TestCase):
    def test_acceptance_is_executed_in_ci(self) -> None:
        location = ROOT / "tests" / "test_orders_list_filters.py"
        spec = importlib.util.spec_from_file_location("condor_orders_acceptance", location)
        self.assertIsNotNone(spec)
        self.assertIsNotNone(spec.loader)
        module = importlib.util.module_from_spec(spec)
        spec.loader.exec_module(module)
        suite = unittest.defaultTestLoader.loadTestsFromTestCase(
            module.PedidosFiltrosTests,
        )
        self.assertEqual(1, suite.countTestCases(), "Debe ejecutarse el AC-01 real")
        result = unittest.TextTestRunner(verbosity=1).run(suite)
        self.assertTrue(result.wasSuccessful(), "El AC-01 PHP debe pasar realmente")


if __name__ == "__main__":
    unittest.main()
