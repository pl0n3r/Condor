"""Execute Condor #849's exact acceptance suite in the canonical contract CI.

The factory-acceptance marker owns tests/test_sales_report_api.py; this
adapter only makes that suite discoverable by tests/contract/test_*.py.
"""
import importlib.util
from pathlib import Path


def load_tests(loader, tests, pattern):
    source = Path(__file__).resolve().parents[1] / "test_sales_report_api.py"
    spec = importlib.util.spec_from_file_location("sales_report_api_acceptance", source)
    if spec is None or spec.loader is None:
        raise RuntimeError("Condor #849 acceptance tests are unavailable")
    module = importlib.util.module_from_spec(spec)
    spec.loader.exec_module(module)
    if not hasattr(module, "VentasReporteApiTests"):
        raise RuntimeError("Condor #849 acceptance test class is unavailable")
    return loader.loadTestsFromTestCase(module.VentasReporteApiTests)
