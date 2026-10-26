#!/usr/bin/env python3
"""Run the real #859 AC-01 PHP payment ledger tests in the canonical CI suite."""
from __future__ import annotations

import importlib.util
import io
import unittest
from pathlib import Path

ROOT = Path(__file__).resolve().parents[2]


class PagosManualesContratoTests(unittest.TestCase):
    def test_real_ac01_executes_in_ci(self) -> None:
        location = ROOT / "tests" / "test_order_payments_api.py"
        self.assertTrue(location.is_file(), "La prueba AC-01 debe existir")
        spec = importlib.util.spec_from_file_location("condor_manual_payments_ac01", location)
        self.assertIsNotNone(spec)
        self.assertIsNotNone(spec.loader)
        module = importlib.util.module_from_spec(spec)
        spec.loader.exec_module(module)
        suite = unittest.defaultTestLoader.loadTestsFromTestCase(
            module.PagosManualesApiTests,
        )
        self.assertEqual(1, suite.countTestCases(), "AC-01 real no descubierto")
        output = io.StringIO()
        result = unittest.TextTestRunner(stream=output, verbosity=1).run(suite)
        self.assertTrue(result.wasSuccessful(), output.getvalue())


if __name__ == "__main__":
    unittest.main()
