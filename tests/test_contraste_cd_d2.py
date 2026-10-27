#!/usr/bin/env python3
"""Condor #1026: fail-closed coverage audit D-019 to D-036."""
from __future__ import annotations

import collections
import re
import unittest
from pathlib import Path

ROOT = Path(__file__).resolve().parents[1]
DOC = ROOT / "docs/contraste-cd_d2.md"
MATRIX = ROOT / "docs/matriz-cobertura.md"
EXPECTED = {f"D-{n:03d}" for n in range(19, 37)} | {f"D-030-S{n:02d}" for n in range(1, 5)}
STATES = {"CUBIERTO", "NO_VERIFICABLE", "SIN_COBERTURA", "DESCARTADO"}
MERGED = {180}  # Independently confirmed MERGED on GitHub.


def doc_rows(text: str) -> dict[str, list[str]]:
    result: dict[str, list[str]] = {}
    for line in text.splitlines():
        m = re.match(r"^\| (D-\d{3}(?:-S\d{2})?) \|", line)
        if m:
            cells = [c.strip() for c in line.strip("|").split("|")]
            if len(cells) != 6 or m.group(1) in result:
                raise ValueError("Fila incompleta o duplicada")
            result[m.group(1)] = cells
    return result


def matrix_rows(text: str) -> dict[str, list[str]]:
    result: dict[str, list[str]] = {}
    for line in text.splitlines():
        m = re.match(r"^\| \x60(D-\d{3}(?:-S\d{2})?)\x60 ·", line)
        if m and m.group(1) in EXPECTED:
            cells = [c.strip() for c in line.strip("|").split("|")]
            if len(cells) != 5 or m.group(1) in result:
                raise ValueError("Matriz duplicada o mal formada")
            result[m.group(1)] = cells
    return result


def validate_proof(rows: dict[str, list[str]]) -> None:
    for key, (_, state, path, test, pr, reason) in rows.items():
        if state not in STATES or len(reason) < 40:
            raise ValueError("Estado o razon invalida: " + key)
        if state == "CUBIERTO":
            if not path.startswith("src/") or not test.startswith("tests/"):
                raise ValueError("Falta codigo y prueba: " + key)
            for name in (path, test):
                if not (ROOT / name).is_file():
                    raise ValueError("Archivo inexistente: " + name)
            m = re.fullmatch(
                r"PR #(\d+) MERGED: https://github\.com/pl0n3r/Condor/pull/\1", pr
            )
            if not m or int(m.group(1)) not in MERGED:
                raise ValueError("PR no fusionado: " + key)
        if state == "SIN_COBERTURA":
            if not re.search(r"https://github\.com/pl0n3r/Condor/issues/\d+", pr):
                raise ValueError("Falta hoja canonica: " + key)
            if "factory-plan-task" not in reason:
                raise ValueError("Falta contrato de hoja: " + key)
        if state == "DESCARTADO" and not re.search(r"(?i)descart|no aplica|decision expresa", reason):
            raise ValueError("Descarte sin razon: " + key)


def validate_matrix(rows: dict[str, list[str]], text: str) -> None:
    matrix = matrix_rows(text)
    if set(rows) != EXPECTED or set(matrix) != EXPECTED:
        raise ValueError("IDs incompletos")
    for key, row in rows.items():
        entry = matrix[key]
        if entry[2] != chr(96) + row[1] + chr(96) or "[contraste](contraste-cd_d2.md)" not in entry[3]:
            raise ValueError("Matriz no actualizada: " + key)
        if row[1] == "CUBIERTO":
            if row[3] not in entry[3] or "PR #180 MERGED" not in entry[3]:
                raise ValueError("Falta evidencia: " + key)
        if row[1] == "SIN_COBERTURA" and not re.fullmatch(r"#\d+", entry[4]):
            raise ValueError("Falta Issue: " + key)


class ContrasteTests(unittest.TestCase):
    @classmethod
    def setUpClass(cls) -> None:
        cls.doc = doc_rows(DOC.read_text(encoding="utf-8"))
        cls.matrix = MATRIX.read_text(encoding="utf-8")

    def test_cada_fila_tiene_veredicto(self) -> None:
        self.assertEqual(set(self.doc), EXPECTED)
        self.assertEqual(len(self.doc), 22)
        self.assertEqual(sum(v[1] == "CUBIERTO" for v in self.doc.values()), 2)
        key = next(iter(self.doc))
        fake = f"| {key} | NO_VERIFICABLE | — | — | — | Razón insuficiente |"
        with self.assertRaises(ValueError):
            doc_rows(fake + "\n" + fake)

    def test_cubierto_enlaza_prueba_existente(self) -> None:
        validate_proof(self.doc)
        key = next(k for k, v in self.doc.items() if v[1] == "CUBIERTO")
        broken = {k: list(v) for k, v in self.doc.items()}
        broken[key][3] = "tests/php/inexistente.php"
        with self.assertRaisesRegex(ValueError, "inexistente"):
            validate_proof(broken)

    def test_faltante_tiene_issue(self) -> None:
        self.assertFalse(any(v[1] == "SIN_COBERTURA" for v in self.doc.values()))
        key = next(iter(self.doc))
        broken = {k: list(v) for k, v in self.doc.items()}
        broken[key][1] = "SIN_COBERTURA"
        broken[key][4] = "—"
        with self.assertRaisesRegex(ValueError, "hoja canonica"):
            validate_proof(broken)

    def test_matriz_actualizada(self) -> None:
        validate_matrix(self.doc, self.matrix)
        broken = self.matrix.replace("[contraste](contraste-cd_d2.md)", "enlace-roto", 1)
        with self.assertRaises(ValueError):
            validate_matrix(self.doc, broken)
        from test_matriz_cobertura import ROWS
        all_rows = ROWS.findall(self.matrix)
        self.assertEqual(len(all_rows), 472)
        counts = collections.Counter(row[3] for row in all_rows)
        for state in ("CUBIERTO", "EN_CURSO", "SIN_COBERTURA", "DESCARTADO", "NO_VERIFICABLE"):
            self.assertIn(f"| {state} | {counts[state]} |", self.matrix)
        self.assertIn("| **TOTAL** | **472** |", self.matrix)


if __name__ == "__main__":
    unittest.main()
