#!/usr/bin/env python3
"""Puente real para ejecutar las siete pruebas de #1983 en el CI de contrato.

El job canónico descubre tests/contract/test_*.py tras Composer y MariaDB.
No debe sustituirse la ejecución por una comprobación de presencia ni un SKIP.
"""
from __future__ import annotations

import importlib.util
from pathlib import Path

TEST_FILE = Path(__file__).resolve().parents[1] / "test_gestion_limites_f0_f6.py"
if not TEST_FILE.is_file():
    raise RuntimeError(f"Falta la prueba de gestión obligatoria: {TEST_FILE}")

spec = importlib.util.spec_from_file_location("condor_gestion_limites_f0_f6", TEST_FILE)
if spec is None or spec.loader is None:
    raise RuntimeError(f"No se pudo cargar la prueba de gestión: {TEST_FILE}")

module = importlib.util.module_from_spec(spec)
spec.loader.exec_module(module)

# unittest discovery ejecuta la clase real, sin reemplazar sus aserciones.
GestLimitesF0F6Tests = module.GestLimitesF0F6Tests
