#!/usr/bin/env python3
"""Execute the genuine #860 refund acceptance test in the canonical CI discovery suite."""
from __future__ import annotations

import importlib.util
import io
import unittest
from pathlib import Path

ROOT = Path(__file__).resolve().parents[2]


class ReembolsosContratoTests(unittest.TestCase):
    def test_real_ac01_executes_in_ci(self) -> None:
        location = ROOT / "tests" / "test_order_refunds_api.py"
        self.assertTrue(location.is_file(), "AC-01 refund test must exist")
        spec = importlib.util.spec_from_file_location("condor_refunds_ac01", location)
        self.assertIsNotNone(spec)
        self.assertIsNotNone(spec.loader)
        module = importlib.util.module_from_spec(spec)
        spec.loader.exec_module(module)
        suite = unittest.defaultTestLoader.loadTestsFromTestCase(module.ReembolsosApiTests)
        self.assertEqual(1, suite.countTestCases(), "Real refund acceptance test not discovered")
        output = io.StringIO()
        result = unittest.TextTestRunner(stream=output, verbosity=1).run(suite)
        self.assertTrue(result.wasSuccessful(), output.getvalue())


if __name__ == "__main__":
    unittest.main()
