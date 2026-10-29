#!/usr/bin/env python3
"""Condor #1322: contraste con llamadas a código REAL de release y throughput.

No prueba producción ni convierte filas a CUBIERTO. Contrato: una sola ruta.
"""
from __future__ import annotations

import re
import tempfile
import unittest
from pathlib import Path
from unittest.mock import patch

from scripts import ci_throughput_report as throughput
from scripts import release_identity_guard as release_guard

ROOT = Path(__file__).resolve().parents[1]
MATRIX = ROOT / "docs/matriz-cobertura.md"
CI = ROOT / ".github/workflows/ci.yml"
VERSION = ROOT / "config/version.php"
TICK = chr(96)
UNKNOWN = "UNKNOWN"
A = {
    "D-005": "El validador real rechaza reutilización de versiones/tags; no acredita la historia de deploy.",
    "D-009": "El validador real exige el sufijo SemVer en títulos de PR; otras entidades GitHub faltan.",
    "D-014": "La función real compara runs homogéneos y detecta regresiones; no acredita telemetría remota.",
}
B = {
    "AGENTES-08-04": "Pausar despachos necesita cuota autenticada real y estado del coordinador.",
    "AGENTES-08-05": "El muestreo horario y la deduplicación necesitan observar ejecuciones y artifacts de Actions.",
    "D-003": "El fallback es-CO transversal necesita respuestas HTTP/admin o navegador real.",
    "D-006": "La paridad README/deploy exige recibo de despliegue verificado por identidad y SHA.",
    "D-011": "La marca y el dominio en todas las superficies requieren aceptación UI/HTTP completa.",
    "D-012": "Atomicidad, expiración y recuperación de reservas exigen coordinación concurrente real.",
    "D-015": "Baseline integral y accesibilidad E2E requieren ejecución real en el runner.",
    "D-016": "Runner Ubuntu y canal Chrome exactos requieren recibos de ejecución y comparación E2E.",
}
C = {
    "D-013": "El protocolo local vigente deja el relay Sonar solo manual; es una decisión histórica, no una obligación automática testable.",
}
ALL = set(A) | set(B) | set(C)
SOURCES = {"AGENTES-08-04": "AGENTES.md", "AGENTES-08-05": "AGENTES.md"}
EXPECTED_TITLES = {
    "D-003": "Español de Colombia como idioma principal",
    "D-005": "Versionado por deploy",
    "D-006": "README como snapshot del deploy",
    "D-009": "Versión obligatoria en títulos de GitHub",
    "D-011": "Nombre oficial Condor App y nombre corto Condor",
    "D-012": "Coordinación multiagente con reserva atómica",
    "D-013": "Relay detallado de Sonar en Pull Requests",
    "D-014": "Telemetría de throughput no bloqueante",
    "D-015": "Baseline de pruebas antes del producto funcional",
    "D-016": "Gate E2E rápido con Chrome estable del runner",
}


def matrix_rows(content: object) -> dict[str, tuple[str, ...]]:
    """Fails closed on absent, duplicate, malformed or pre-promoted target rows."""
    if type(content) is not str:
        raise ValueError("UNKNOWN: matriz ausente")
    found: dict[str, tuple[str, ...]] = {}
    for line in content.splitlines():
        match = re.match(r"^\|\s*`((?:AGENTES-\d\d-\d\d)|(?:D-\d{3}))`\s*·", line)
        if not match or match.group(1) not in ALL:
            continue
        key = match.group(1)
        cells = tuple(part.strip() for part in line.strip().strip("|").split("|"))
        expected_source = SOURCES.get(key, "ESPECIFICACIONES.md")
        expected_id = f"{TICK}{key}{TICK} · {TICK}{expected_source}{TICK}"
        if (key in found or len(cells) != 5 or not all(cells)
                or cells[0] != expected_id
                or cells[2] != f"{TICK}NO_VERIFICABLE{TICK}"
                or cells[4] != "#1"
                or (key in EXPECTED_TITLES and cells[1] != EXPECTED_TITLES[key])):
            raise ValueError("UNKNOWN: fila inválida o duplicada " + key)
        found[key] = cells
    if set(found) != ALL:
        raise ValueError("UNKNOWN: inventario incompleto: " + repr(sorted(ALL - set(found))))
    return found


def check_release_guard(title: str, version: str = "0.1.196", tags: str = "v0.1.195") -> tuple[str, str]:
    """Exercise real release_identity_guard.validate with a synthetic Git backend."""
    with tempfile.TemporaryDirectory(prefix="condor1322-") as tmp:
        root = Path(tmp)
        (root / "config").mkdir()
        (root / "config/version.php").write_text(
            "<?php\nreturn ['version' => '" + version + "'];\n", encoding="utf-8"
        )

        def fake_git(_root, *args):
            if args == ("tag", "--list"):
                return tags
            if args == ("rev-list", "-n", "1", "v" + version):
                return "b" * 40
            raise AssertionError("UNKNOWN: inesperado Git " + repr(args))

        with patch.object(release_guard, "git", side_effect=fake_git):
            try:
                return release_guard.validate(root, title, "a" * 40)
            except release_guard.GuardError as exc:
                return UNKNOWN, str(exc)


def historical_run(run_id: int, event: str, seconds: int,
                   conclusion: str = "success") -> dict[str, object]:
    from datetime import datetime, timedelta, timezone
    started = datetime(2026, 10, 1, tzinfo=timezone.utc) + timedelta(seconds=run_id)
    return {
        "id": run_id,
        "name": throughput.WORKFLOW_NAME,
        "event": event,
        "conclusion": conclusion,
        "head_sha": "a" * 40,
        "run_started_at": started.isoformat(),
        "updated_at": (started + timedelta(seconds=seconds)).isoformat(),
    }


def integral_status(_row: object, _local: object = None) -> str:
    """Local evidence does not constitute full-system / production verification."""
    return UNKNOWN


class CoberturaCondor03Tests(unittest.TestCase):
    @classmethod
    def setUpClass(cls):
        cls.matrix = MATRIX.read_text(encoding="utf-8")
        cls.rows = matrix_rows(cls.matrix)

    def test_contrato_principal(self):
        """AC-01: 12 rows, real release guard and real CI throughput logic."""
        self.assertEqual(len(self.rows), 12)
        self.assertEqual(set(self.rows), ALL)
        self.assertEqual((0, 1, 196), release_guard.sv("0.1.196"))
        self.assertEqual(
            check_release_guard("test(cobertura): fixture (V 0.1.196)"),
            ("v0.1.196", "v0.1.195"),
        )
        baseline = [{"duration_seconds": x} for x in (40, 40, 40)]
        self.assertEqual("regression", throughput.regression_result(60, baseline, "success")["status"])
        self.assertEqual("normal", throughput.regression_result(45, baseline, "success")["status"])
        history = {"workflow_runs": [
            historical_run(1, "pull_request", 40),
            historical_run(2, "push", 90),
            historical_run(3, "pull_request", 60, "failure"),
            historical_run(4, "pull_request", 42),
        ]}
        comparable = throughput.comparable_runs(history, 99, "pull_request")
        self.assertEqual({1, 4}, {x["run_id"] for x in comparable})
        self.assertEqual("not_evaluated", throughput.regression_result(90, baseline, "failure")["status"])
        for key in ALL:
            with self.subTest(row=key):
                self.assertEqual(f"{TICK}NO_VERIFICABLE{TICK}", self.rows[key][2])
                self.assertEqual(integral_status(key, self.rows[key]), UNKNOWN)

    def test_falla_cerrado(self):
        """AC-02: missing/forged matrix, fake SemVer, unsafe Git evidence fail."""
        first = next(line for line in self.matrix.splitlines()
                     if line.startswith("| `D-005` ·"))
        invalid = [None, self.matrix.replace(first + "\n", "", 1),
                   self.matrix + "\n" + first,
                   self.matrix.replace(first, first.replace("NO_VERIFICABLE", "CUBIERTO"), 1),
                   self.matrix.replace(first, first.replace(" | #1 |", " |  |"), 1)]
        for value in invalid:
            with self.subTest(case=str(value)[:30]):
                with self.assertRaisesRegex(ValueError, UNKNOWN):
                    matrix_rows(value)
        for title in ("test: without version", "test: wrong (V 0.1.197)",
                      "test: malformed (V 0.01.196)"):
            with self.subTest(title=title):
                self.assertEqual(UNKNOWN, check_release_guard(title)[0])
        self.assertEqual(UNKNOWN, check_release_guard(
            "test: collision (V 0.1.196)", tags="v0.1.195\nv0.1.196")[0])
        with self.assertRaises(release_guard.GuardError):
            release_guard.sv("01.2.3")
        self.assertEqual([], throughput.comparable_runs(None, 99, "pull_request"))
        self.assertEqual("insufficient_baseline", throughput.regression_result(
            90, [{"duration_seconds": 40}], "success")["status"])
        self.assertEqual(UNKNOWN, integral_status("D-012", {"reservation": "active"}))

    def test_sin_datos_reales(self):
        """AC-03: fixtures only; no GitHub, database, credentials or production IO."""
        for path in (MATRIX, CI, VERSION, ROOT / "scripts/release_identity_guard.py",
                     ROOT / "scripts/ci_throughput_report.py"):
            self.assertTrue(path.is_file(), str(path))
        own = Path(__file__).read_text(encoding="utf-8")
        self.assertNotRegex(own, r"(?m)^\s*(?:import|from)\s+(?:requests|httpx|socket|urllib|pymysql)\b")
        self.assertNotIn("-----BEGIN " + "PRIVATE KEY-----", own)
        self.assertEqual(set(A) | set(B) | set(C), ALL)
        self.assertEqual(len(A), 3)
        self.assertEqual(len(B), 8)
        self.assertEqual(len(C), 1)
        self.assertTrue(all(B.values()) and all(C.values()))
        self.assertIn("Relay de Sonar exclusivamente manual", (ROOT / "AGENTES.md").read_text(encoding="utf-8"))
        self.assertIn("  validar:\n    name: Validar\n", CI.read_text(encoding="utf-8"))
        self.assertEqual(UNKNOWN, integral_status("D-006", {"ci": "success"}))


if __name__ == "__main__":
    unittest.main()
