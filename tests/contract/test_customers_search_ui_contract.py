#!/usr/bin/env python3
"""Execute the real #870 UI acceptance in the canonical Factory contract CI."""
from __future__ import annotations

import importlib.util
import io
import unittest
from pathlib import Path

ROOT = Path(__file__).resolve().parents[2]


class UiClientesBusquedaContratoTests(unittest.TestCase):
    def test_acceptance_runs_in_ci(self) -> None:
        location = ROOT / "tests" / "test_customers_search_ui.py"
        spec = importlib.util.spec_from_file_location("condor_customers_ui_ac01", location)
        self.assertIsNotNone(spec)
        self.assertIsNotNone(spec.loader)
        module = importlib.util.module_from_spec(spec)
        spec.loader.exec_module(module)
        suite = unittest.defaultTestLoader.loadTestsFromTestCase(
            module.UiClientesBusquedaTests,
        )
        self.assertEqual(2, suite.countTestCases(), "AC-01 y regresión negativa deben ejecutarse")
        output = io.StringIO()
        result = unittest.TextTestRunner(stream=output, verbosity=1).run(suite)
        self.assertTrue(result.wasSuccessful(), output.getvalue())


if __name__ == "__main__":
    unittest.main()
