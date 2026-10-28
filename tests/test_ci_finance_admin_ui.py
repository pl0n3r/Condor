#!/usr/bin/env python3
"""Incluye los AC del simulador M07 en el discovery real test_ci_*.py de Condor."""
from test_finance_admin_ui import ModuloTests as _FinanceAcceptanceTests


class FinanceAcceptanceInCITests(_FinanceAcceptanceTests):
    """Ejecuta los AC y la guarda de montaje DEV sin duplicar su lógica."""
