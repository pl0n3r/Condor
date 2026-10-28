#!/usr/bin/env python3
"""Condor #1029: 96 audit rows OPS/README/SPEC; conservative, fail-closed."""
from __future__ import annotations

import collections
import re
import unittest
from pathlib import Path

ROOT = Path(__file__).resolve().parents[1]
DOC = ROOT / "docs/contraste-cd_ops.md"
MATRIX = ROOT / "docs/matriz-cobertura.md"
EXPECTED = {"SPEC-5-1", "SPEC-5-2", "SPEC-5-3", "SPEC-5-4", "SPEC-5-5", "SPEC-BASE-0", "SPEC-BASE-1", "SPEC-BASE-2", "SPEC-BASE-3", "SPEC-BASE-4", "OPS-M01", "OPS-M01-PRESENTE", "OPS-M01-PENDIENTE", "OPS-M01-PRUEBA", "OPS-M02", "OPS-M02-PRESENTE", "OPS-M02-PENDIENTE", "OPS-M02-PRUEBA", "OPS-M03", "OPS-M03-PRESENTE", "OPS-M03-PENDIENTE", "OPS-M03-PRUEBA", "OPS-M04", "OPS-M04-PRESENTE", "OPS-M04-PENDIENTE", "OPS-M04-PRUEBA", "OPS-M05", "OPS-M05-PRESENTE", "OPS-M05-PENDIENTE", "OPS-M05-PRUEBA", "OPS-M06", "OPS-M06-PRESENTE", "OPS-M06-PENDIENTE", "OPS-M06-PRUEBA", "OPS-M07", "OPS-M07-PRESENTE", "OPS-M07-PENDIENTE", "OPS-M07-PRUEBA", "OPS-M08", "OPS-M08-PRESENTE", "OPS-M08-PENDIENTE", "OPS-M08-PRUEBA", "OPS-REC-A", "OPS-REC-A-P1", "OPS-REC-A-P2", "OPS-REC-A-P3", "OPS-REC-A-P4", "OPS-REC-B", "OPS-REC-B-P1", "OPS-REC-B-P2", "OPS-REC-B-P3", "OPS-REC-B-P4", "OPS-REC-C", "OPS-REC-C-P1", "OPS-REC-C-P2", "OPS-REC-C-P3", "OPS-REC-C-P4", "OPS-REC-D", "OPS-REC-D-P1", "OPS-REC-D-P2", "OPS-REC-D-P3", "OPS-REC-D-P4", "OPS-LIM", "README-01-01", "README-02-01", "README-02-02", "README-02-03", "README-02-04", "README-03-01", "README-03-02", "README-03-03", "README-03-04", "README-03-05", "README-03-06", "README-04-01", "README-05-01", "README-05-02", "README-05-03", "README-05-04", "README-05-05", "README-06-01", "README-07-01", "README-07-02", "README-07-03", "README-07-04", "README-08-01", "README-08-02", "README-08-03", "README-08-04", "README-09-01", "README-09-02", "README-10-01", "README-10-02", "README-10-03", "README-10-04", "README-10-05"}
STATES = {"CUBIERTO", "NO_VERIFICABLE", "DESCARTADO", "SIN_COBERTURA"}
MERGED = {
    152: "0a60bafad39ef1b6bbda4bb475a7a5a005b157d4",
    172: "90083457d9d2d1777138b7a4d54048fa6fafc513",
    180: "f22e9d15269adebaee5155450f100c8b87a0ef7f",
    182: "4c3e7c70a767db5d2aafb7614205cc96cac8c524",
    697: "c55a6b5497f138362729388ec0b4d0c187dad771",
    700: "3f3c57bcdc1bf92377b55730cca83ea867f52ffd",
    710: "d798ceaf711b67047abcefd7b5b826ad7afeebf5",
    885: "e300ab3321963b2c76ebaacf51c48991ff5eec69",
}
REPORT_ROW = re.compile(
    r"^\| \x60((?:OPS|README|SPEC)-[^\x60]+)\x60 \| \x60([^\x60]+)\x60 \| \x60([A-Z_]+)\x60 \| \x60([^\x60]+)\x60 \| \x60([^\x60]+)\x60 \| (.*?) \| (.*?) \|$",
    re.MULTILINE,
)
MATRIX_ROW = re.compile(
    r"^\| \x60([^|]+)\x60 · \x60([^|]+)\x60 \| (.*?) \| \x60([A-Z_]+)\x60 \| (.*?) \| (.*?) \|$",
    re.MULTILINE,
)
PATH = re.compile(r"(?:src|frontend|tests)/[A-Za-z0-9_./-]+\.(?:php|py|tsx|js)")
PR = re.compile(r"\[PR #(\d+) MERGED\]\(https://github\.com/pl0n3r/Condor/pull/\1\) · ([0-9a-f]{40})")


def parse_doc(text: str) -> dict[str, tuple[str, str, str, str, str, str]]:
    matches = REPORT_ROW.findall(text)
    if len(matches) != len({row[0] for row in matches}):
        raise ValueError("IDs del contraste duplicados")
    return {k: (source, state, code, test, pr, reason)
            for k, source, state, code, test, pr, reason in matches}


def parse_matrix(text: str) -> dict[str, tuple[str, str, str, str, str]]:
    matches = MATRIX_ROW.findall(text)
    if len(matches) != len({row[0] for row in matches}):
        raise ValueError("IDs de matriz duplicados")
    return {k: (source, requirement, state, evidence, issue)
            for k, source, requirement, state, evidence, issue in matches}


def validate_proof(key: str, entry: tuple[str, str, str, str, str, str]) -> None:
    source, state, code, test, pr, reason = entry
    if state not in STATES or len(reason) < 75 or key not in reason:
        raise ValueError("Veredicto incompleto: " + key)
    if not (ROOT / source).is_file():
        raise ValueError("Fuente inexistente: " + key)
    if state == "CUBIERTO":
        if not (code.startswith("src/") or code.startswith("frontend/")) or not test.startswith("tests/"):
            raise ValueError("CUBIERTO sin código y prueba: " + key)
        for path in (code, test):
            if not (ROOT / path).is_file():
                raise ValueError("CUBIERTO apunta a archivo inexistente: " + path)
        match = PR.fullmatch(pr)
        if not match or MERGED.get(int(match.group(1))) != match.group(2):
            raise ValueError("PR MERGED no validado: " + key)
        if "No incluye" not in reason and "No acredita" not in reason:
            raise ValueError("Cobertura sin límite: " + key)
    elif state == "NO_VERIFICABLE":
        if not reason.startswith("Falta"):
            raise ValueError("Falta motivo específico: " + key)
        if pr != "—":
            raise ValueError("NO_VERIFICABLE no hereda un PR: " + key)
    elif state == "DESCARTADO":
        if "Razón" not in reason or not re.search(r"editorial|decisión|convención|funcional", reason, re.I):
            raise ValueError("DESCARTADO sin justificación normativa: " + key)
        if pr != "—":
            raise ValueError("DESCARTADO no es merge funcional: " + key)
    else:
        if not re.search(r"https://github\.com/pl0n3r/Condor/issues/[2-9]\d+", pr):
            raise ValueError("SIN_COBERTURA sin hoja canónica: " + key)
        if "factory-plan-task" not in reason:
            raise ValueError("SIN_COBERTURA sin contrato ejecutable: " + key)


class ContrasteTests(unittest.TestCase):
    @classmethod
    def setUpClass(cls):
        cls.doc_text = DOC.read_text(encoding="utf-8")
        cls.matrix_text = MATRIX.read_text(encoding="utf-8")
        cls.doc = parse_doc(cls.doc_text)
        cls.matrix = parse_matrix(cls.matrix_text)

    def test_cada_fila_tiene_veredicto(self):
        self.assertEqual(EXPECTED, set(self.doc))
        self.assertEqual(EXPECTED, {k for k in self.matrix if k.startswith(("OPS-", "README-", "SPEC-"))})
        for key, entry in self.doc.items():
            with self.subTest(id=key):
                validate_proof(key, entry)
        states = collections.Counter(row[1] for row in self.doc.values())
        self.assertEqual({"CUBIERTO": 16, "NO_VERIFICABLE": 74, "DESCARTADO": 6}, dict(states))

    def test_cubierto_enlaza_prueba_existente(self):
        covered = {k: v for k, v in self.doc.items() if v[1] == "CUBIERTO"}
        self.assertEqual(16, len(covered))
        for key, entry in covered.items():
            with self.subTest(id=key):
                validate_proof(key, entry)
        key, original = next(iter(covered.items()))
        broken = list(original)
        broken[3] = "tests/no-existe-el-caso_1029.py"
        with self.assertRaisesRegex(ValueError, "inexistente"):
            validate_proof(key, tuple(broken))
        broken = list(original)
        broken[4] = "—"
        with self.assertRaisesRegex(ValueError, "PR MERGED"):
            validate_proof(key, tuple(broken))

    def test_faltante_tiene_issue(self):
        self.assertFalse(any(v[1] == "SIN_COBERTURA" for v in self.doc.values()))
        key, original = next(iter(self.doc.items()))
        broken = list(original)
        broken[1] = "SIN_COBERTURA"
        broken[4] = "—"
        with self.assertRaisesRegex(ValueError, "hoja canónica"):
            validate_proof(key, tuple(broken))
        broken[4] = "https://github.com/pl0n3r/Condor/issues/1"
        with self.assertRaisesRegex(ValueError, "hoja canónica"):
            validate_proof(key, tuple(broken))

    def test_matriz_actualizada(self):
        self.assertEqual(EXPECTED, set(self.doc))
        for key, row in self.doc.items():
            with self.subTest(id=key):
                self.assertIn(key, self.matrix)
                source, _requirement, state, evidence, issue = self.matrix[key]
                self.assertEqual(row[0], source)
                self.assertEqual(row[1], state)
                self.assertIn("[contraste](contraste-cd_ops.md)", evidence)
                self.assertRegex(issue, r"^#\d+$")
                if state == "CUBIERTO":
                    match = PR.fullmatch(row[4])
                    self.assertIsNotNone(match)
                    self.assertIn(f"PR #{match.group(1)} MERGED", evidence)
                    self.assertIn(row[3], evidence)
                elif state == "DESCARTADO":
                    self.assertIn("Razón", evidence)
                elif state == "NO_VERIFICABLE":
                    self.assertIn("Falta", evidence)
        totals = collections.Counter(row[2] for row in self.matrix.values())
        self.assertEqual(472, len(self.matrix))
        for state in ("CUBIERTO", "NO_VERIFICABLE", "DESCARTADO", "SIN_COBERTURA", "EN_CURSO"):
            self.assertIn(f"| {state} | {totals[state]} |", self.matrix_text)
        self.assertIn("| **TOTAL** | **472** |", self.matrix_text)


if __name__ == "__main__":
    unittest.main()
