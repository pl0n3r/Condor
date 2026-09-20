"""Incluye las pruebas de observación en el gate de contrato canónico de CI."""

import importlib.util
from pathlib import Path

ruta = Path(__file__).resolve().parents[1] / "test_observar_release.py"
especificacion = importlib.util.spec_from_file_location("test_observar_release", ruta)
assert especificacion is not None
assert especificacion.loader is not None
modulo = importlib.util.module_from_spec(especificacion)
especificacion.loader.exec_module(modulo)

ObserverTests = modulo.ObserverTests
