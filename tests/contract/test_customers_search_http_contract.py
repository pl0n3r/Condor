#!/usr/bin/env python3
"""Descubrimiento de la aceptación HTTP real #902 por el CI canónico."""
from __future__ import annotations

import importlib.util
import io
import unittest
from pathlib import Path

ROOT = Path(__file__).resolve().parents[2]


class ClientesBusquedaHttpContratoTests(unittest.TestCase):
    def test_real_ac01_executes_in_ci(self) -> None:
        location = ROOT / "tests/test_customers_search_http.py"
        spec = importlib.util.spec_from_file_location("condor_customers_http_ac01", location)
        self.assertIsNotNone(spec)
        self.assertIsNotNone(spec.loader)
        module = importlib.util.module_from_spec(spec)
        spec.loader.exec_module(module)
        suite = unittest.defaultTestLoader.loadTestsFromTestCase(
            module.CustomerSearchHttpTests,
        )
        self.assertEqual(3, suite.countTestCases(), "AC-01 no descubierto")
        output = io.StringIO()
        result = unittest.TextTestRunner(stream=output, verbosity=1).run(suite)
        self.assertTrue(result.wasSuccessful(), output.getvalue())


if __name__ == "__main__":
    unittest.main()
