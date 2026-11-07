#!/usr/bin/env python3
"""CI discovery bridge for #1620's genuine Symfony/MariaDB E2E tests.

The canonical job discovers tests/contract/test_*.py, not tests/e2e/test_*.py.
Export the original TestCase unchanged so that unittest executes the real
HTTP/SQL checks after Composer and disposable MariaDB setup.
Never replace the integration tests with text-only assertions or a fake PASS.
"""
from __future__ import annotations

import importlib.util
from pathlib import Path

E2E_FILE = Path(__file__).resolve().parents[1] / "e2e" / "test_gest_flows_integrados.py"

if not E2E_FILE.is_file():
    raise RuntimeError(f"Falta la prueba E2E real requerida: {E2E_FILE}")

spec = importlib.util.spec_from_file_location("condor_gest_flows_integrados_e2e", E2E_FILE)
if spec is None or spec.loader is None:
    raise RuntimeError(f"No se puede importar la prueba E2E: {E2E_FILE}")

module = importlib.util.module_from_spec(spec)
spec.loader.exec_module(module)

# unittest discovery collects imported subclasses of unittest.TestCase.
# The original tests still require real Symfony/PHPUnit/MariaDB fixtures.
GestFlowsIntegradosTests = module.GestFlowsIntegradosTests
