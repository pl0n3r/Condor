#!/usr/bin/env python3
"""Descubrimiento CI de los cuatro criterios ejecutables de Condor #1045."""

from pathlib import Path
from runpy import run_path

ROOT = Path(__file__).resolve().parents[2]
ModuloTests = run_path(str(ROOT / "tests/test_documents_http_api.py"))["ModuloTests"]
