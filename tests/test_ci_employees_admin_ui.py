"""Expose #725's real, synthetic UI acceptance to the canonical CI discovery.

No network fixtures, no production data and no test skipping.
"""
from test_employees_admin_ui import EmpleadosUiAdminTests

__all__ = ["EmpleadosUiAdminTests"]
