"""Run the exact #868 acceptance suite in CI contract discovery.

Condor CI discovers tests/contract/test_*.py, not tests/test_*.py.
This bridge imports the canonical test class without copying test logic.
"""
import importlib.util
from pathlib import Path

ROOT = Path(__file__).resolve().parents[2]
TARGET = ROOT / "tests/test_sales_report_ui.py"
SPEC = importlib.util.spec_from_file_location("condor_sales_report_ui_acceptance", TARGET)
if SPEC is None or SPEC.loader is None:
    raise ImportError("Cannot load sales report UI acceptance")
MODULE = importlib.util.module_from_spec(SPEC)
SPEC.loader.exec_module(MODULE)
UiVentasPorProductoTests = MODULE.UiVentasPorProductoTests
