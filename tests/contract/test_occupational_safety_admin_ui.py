#!/usr/bin/env python3
"""Descubre los criterios SG-SST desde la suite canónica tests/contract."""
from __future__ import annotations

import importlib.util
from pathlib import Path

ROOT = Path(__file__).resolve().parents[2]
SOURCE = ROOT / "tests/test_occupational_safety_admin_ui.py"
spec = importlib.util.spec_from_file_location("_sgsst_acceptance", SOURCE)
if spec is None or spec.loader is None:
    raise ImportError("Aceptación SG-SST no encontrada")
module = importlib.util.module_from_spec(spec)
spec.loader.exec_module(module)
ModuloTests = module.ModuloTests
