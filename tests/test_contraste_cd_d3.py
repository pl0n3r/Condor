#!/usr/bin/env python3
"""Condor #1027: auditoria fail-closed D-037 a D-054, 93 filas."""
from __future__ import annotations

import collections
from pathlib import Path
import re
import unittest

ROOT = Path(__file__).resolve().parents[1]
REPORT = ROOT / "docs/contraste-cd_d3.md"
MATRIX = ROOT / "docs/matriz-cobertura.md"
EXPECTED = (
    {f"D-{i:03d}" for i in range(37, 55)}
    | {f"D-040-S{i:02d}" for i in range(1, 21)}
    | {f"D-041-S{i:02d}" for i in range(1, 11)}
    | {f"D-042-S{i:02d}" for i in range(1, 6)}
    | {f"D-043-S{i:02d}" for i in range(1, 11)}
    | {f"D-044-S{i:02d}" for i in range(1, 12)}
    | {f"D-045-S{i:02d}" for i in range(1, 4)}
    | {f"D-048-S{i:02d}" for i in range(1, 3)}
    | {f"D-049-S{i:02d}" for i in range(1, 9)}
    | {f"D-050-S{i:02d}" for i in range(1, 7)}
)
STATES = {"CUBIERTO", "NO_VERIFICABLE", "SIN_COBERTURA", "DESCARTADO"}
MERGED = {966: "4f72e0d5945ed35c5bd98fb7e4ba44ba8fa0e097"}
REP_ROW = re.compile(
    r"^\| (D-\d{3}(?:-S\d{2})?) \| (CUBIERTO|NO_VERIFICABLE|SIN_COBERTURA|DESCARTADO)"
    r" \| ([^|]+) \| ([^|]+) \| ([^|]+) \| ([^|]+) \|$", re.M
)
MAT_ROW = re.compile(
    r"^\| \x60([^|]+)\x60 · \x60([^|]+)\x60 \| (.*?) \| \x60([A-Z_]+)\x60 \| (.*?) \| (.*?) \|$",
    re.M,
)


def parse_report(text: str) -> dict[str, tuple[str, str, str, str, str]]:
    found = {}
    for ident, state, code, test, pr, reason in REP_ROW.findall(text):
        if ident in found:
            raise ValueError("ID duplicado: " + ident)
        found[ident] = (state, code.strip(), test.strip(), pr.strip(), reason.strip())
    return found


def verify(ident: str, row: tuple[str, str, str, str, str]) -> None:
    state, code, test, pr, reason = row
    if state not in STATES or len(reason) < 100 or not reason.startswith(ident + ":"):
        raise ValueError("Veredicto sin razón: " + ident)
    if state == "CUBIERTO":
        if not code.startswith(("src/", "frontend/")) or not test.startswith("tests/"):
            raise ValueError("CUBIERTO sin código/test: " + ident)
        parts = test.split("::")
        if len(parts) != 3:
            raise ValueError("Prueba sin clase y método: " + ident)
        test_path, class_name, method_name = parts
        for path in (code, test_path):
            if not (ROOT / path).is_file():
                raise ValueError("Archivo inexistente: " + path)
        test_content = (ROOT / test_path).read_text(encoding="utf-8")
        if not re.search(r"\bclass\s+" + re.escape(class_name) + r"\b", test_content):
            raise ValueError("Clase inexistente: " + ident)
        if not re.search(r"\bfunction\s+" + re.escape(method_name) + r"\s*\(", test_content):
            raise ValueError("Método inexistente: " + ident)
        m = re.fullmatch(
            r"PR #(\d+) MERGED: https://github\.com/pl0n3r/Condor/pull/\1", pr
        )
        if not m or int(m.group(1)) not in MERGED:
            raise ValueError("PR MERGED no acreditado: " + ident)
        if MERGED[int(m.group(1))] not in reason or "No acredita" not in reason:
            raise ValueError("Cobertura sin límite o SHA: " + ident)
    elif state == "SIN_COBERTURA":
        if not re.fullmatch(
            r"https://github\.com/pl0n3r/Condor/issues/[2-9]\d+", pr
        ):
            raise ValueError("Falta hoja canónica: " + ident)
        if "factory-plan-task" not in reason:
            raise ValueError("Falta contrato de hoja: " + ident)
    elif state == "DESCARTADO":
        if pr != "—" or "Razón editorial" not in reason:
            raise ValueError("Descarte sin causa: " + ident)
    else:
        if pr != "—" or "Falta" not in reason or "no se fabrica SIN_COBERTURA" not in reason:
            raise ValueError("Brecha no justificada: " + ident)


def verify_matrix(doc_rows, text: str) -> None:
    matches = MAT_ROW.findall(text)
    if len(matches) != 472 or len({m[0] for m in matches}) != 472:
        raise ValueError("Inventario incompleto o duplicado")
    subset = {a: (b, c, d, e, f) for a, b, c, d, e, f in matches if a in EXPECTED}
    if set(subset) != EXPECTED or set(doc_rows) != EXPECTED:
        raise ValueError("Corte D3 incompleto")
    for ident, (state, code, test, pr, reason) in doc_rows.items():
        source, requirement, matrix_state, evidence, issue = subset[ident]
        if source != "ESPECIFICACIONES.md" or not requirement or matrix_state != state:
            raise ValueError("Estado o fuente sin sincronía: " + ident)
        if "[contraste](contraste-cd_d3.md)" not in evidence or ident not in evidence:
            raise ValueError("Enlace de contraste ausente: " + ident)
        if not re.fullmatch(r"#\d+", issue):
            raise ValueError("Issue de matriz inválido: " + ident)
        if state == "CUBIERTO" and ("PR #966 MERGED" not in evidence or "tests/Unit/PlatformOwnerNavigationTest.php" not in evidence):
            raise ValueError("Matriz sin prueba de cobertura: " + ident)
        if state == "DESCARTADO" and "Razón editorial" not in evidence:
            raise ValueError("Matriz sin justificación: " + ident)
        if state == "NO_VERIFICABLE" and "Falta" not in evidence:
            raise ValueError("Matriz sin brecha: " + ident)
    summary = collections.Counter(m[3] for m in matches)
    for state in ("CUBIERTO", "EN_CURSO", "SIN_COBERTURA", "DESCARTADO", "NO_VERIFICABLE"):
        if f"| {state} | {summary[state]} |" not in text:
            raise ValueError("Resumen desactualizado: " + state)
    if "| **TOTAL** | **472** |" not in text:
        raise ValueError("Total de cobertura incorrecto")
    pct = f"{100 * summary['CUBIERTO'] / 472:.1f}"
    if f"**Porcentaje " + chr(96) + "CUBIERTO" + chr(96) + f": {pct} %**" not in text:
        raise ValueError("Porcentaje incorrecto")


class ContrasteTests(unittest.TestCase):
    @classmethod
    def setUpClass(cls) -> None:
        cls.rows = parse_report(REPORT.read_text(encoding="utf-8"))
        cls.matrix = MATRIX.read_text(encoding="utf-8")

    def test_cada_fila_tiene_veredicto(self) -> None:
        self.assertEqual(93, len(EXPECTED))
        self.assertEqual(EXPECTED, set(self.rows))
        counts = collections.Counter(row[0] for row in self.rows.values())
        self.assertEqual({"CUBIERTO": 1, "DESCARTADO": 8, "NO_VERIFICABLE": 84}, dict(counts))
        for ident, row in self.rows.items():
            with self.subTest(ident=ident):
                verify(ident, row)
        first = next(iter(self.rows))
        line = next(s for s in REPORT.read_text().splitlines() if s.startswith(f"| {first} |"))
        with self.assertRaisesRegex(ValueError, "duplicado"):
            parse_report(line + "\n" + line)

    def test_cubierto_enlaza_prueba_existente(self) -> None:
        covered = [(ident, row) for ident, row in self.rows.items() if row[0] == "CUBIERTO"]
        self.assertEqual(1, len(covered))
        ident, row = covered[0]
        verify(ident, row)
        broken = list(row)
        broken[2] = "tests/Unit/Inexistente.php::NoExiste::noExiste"
        with self.assertRaisesRegex(ValueError, "inexistente"):
            verify(ident, tuple(broken))
        broken = list(row)
        broken[3] = "—"
        with self.assertRaisesRegex(ValueError, "MERGED"):
            verify(ident, tuple(broken))

    def test_faltante_tiene_issue(self) -> None:
        self.assertFalse(any(row[0] == "SIN_COBERTURA" for row in self.rows.values()))
        ident = "D-039"
        broken = list(self.rows[ident])
        broken[0] = "SIN_COBERTURA"
        broken[3] = "—"
        with self.assertRaisesRegex(ValueError, "hoja canónica"):
            verify(ident, tuple(broken))
        broken[3] = "https://github.com/pl0n3r/Condor/issues/1"
        with self.assertRaisesRegex(ValueError, "hoja canónica"):
            verify(ident, tuple(broken))

    def test_matriz_actualizada(self) -> None:
        verify_matrix(self.rows, self.matrix)
        invalid = self.matrix.replace("[contraste](contraste-cd_d3.md)", "sin enlace", 1)
        with self.assertRaisesRegex(ValueError, "Enlace"):
            verify_matrix(self.rows, invalid)


if __name__ == "__main__":
    unittest.main()
