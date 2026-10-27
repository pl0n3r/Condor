#!/usr/bin/env python3
"""#1025: conservative D-001..D-018 acceptance, real matrix required for AC-04."""
from __future__ import annotations

import collections
import re
import unittest
from pathlib import Path

ROOT = Path(__file__).resolve().parents[1]
REPORT = ROOT / "docs/contraste-cd_d1.md"
MATRIX = ROOT / "docs/matriz-cobertura.md"
SOURCE = ROOT / "ESPECIFICACIONES.md"
EXPECTED = {f"D-{number:03d}" for number in range(1, 19)}
STATES = {"CUBIERTO", "NO_VERIFICABLE", "DESCARTADO", "SIN_COBERTURA"}
REPORT_ROW = re.compile(
    r"^\| \x60(D-\d{3}(?:-S\d+)?)\x60 \| \x60([A-Z_]+)\x60 \| (.*?) \|$",
    re.MULTILINE,
)
MATRIX_ROW = re.compile(
    r"^\| \x60([^|]+)\x60 · \x60([^|]+)\x60 \| (.*?) \| \x60([A-Z_]+)\x60 \| (.*?) \| (.*?) \|$",
    re.MULTILINE,
)
PATH = re.compile(r"(?:src|frontend|tests)/[A-Za-z0-9_./-]+\.(?:php|py|tsx|js)")
MERGED_PR = re.compile(
    r"\[PR #(\d+) MERGED\]\(https://github\.com/pl0n3r/Condor/pull/\1\)"
)


def extract_report(text: str) -> dict[str, dict[str, str]]:
    rows = REPORT_ROW.findall(text)
    if len(rows) != len(set(row[0] for row in rows)):
        raise AssertionError("Duplicate contrast IDs")
    return {key: {"state": state, "evidence": evidence}
            for key, state, evidence in rows}


def extract_matrix(text: str) -> dict[str, dict[str, str]]:
    rows = MATRIX_ROW.findall(text)
    if len(rows) != len(set(row[0] for row in rows)):
        raise AssertionError("Duplicate matrix IDs")
    return {key: {"source": source, "requirement": requirement,
                  "state": state, "evidence": evidence, "issue": issue}
            for key, source, requirement, state, evidence, issue in rows}


class ContrasteTests(unittest.TestCase):
    @classmethod
    def setUpClass(cls) -> None:
        cls.doc = REPORT.read_text(encoding="utf-8")
        cls.matrix_doc = MATRIX.read_text(encoding="utf-8")
        cls.spec = SOURCE.read_text(encoding="utf-8")
        cls.rows = extract_report(cls.doc)
        cls.matrix = extract_matrix(cls.matrix_doc)

    def test_cada_fila_tiene_veredicto(self) -> None:
        """AC-01: the 18 normative headings are covered without invented sub-IDs."""
        self.assertEqual(EXPECTED, set(self.rows))
        headings = set(re.findall(r"^### (D-\d{3}) —", self.spec,
                                  re.MULTILINE))
        self.assertEqual(EXPECTED, headings & EXPECTED)
        source_rows = {key for key in self.matrix
                       if re.fullmatch(r"D-0(?:0[1-9]|1[0-8])(?:-S\d+)?", key)}
        self.assertEqual(EXPECTED, source_rows)
        for key, row in self.rows.items():
            with self.subTest(id=key):
                self.assertIn(row["state"], STATES)
                self.assertGreater(len(row["evidence"]), 95)
                if row["state"] == "NO_VERIFICABLE":
                    self.assertIn("Falta", row["evidence"])
                    self.assertIn(key, row["evidence"])
        counts = collections.Counter(row["state"] for row in self.rows.values())
        self.assertEqual({"DESCARTADO": 6, "NO_VERIFICABLE": 11,
                          "CUBIERTO": 1}, dict(counts))

    def test_cubierto_enlaza_prueba_existente(self) -> None:
        """AC-02: real code, real test, authenticated merged PR for each covered."""
        covered = {key: row for key, row in self.rows.items()
                   if row["state"] == "CUBIERTO"}
        self.assertEqual({"D-017"}, set(covered))
        for key, row in covered.items():
            with self.subTest(id=key):
                evidence = row["evidence"]
                matches = PATH.findall(evidence)
                self.assertGreaterEqual(len(matches), 2)
                self.assertTrue(any(p.startswith("src/") for p in matches))
                self.assertTrue(any(p.startswith("tests/") for p in matches))
                for path in matches:
                    self.assertTrue((ROOT / path).is_file(), path)
                self.assertRegex(evidence, MERGED_PR)
                # Authenticated PR #152 squash SHA on GitHub at contrast time.
                self.assertIn("[PR #152 MERGED]", evidence)
                self.assertIn("0a60bafad39ef1b6bbda4bb475a7a5a005b157d4",
                              evidence)
                self.assertIn("limitada", evidence)

    def test_faltante_tiene_issue(self) -> None:
        """AC-03: no fabricated missing-feature tickets from unknown evidence."""
        for key, row in self.rows.items():
            with self.subTest(id=key):
                state, evidence = row["state"], row["evidence"]
                if state == "SIN_COBERTURA":
                    self.assertRegex(evidence, r"#[2-9]\d+")
                    self.assertIn("factory-plan-task", evidence)
                    self.assertIn("hoja", evidence.lower())
                elif state == "DESCARTADO":
                    self.assertIn("Razón", evidence)
                    self.assertIn(key, evidence)
                    self.assertRegex(evidence.lower(), r"editorial|gobernanza|proceso")
                elif state == "NO_VERIFICABLE":
                    self.assertIn("Falta", evidence)
        self.assertFalse(any(r["state"] == "SIN_COBERTURA"
                             for r in self.rows.values()))

    def test_matriz_actualizada(self) -> None:
        """AC-04 fails until reserved matrix is updated and summary reconciled."""
        self.assertEqual(EXPECTED, set(self.rows))
        for key, row in self.rows.items():
            with self.subTest(id=key):
                matrix = self.matrix[key]
                self.assertEqual("ESPECIFICACIONES.md", matrix["source"])
                self.assertEqual(row["state"], matrix["state"],
                                 "Report alone cannot close this issue")
                self.assertIn("docs/contraste-cd_d1.md", matrix["evidence"])
                self.assertRegex(matrix["issue"], r"^#\d+$")
                if row["state"] == "DESCARTADO":
                    self.assertIn("Razón", matrix["evidence"])
                elif row["state"] == "NO_VERIFICABLE":
                    self.assertIn("Falta", matrix["evidence"])
                    self.assertIn(key, matrix["evidence"])
                else:
                    self.assertRegex(matrix["evidence"], MERGED_PR)
        counts = collections.Counter(row["state"] for row in self.matrix.values())
        for state in ("CUBIERTO", "NO_VERIFICABLE", "DESCARTADO",
                      "SIN_COBERTURA", "EN_CURSO"):
            self.assertIn(f"| {state} | {counts[state]} |", self.matrix_doc)
        self.assertIn(f"| **TOTAL** | **{len(self.matrix)}** |",
                      self.matrix_doc)
        pct = 100 * counts["CUBIERTO"] / len(self.matrix)
        self.assertIn(f"**Porcentaje {chr(96)}CUBIERTO{chr(96)}: {pct:.1f} %**",
                      self.matrix_doc)


if __name__ == "__main__":
    unittest.main()
