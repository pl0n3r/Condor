#!/usr/bin/env python3
"""CI discovery bridge: execute Condor #869's real Admin UI acceptance."""
from __future__ import annotations

import importlib.util
import io
import unittest
from pathlib import Path

ROOT = Path(__file__).resolve().parents[2]


class UiPedidosFiltrosContratoTests(unittest.TestCase):
    def test_acceptance_runs_in_ci(self) -> None:
        location = ROOT / "tests" / "test_orders_filters_ui.py"
        spec = importlib.util.spec_from_file_location("condor_orders_ui_ac01", location)
        self.assertIsNotNone(spec)
        self.assertIsNotNone(spec.loader)
        module = importlib.util.module_from_spec(spec)
        spec.loader.exec_module(module)
        suite = unittest.defaultTestLoader.loadTestsFromTestCase(
            module.UiPedidosFiltrosTests,
        )
        self.assertEqual(2, suite.countTestCases(), "Deben ejecutarse AC-01 y aislamiento")
        output = io.StringIO()
        result = unittest.TextTestRunner(stream=output, verbosity=1).run(suite)
        self.assertTrue(result.wasSuccessful(), output.getvalue())


if __name__ == "__main__":
    unittest.main()
