#!/usr/bin/env python3
"""Regresión ejecutable #1030: contraste M09/M11/M14, fail-closed."""
from __future__ import annotations

import re
import unittest
from pathlib import Path

ROOT = Path(__file__).resolve().parents[1]
REPORT = ROOT / "docs/contraste-cd_modbase.md"
MATRIX = ROOT / "docs/matriz-cobertura.md"
SOURCE = ROOT / "docs/gestion-empresarial-integral.md"
GROUPS = {"GEST-M09": 8, "GEST-M11": 7, "GEST-M14": 8}
EXPECTED = {key for root, count in GROUPS.items()
            for key in [root, *(f"{root}-S{i:02d}" for i in range(1, count + 1))]}
STATES = {"CUBIERTO", "NO_VERIFICABLE", "SIN_COBERTURA", "DESCARTADO"}
MERGED = {
    700: "3f3c57bcdc1bf92377b55730cca83ea867f52ffd",
    697: "c55a6b5497f138362729388ec0b4d0c187dad771",
}
REPORT_RE = re.compile(
    r"^\| \x60(?P<key>GEST-M(?:09|11|14)(?:-S\d{2})?)\x60"
    r" \| \x60(?P<state>[A-Z_]+)\x60"
    r" \| \x60(?P<code>[^|]+)\x60"
    r" \| \x60(?P<test>[^|]+)\x60"
    r" \| \x60(?P<pr>[^|]+)\x60"
    r" \| (?P<reason>[^|\n]+) \|$", re.MULTILINE,
)
MATRIX_RE = re.compile(
    r"^\| \x60(?P<key>GEST-M(?:09|11|14)(?:-S\d{2})?)\x60"
    r" · \x60docs/gestion-empresarial-integral\.md\x60"
    r" \| (?P<requirement>.*?)"
    r" \| \x60(?P<state>[A-Z_]+)\x60"
    r" \| (?P<evidence>.*?)"
    r" \| (?P<issue>#[0-9]+) \|$", re.MULTILINE,
)
PR_RE = re.compile(r"PR #([0-9]+) MERGED @([0-9a-f]{40})")


def parse_unique(pattern: re.Pattern[str], text: str) -> dict[str, dict[str, str]]:
    matches = [m.groupdict() for m in pattern.finditer(text)]
    keys = [m["key"] for m in matches]
    if len(keys) != len(set(keys)):
        raise ValueError("IDs duplicados en contraste o matriz")
    return {entry["key"]: entry for entry in matches}


def verify_entry(key: str, row: dict[str, str]) -> None:
    state, code, test, pr, reason = (
        row["state"], row["code"], row["test"], row["pr"], row["reason"]
    )
    if state not in STATES or len(reason) < 90 or key not in reason:
        raise ValueError("Veredicto vacío, genérico o sin ID: " + key)
    for candidate in (code, test):
        if candidate != "—" and not (ROOT / candidate).is_file():
            raise ValueError("Ruta candidata o prueba inexistente: " + candidate)
    if state == "CUBIERTO":
        if not code.startswith("src/") or not test.startswith("tests/"):
            raise ValueError("CUBIERTO sin código y test: " + key)
        match = PR_RE.fullmatch(pr)
        if not match or MERGED.get(int(match.group(1))) != match.group(2):
            raise ValueError("PR MERGED sin procedencia acreditada: " + key)
        if "No acredita" not in reason:
            raise ValueError("CUBIERTO sin límite explícito: " + key)
    elif state == "NO_VERIFICABLE":
        if not reason.startswith("Falta") or pr != "—":
            raise ValueError("NO_VERIFICABLE debe explicar evidencia ausente sin PR: " + key)
    elif state == "DESCARTADO":
        if "Razón" not in reason or pr != "—":
            raise ValueError("DESCARTADO sin justificación normativa: " + key)
    elif state == "SIN_COBERTURA":
        if not re.search(r"https://github\.com/pl0n3r/Condor/issues/[2-9][0-9]+", pr):
            raise ValueError("SIN_COBERTURA sin hoja ejecutable nueva: " + key)
        if "factory-plan-task" not in reason:
            raise ValueError("SIN_COBERTURA sin contrato ejecutable: " + key)


class ContrasteTests(unittest.TestCase):
    @classmethod
    def setUpClass(cls) -> None:
        cls.report = parse_unique(REPORT_RE, REPORT.read_text(encoding="utf-8"))
        cls.matrix = parse_unique(MATRIX_RE, MATRIX.read_text(encoding="utf-8"))

    def test_cada_fila_tiene_veredicto(self) -> None:
        self.assertTrue(SOURCE.is_file())
        self.assertEqual(EXPECTED, set(self.report))
        self.assertEqual(EXPECTED, set(self.matrix))
        self.assertEqual(26, len(self.report))
        for key, entry in self.report.items():
            with self.subTest(id=key):
                verify_entry(key, entry)
        self.assertEqual(2, sum(row["state"] == "CUBIERTO" for row in self.report.values()))
        self.assertEqual(24, sum(row["state"] == "NO_VERIFICABLE" for row in self.report.values()))

    def test_cubierto_enlaza_prueba_existente(self) -> None:
        covered = {key: row for key, row in self.report.items()
                   if row["state"] == "CUBIERTO"}
        self.assertEqual({"GEST-M09", "GEST-M11"}, set(covered))
        for key, row in covered.items():
            with self.subTest(id=key):
                verify_entry(key, row)
                self.assertIn(Path(row["code"]).stem,
                              (ROOT / row["test"]).read_text(encoding="utf-8"))
                altered = {**row, "test": "tests/falso_1030.py"}
                with self.assertRaisesRegex(ValueError, "inexistente"):
                    verify_entry(key, altered)
                altered = {**row, "pr": "—"}
                with self.assertRaisesRegex(ValueError, "PR MERGED"):
                    verify_entry(key, altered)

    def test_faltante_tiene_issue(self) -> None:
        for key, row in self.report.items():
            if row["state"] == "SIN_COBERTURA":
                with self.subTest(id=key):
                    verify_entry(key, row)
        self.assertFalse(any(row["state"] == "SIN_COBERTURA"
                             for row in self.report.values()))
        key, row = next(iter(self.report.items()))
        malformed = {**row, "state": "SIN_COBERTURA", "pr": "—"}
        with self.assertRaisesRegex(ValueError, "hoja ejecutable"):
            verify_entry(key, malformed)

    def test_matriz_actualizada(self) -> None:
        self.assertEqual(EXPECTED, set(self.matrix))
        for key, row in self.report.items():
            with self.subTest(id=key):
                matrix = self.matrix[key]
                self.assertEqual(row["state"], matrix["state"])
                self.assertIn("[contraste #1030](contraste-cd_modbase.md)",
                              matrix["evidence"])
                self.assertIn("GEST-M", key)
                if row["state"] == "CUBIERTO":
                    self.assertIn(row["test"], matrix["evidence"])
        full = MATRIX.read_text(encoding="utf-8")
        self.assertIn("**TOTAL** | **472**", full)
        self.assertIn("## Resumen de cobertura", full)


if __name__ == "__main__":
    unittest.main()
