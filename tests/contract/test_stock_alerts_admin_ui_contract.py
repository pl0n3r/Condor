"""Execute #726 acceptance tests as part of the canonical CI contract gate.

The authoritative tests remain at tests/test_stock_alerts_admin_ui.py because
the Factory acceptance marker points to that exact path.
"""
import importlib.util
from pathlib import Path


def load_tests(loader, tests, pattern):
    source = Path(__file__).resolve().parents[1] / "test_stock_alerts_admin_ui.py"
    spec = importlib.util.spec_from_file_location("stock_alerts_admin_acceptance", source)
    if spec is None or spec.loader is None:
        raise RuntimeError("Stock-alert acceptance tests are unavailable")
    module = importlib.util.module_from_spec(spec)
    spec.loader.exec_module(module)
    return loader.loadTestsFromTestCase(module.AlertasUiAdminTests)
