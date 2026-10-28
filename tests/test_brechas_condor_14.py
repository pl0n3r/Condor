#!/usr/bin/env python3
"""Condor #1209: contratos sintéticos D-043-S09/S10 y D-044/S01/S02.

Pruebas offline de lógica existente, no evidencia de tiempos reales, deploy,
observabilidad operacional ni VALIDATED_IN_PRODUCTION. La matriz no se edita.
"""
from __future__ import annotations

import importlib.util
import json
import sys
import unittest
from email.message import Message
from pathlib import Path
from unittest.mock import patch
from urllib.error import HTTPError

ROOT = Path(__file__).resolve().parents[1]
SCRIPTS = ROOT / "scripts"
if str(SCRIPTS) not in sys.path:
    sys.path.insert(0, str(SCRIPTS))

UNKNOWN = "UNKNOWN"
VERSION = "0.1.0"
SHA = "a" * 40
GAPS = {
    "D-043-S09": "Medición instruction-to-production",
    "D-043-S10": "Primer deploy funcional V 0.1.0",
    "D-044": "Operación, observabilidad y evolución continua (Fase 8)",
    "D-044-S01": "Observabilidad",
    "D-044-S02": "Gestión de errores y alertas",
}


def load_script(name: str):
    """Cargar un contrato local sin invocar red, CLI ni observador productivo."""
    spec = importlib.util.spec_from_file_location(name, SCRIPTS / f"{name}.py")
    if spec is None or spec.loader is None:
        raise AssertionError(f"Falta el módulo local {name}")
    module = importlib.util.module_from_spec(spec)
    sys.modules[spec.name] = module
    spec.loader.exec_module(module)
    return module


release = load_script("release_evidence")
observer = load_script("observar_release")


def matrix_rows(document: str) -> dict[str, list[str]]:
    """Rechazar cualquier omisión/duplicado o formato ambiguo de estas filas."""
    rows: dict[str, list[str]] = {}
    for line in document.splitlines():
        if not line.startswith("| `"):
            continue
        cells = [part.strip() for part in line.strip("|").split("|")]
        key = cells[0].split(" · ", 1)[0].strip(" `")
        if key not in GAPS:
            continue
        if key in rows or len(cells) != 5:
            raise ValueError(f"UNKNOWN: fila duplicada o inválida {key}")
        rows[key] = cells
    if set(rows) != set(GAPS):
        raise ValueError("UNKNOWN: faltan filas de la matriz")
    return rows


def manifest() -> dict:
    return release.build_manifest_explicit(
        version=VERSION, sha=SHA,
        changed_paths=("tests/test_brechas_condor_14.py",),
    )


def observation(*, missing: str | None = None, sha: str = SHA,
                state: str = "VALIDATED_IN_PRODUCTION") -> dict:
    checks = {
        key: {"ok": True, "clase": "ok", "detalle": "Fixture sintético"}
        for key in release.public_checks_for_version(VERSION)
        if key != missing
    }
    return {
        "estado": state, "version_esperada": VERSION,
        "sha_esperado": sha, "comprobaciones": checks,
    }


def health(*, sha: str = SHA, status: str = "ok",
           schema_up_to_date: object = True) -> bytes:
    return json.dumps({
        "status": status, "version": VERSION, "release_sha": sha,
        "schema_up_to_date": schema_up_to_date,
    }).encode("utf-8")


def synthetic_http_error(code: int) -> HTTPError:
    return HTTPError("https://example.invalid/test", code, "synthetic", Message(), None)


def operational_evidence(_offline: object = None) -> str:
    """No convertir resultados unitarios ni un SHA sintético en prueba live."""
    return UNKNOWN


class BrechasCondor14Tests(unittest.TestCase):
    def test_contrato_principal(self) -> None:
        """AC-01: las cinco filas ejercitan un control o brecha explícitos."""
        rows = matrix_rows((ROOT / "docs/matriz-cobertura.md").read_text(encoding="utf-8"))
        spec = (ROOT / "ESPECIFICACIONES.md").read_text(encoding="utf-8")
        for key, title in GAPS.items():
            with self.subTest(brecha=key):
                self.assertEqual(rows[key][1], title)
                self.assertEqual(rows[key][2], "`NO_VERIFICABLE`")
                self.assertIn(f"Falta evidencia integral {key}", rows[key][3])
                self.assertEqual(operational_evidence(rows[key]), UNKNOWN)

        # D-043-S09: contrato de hitos, sin inventar telemetría de tiempos.
        self.assertIn("#### Medición instruction-to-production", spec)
        for stage in ("primer commit", "apertura del PR", "inicio/fin de CI",
                      "merge", "deploy observado", "producción validada"):
            self.assertIn(stage, spec)
        self.assertEqual(operational_evidence({"timestamps": "sin medición"}), UNKNOWN)

        # D-043-S10: deploy sin un smoke no puede convertirse en validación.
        self.assertIn("#### Primer deploy funcional V 0.1.0", spec)
        built = manifest()
        self.assertEqual(release.validated_release_identity(built), (VERSION, SHA))
        incomplete = release.finalize(built, observation(missing="admin_login"), ())
        self.assertEqual(incomplete["estado"], "DEPLOY_OBSERVED")
        self.assertFalse(incomplete["public_checks"]["admin_login"]["ok"])

        # D-044: el estado integral requiere evidencia, no un CI sintético.
        self.assertIn("### D-044 — Operación, observabilidad", spec)
        self.assertEqual(observer.codigo_salida_observacion({
            "estado": "NO_OBSERVADO", "comprobaciones": {"health": {"ok": False}}
        }), 2)

        # D-044-S01: health acepta solo identidad/version exacta y esquema ok.
        self.assertIn("#### Observabilidad", spec)
        self.assertTrue(observer.validar_health("application/json", health(), VERSION, SHA))
        self.assertFalse(observer.validar_health(
            "application/json", health(schema_up_to_date=False), VERSION, SHA
        ))

        # D-044-S02: 5xx es error transitorio, no respuesta exitosa.
        self.assertIn("#### Gestión de errores y alertas", spec)
        with self.assertRaises(observer.ObservacionTransitoria):
            observer.clasificar_error_http(synthetic_http_error(503), 200)

    def test_falla_cerrado(self) -> None:
        """AC-02: identidad, filas, health y alertas ambiguas se deniegan."""
        matrix = (ROOT / "docs/matriz-cobertura.md").read_text(encoding="utf-8")
        row = next(line for line in matrix.splitlines()
                   if line.startswith("| `D-043-S09` ·"))
        for corrupted in (matrix.replace(row, ""), matrix + "\n" + row,
                          matrix.replace(row, "| `D-043-S09` · broken |")):
            with self.assertRaises(ValueError):
                matrix_rows(corrupted)

        built = manifest()
        self.assertEqual(
            release.finalize(built, observation(missing="health"), ())["estado"],
            "NO_OBSERVADO",
        )
        mismatch = release.finalize(built, observation(sha="b" * 40), ())
        self.assertFalse(mismatch["identity_match"])
        self.assertEqual(mismatch["estado"], "NO_OBSERVADO")
        with self.assertRaises(release.EvidenceError):
            release.build_manifest_explicit(
                version=VERSION, sha="invalid", changed_paths=()
            )
        with self.assertRaises(observer.ObservacionError):
            observer.validar_health("text/plain", health(), VERSION, SHA)
        with self.assertRaises(observer.ObservacionError):
            observer.validar_health("application/json", health(status="error"), VERSION, SHA)
        with self.assertRaises(observer.ObservacionDeployPendiente):
            observer.validar_health("application/json", health(sha="b" * 40), VERSION, SHA)
        with self.assertRaises(observer.ObservacionIdentidad):
            observer.validar_health("application/json", health(sha="not-a-sha"), VERSION, SHA)
        with self.assertRaises(observer.ObservacionError):
            observer.clasificar_error_http(synthetic_http_error(302), 200)
        self.assertEqual(observer.codigo_salida_observacion({
            "estado": "VALIDATED_IN_PRODUCTION", "comprobaciones": {}
        }), 2)

    def test_sin_datos_reales(self) -> None:
        """AC-03: operaciones puras, sin HTTP ni credenciales ni PII."""
        with patch.object(observer, "obtener", side_effect=AssertionError("sin red")):
            built = manifest()
            evidence = release.finalize(built, observation(missing="health"), ())
            self.assertEqual(evidence["estado"], "NO_OBSERVADO")
            self.assertTrue(observer.validar_health(
                "application/json", health(), VERSION, SHA
            ))
        serialized = json.dumps(evidence, sort_keys=True).lower()
        for field in ("access_token", "authorization", "password", "database_url",
                      "cookie", "email", "telefono", "documento_identidad"):
            self.assertNotIn(field, serialized)
        self.assertEqual(evidence["version"], VERSION)
        self.assertEqual(evidence["sha"], SHA)
        self.assertEqual(evidence["schema"], release.SCHEMA)

    def test_estados_unknown_explicitos(self) -> None:
        """AC-04: una suite verde no acredita D-043/D-044 completos."""
        rows = matrix_rows((ROOT / "docs/matriz-cobertura.md").read_text(encoding="utf-8"))
        self.assertEqual(set(rows), set(GAPS))
        for key, row in rows.items():
            with self.subTest(brecha=key):
                self.assertEqual(row[2], "`NO_VERIFICABLE`")
                for candidate in (None, {}, {"ci": "success"},
                                  {"offline": True, "passed": True},
                                  {"estado": "VALIDATED_IN_PRODUCTION",
                                   "sha": SHA, "synthetic": True},
                                  {"deployment": "claimed", "alerts": []}):
                    self.assertEqual(operational_evidence(candidate), UNKNOWN)


if __name__ == "__main__":
    unittest.main()
