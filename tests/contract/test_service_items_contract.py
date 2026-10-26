"""CI bridge: run the real #865 acceptance tests on each applicable pull request."""
import importlib.util
import unittest
from pathlib import Path

ROOT = Path(__file__).resolve().parents[2]
ACCEPTANCE = ROOT / "tests/test_service_items_api.py"
spec = importlib.util.spec_from_file_location("service_items_acceptance", ACCEPTANCE)
if spec is None or spec.loader is None:
    raise ImportError("AC-01 de servicios no está disponible")
module = importlib.util.module_from_spec(spec)
spec.loader.exec_module(module)


class ServicesInCiContractTests(unittest.TestCase):
    def test_real_ac01_and_security(self):
        suite = unittest.defaultTestLoader.loadTestsFromTestCase(
            module.ServiciosVendiblesApiTests
        )
        result = unittest.TestResult()
        suite.run(result)
        diagnostics = "\n".join(
            f"{test}: {trace}"
            for test, trace in result.failures + result.errors
        )
        self.assertTrue(result.wasSuccessful(), diagnostics)
        self.assertEqual(2, result.testsRun, "Deben ejecutarse ambas regresiones, no solo descubrirse")
