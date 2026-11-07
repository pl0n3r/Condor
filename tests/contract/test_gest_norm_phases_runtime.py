#!/usr/bin/env python3
"""Discovery bridge: run #1638 E2E from the Composer/MariaDB CI contract job.

CI discovers tests/contract/test_*.py; it does not discover tests/e2e by default.
Import the real TestCase unchanged, including its PHPUnit/Symfony/SQL checks.
Never replace this with static text assertions or a fake success.
"""
from __future__ import annotations

import importlib.util
from pathlib import Path

E2E_FILE = Path(__file__).resolve().parents[1] / "e2e" / "test_gest_norm_phases.py"
if not E2E_FILE.is_file():
    raise RuntimeError(f"Falta el E2E normativo obligatorio: {E2E_FILE}")

spec = importlib.util.spec_from_file_location("condor_gest_norm_phases_e2e", E2E_FILE)
if spec is None or spec.loader is None:
    raise RuntimeError(f"No se pudo cargar E2E normativo: {E2E_FILE}")

module = importlib.util.module_from_spec(spec)
spec.loader.exec_module(module)

# unittest discovery runs the unmodified original two-test TestCase.
GestNormPhasesE2ETests = module.GestNormPhasesE2ETests
