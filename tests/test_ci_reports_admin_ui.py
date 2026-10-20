"""CI-discovered acceptance for Condor #727 (isolated reports CSV panel).

The regular CI discovers tests/test_ci_*.py; the canonical acceptance and
security cases live in tests/test_reports_admin_ui.py. Re-export the actual
TestCase so discovery executes identical assertions rather than a mock pass.
The end-to-end admin integration remains gated by #726 V3 and D-043.
"""

from test_reports_admin_ui import ReportesUiAdminTests

__all__ = ["ReportesUiAdminTests"]
