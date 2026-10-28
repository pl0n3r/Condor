#!/usr/bin/env python3
"""Condor #1028: 52 AGENTES-* audited without claiming deployment."""
from __future__ import annotations
import collections
from pathlib import Path
import re
import unittest

ROOT = Path(__file__).resolve().parents[1]
DOC = ROOT / "docs/contraste-cd_agentes.md"
MATRIX = ROOT / "docs/matriz-cobertura.md"
AGENTS = ROOT / "AGENTES.md"
ROW = re.compile(r"^\| \x60(AGENTES-\d{2}-\d{2})\x60 \| \x60(AGENTES\.md)\x60 \| \x60(CUBIERTO|DESCARTADO|NO_VERIFICABLE|SIN_COBERTURA)\x60 \| \x60([^\x60]+)\x60 \| \x60([^\x60]+)\x60 \| (.*?) \| (.*?) \|$", re.MULTILINE)
MAT = re.compile(r"^\| \x60([^|]+)\x60 · \x60([^|]+)\x60 \| (.*?) \| \x60([A-Z_]+)\x60 \| (.*?) \| (.*?) \|$", re.MULTILINE)
PR = re.compile(r"\[PR #(\d+) MERGED\]\(https://github\.com/pl0n3r/Condor/pull/\1\) · ([a-f0-9]{40})")
MERGED = {1119: "23a0ddca019229ce7edfd65e3678aff2350bc733"}
EXPECTED = {f"AGENTES-{s:02d}-{n:02d}" for s in range(1,10)
            for n in range(1, 5 if s == 9 else 7)}


def parse_report(text: str) -> dict[str, tuple[str, str, str, str, str, str]]:
    matches = ROW.findall(text)
    if len(matches) != len({r[0] for r in matches}):
        raise ValueError("Contraste contiene IDs duplicados")
    return {id: (source, state, code, test, pr, reason)
            for id, source, state, code, test, pr, reason in matches}


def parse_matrix(text: str) -> dict[str, tuple[str, str, str, str, str]]:
    matches = MAT.findall(text)
    if len(matches) != len({r[0] for r in matches}):
        raise ValueError("Matriz contiene IDs duplicados")
    return {id: (source, req, state, proof, issue)
            for id, source, req, state, proof, issue in matches}


def verify(id: str, value: tuple[str, str, str, str, str, str]) -> None:
    source, state, code, test, pr, reason = value
    if source != "AGENTES.md" or not AGENTS.is_file() or id not in reason or len(reason) < 100:
        raise ValueError("Veredicto incompleto: " + id)
    if state == "CUBIERTO":
        if not code.startswith("src/") or not test.startswith("tests/") or test.count("::") != 2:
            raise ValueError("CUBIERTO sin código y prueba: " + id)
        path, cls, method = test.split("::")
        if not (ROOT / code).is_file() or not (ROOT / path).is_file():
            raise ValueError("CUBIERTO con ruta inexistente: " + id)
        content = (ROOT / path).read_text(encoding="utf-8")
        if not re.search(r"(?m)^class " + re.escape(cls) + r"\(", content) or not re.search(r"(?m)^\s+def " + re.escape(method) + r"\(", content):
            raise ValueError("CUBIERTO con prueba inexistente: " + id)
        evidence = PR.fullmatch(pr)
        if evidence is None or MERGED.get(int(evidence.group(1))) != evidence.group(2):
            raise ValueError("CUBIERTO sin PR MERGED auténtico: " + id)
        if "No acredita" not in reason:
            raise ValueError("CUBIERTO sin límite de evidencia: " + id)
    elif state == "DESCARTADO":
        if pr != "—" or "Razón editorial" not in reason:
            raise ValueError("DESCARTADO sin justificación: " + id)
    elif state == "NO_VERIFICABLE":
        if pr != "—" or not reason.startswith(id + ": Falta"):
            raise ValueError("NO_VERIFICABLE sin brecha: " + id)
    elif state == "SIN_COBERTURA":
        if not re.search(r"https://github\.com/pl0n3r/Condor/issues/[2-9]\d+", pr) or "factory-plan-task" not in reason:
            raise ValueError("SIN_COBERTURA sin hoja canónica: " + id)
    else:
        raise ValueError("Estado inválido: " + id)


class ContrasteTests(unittest.TestCase):
    @classmethod
    def setUpClass(cls) -> None:
        cls.doc = parse_report(DOC.read_text(encoding="utf-8"))
        cls.matrix_text = MATRIX.read_text(encoding="utf-8")
        cls.matrix = parse_matrix(cls.matrix_text)

    def test_cada_fila_tiene_veredicto(self) -> None:
        self.assertEqual(EXPECTED, set(self.doc))
        self.assertEqual(EXPECTED, {id for id in self.matrix if id.startswith("AGENTES-")})
        for id, row in self.doc.items():
            with self.subTest(id=id):
                verify(id, row)
        self.assertEqual({"CUBIERTO": 3, "DESCARTADO": 23, "NO_VERIFICABLE": 26},
                         dict(collections.Counter(row[1] for row in self.doc.values())))

    def test_cubierto_enlaza_prueba_existente(self) -> None:
        covered = [(id, row) for id, row in self.doc.items() if row[1] == "CUBIERTO"]
        self.assertEqual(3, len(covered))
        for id, row in covered:
            verify(id, row)
        id, row = covered[0]
        broken = list(row)
        broken[3] = "tests/test_payroll_persistence.py::ModuloTests::no_such_test"
        with self.assertRaisesRegex(ValueError, "prueba inexistente"):
            verify(id, tuple(broken))
        broken = list(row)
        broken[4] = "—"
        with self.assertRaisesRegex(ValueError, "PR MERGED"):
            verify(id, tuple(broken))

    def test_faltante_tiene_issue(self) -> None:
        self.assertFalse(any(row[1] == "SIN_COBERTURA" for row in self.doc.values()))
        id, row = next(iter(self.doc.items()))
        broken = list(row)
        broken[1] = "SIN_COBERTURA"
        broken[4] = "—"
        with self.assertRaisesRegex(ValueError, "hoja canónica"):
            verify(id, tuple(broken))
        broken[4] = "https://github.com/pl0n3r/Condor/issues/1"
        with self.assertRaisesRegex(ValueError, "hoja canónica"):
            verify(id, tuple(broken))

    def test_matriz_actualizada(self) -> None:
        self.assertEqual(472, len(self.matrix))
        self.assertEqual(52, len(self.doc))
        for id, row in self.doc.items():
            with self.subTest(id=id):
                source, req, state, evidence, issue = self.matrix[id]
                self.assertEqual("AGENTES.md", source)
                self.assertTrue(req)
                self.assertEqual(row[1], state)
                self.assertIn("[contraste](contraste-cd_agentes.md)", evidence)
                self.assertIn(id, evidence)
                self.assertRegex(issue, r"^#\d+$")
                if state == "CUBIERTO":
                    self.assertIn(row[3], evidence)
                    self.assertIn("[PR #1119 MERGED]", evidence)
                elif state == "DESCARTADO":
                    self.assertIn("Razón editorial", evidence)
                elif state == "NO_VERIFICABLE":
                    self.assertIn("Falta", evidence)
        totals = collections.Counter(row[2] for row in self.matrix.values())
        for status in ("CUBIERTO", "EN_CURSO", "SIN_COBERTURA", "DESCARTADO", "NO_VERIFICABLE"):
            self.assertIn(f"| {status} | {totals[status]} |", self.matrix_text)
        self.assertIn("| **TOTAL** | **472** |", self.matrix_text)
        self.assertIn("**Porcentaje " + chr(96) + "CUBIERTO" + chr(96) + ": 5.5 %**", self.matrix_text)


if __name__ == "__main__":
    unittest.main()
