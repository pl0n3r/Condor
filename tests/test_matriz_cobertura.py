#!/usr/bin/env python3
"""AC-01..04 #978: auditoría verificable y conservadora de la matriz de requisitos."""
from __future__ import annotations

import collections
import re
import unittest
from pathlib import Path

ROOT = Path(__file__).resolve().parents[1]
MATRIX = ROOT / "docs/matriz-cobertura.md"
SPEC = ROOT / "ESPECIFICACIONES.md"
ENTERPRISE = ROOT / "docs/gestion-empresarial-integral.md"
OPERATIONS = ROOT / "docs/modulos-operativos.md"
STATES = {"CUBIERTO", "EN_CURSO", "SIN_COBERTURA", "DESCARTADO", "NO_VERIFICABLE"}
ROWS = re.compile(r"^\| \x60([^|]+)\x60 · \x60([^|]+)\x60 \| (.*?) \| \x60([A-Z_]+)\x60 \| (.*?) \| (.*?) \|$", re.MULTILINE)


def split_modules(value: str) -> list[str]:
    text = value.strip().replace("; ", ", ")
    text = re.sub(r"\s+y\s+(?=[^,]+$)", ", ", text)
    return [part.strip() for part in text.split(",") if part.strip()]


class MatrizCoberturaTests(unittest.TestCase):
    @classmethod
    def setUpClass(cls) -> None:
        cls.doc = MATRIX.read_text(encoding="utf-8")
        cls.rows = [dict(id=a, source=b, requirement=c, state=d, evidence=e, issue=f)
                    for a, b, c, d, e, f in ROWS.findall(cls.doc)]
        cls.ids = {row["id"] for row in cls.rows}

    def test_cubre_todas_las_fuentes(self) -> None:
        spec = SPEC.read_text(encoding="utf-8")
        enterprise = ENTERPRISE.read_text(encoding="utf-8")
        operations = OPERATIONS.read_text(encoding="utf-8")
        decisions = set(re.findall(r"^### (D-\d{3}) \u2014", spec, re.MULTILINE))
        self.assertEqual({f"D-{n:03d}" for n in range(1, 55)}, decisions)
        self.assertTrue(decisions.issubset(self.ids), "Falta decisión D-001..D-054")
        module_rows = re.findall(r"^\|\s*(\d{1,2})\s*\|([^|]*)\|([^|]*)\|", enterprise, re.MULTILINE)
        self.assertEqual(16, len(module_rows))
        for number, name, submodules in module_rows:
            with self.subTest(module=number):
                prefix = f"GEST-M{int(number):02d}"
                self.assertIn(prefix, self.ids)
                expected = split_modules(submodules)
                for index, submodule in enumerate(expected, 1):
                    key = f"{prefix}-S{index:02d}"
                    self.assertIn(key, self.ids)
                    row = next(r for r in self.rows if r["id"] == key)
                    self.assertIn(submodule, row["requirement"])
        # Comprobar también la granularidad de subapartados, límites, flujos y AC.
        specs_sections = re.findall(r"(?m)^#### ([^\n]+)$", spec)
        self.assertGreaterEqual(len([id for id in self.ids if re.match(r"D-\d{3}-S\d{2}$", id)]), len(specs_sections))
        enterprise_limits = re.search(r"## Límites no negociables([\s\S]*?)## Catálogo de módulos", enterprise).group(1)
        for n, _ in enumerate(re.findall(r"(?m)^- ", enterprise_limits), 1):
            self.assertIn(f"GEST-LIM-{n:02d}", self.ids)
        for n in range(1, 6):
            self.assertIn(f"GEST-FLUJO-{n}", self.ids)
        enterprise_acceptance = re.search(r"## Criterios de aceptación transversales([\s\S]*?)## Trazabilidad", enterprise).group(1)
        for n, _ in enumerate(re.findall(r"(?m)^- ", enterprise_acceptance), 1):
            self.assertIn(f"GEST-AC-{n:02d}", self.ids)
        for phase in range(7):
            self.assertIn(f"GEST-F{phase}", self.ids)
        for number in range(1, 9):
            prefix = f"OPS-M{number:02d}"
            for suffix in ("", "-PRESENTE", "-PENDIENTE", "-PRUEBA"):
                self.assertIn(prefix + suffix, self.ids)
            self.assertRegex(operations, rf"(?m)^### {number}\. ")
        for flow in "ABCD":
            self.assertIn(f"OPS-REC-{flow}", self.ids)
        self.assertGreaterEqual(len(self.rows), 190, "No reducir la granularidad")

    def test_sin_cobertura_tiene_issue(self) -> None:
        for row in self.rows:
            with self.subTest(id=row["id"]):
                if row["state"] == "SIN_COBERTURA":
                    self.assertRegex(row["issue"], r"^#\d+$")
                    self.assertRegex(row["evidence"], r".{12,}")
                if row["state"] == "EN_CURSO":
                    self.assertRegex(row["issue"], r"^#\d+$")

    def test_formato_y_estados(self) -> None:
        self.assertIn("| ID / fuente | Requisito resumido | Estado | Evidencia y límite de verificación | Issue |", self.doc)
        self.assertEqual(len(self.rows), len(self.ids), "ID duplicado")
        self.assertEqual(len(self.rows), len([line for line in self.doc.splitlines()
                                                if line.startswith("| \x60") and " · \x60" in line]),
                         "Fila malformada o truncada")
        for row in self.rows:
            with self.subTest(id=row["id"]):
                self.assertIn(row["state"], STATES)
                self.assertIn(row["source"], {
                    "ESPECIFICACIONES.md",
                    "docs/gestion-empresarial-integral.md",
                    "docs/modulos-operativos.md",
                })
                self.assertTrue(row["requirement"].strip())
                self.assertGreater(len(row["evidence"]), 18)
                if row["state"] == "DESCARTADO":
                    self.assertRegex(row["evidence"].lower(), r"razón|motivo|descart")
                if row["state"] == "CUBIERTO":
                    self.assertRegex(row["evidence"], r"PR #\d+ MERGED")
                    self.assertRegex(row["evidence"], r"(?:src/|tests/|frontend/)")
                if row["state"] == "NO_VERIFICABLE":
                    self.assertRegex(row["evidence"].lower(), r"falta|pendiente|sin evidencia|requiere")

    def test_resumen_por_estado(self) -> None:
        counts = collections.Counter(row["state"] for row in self.rows)
        for state in sorted(STATES):
            self.assertIn(f"| {state} | {counts[state]} |", self.doc)
        self.assertIn(f"| **TOTAL** | **{len(self.rows)}** |", self.doc)
        pct = 100 * counts["CUBIERTO"] / len(self.rows)
        self.assertIn(f"**Porcentaje \x60CUBIERTO\x60: {pct:.1f} %**", self.doc)


if __name__ == "__main__":
    unittest.main()
