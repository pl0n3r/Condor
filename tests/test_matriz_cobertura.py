#!/usr/bin/env python3
"""Condor #989: matriz trazable y evidencia de aceptación conservadora."""
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
README = ROOT / "README.md"
AGENTS = ROOT / "AGENTES.md"
STATES = {"CUBIERTO", "EN_CURSO", "SIN_COBERTURA", "DESCARTADO", "NO_VERIFICABLE"}
SOURCES = {"ESPECIFICACIONES.md", "docs/gestion-empresarial-integral.md",
           "docs/modulos-operativos.md", "README.md", "AGENTES.md"}
ROWS = re.compile(r"^\| \x60([^|]+)\x60 · \x60([^|]+)\x60 \| (.*?) \| \x60([A-Z_]+)\x60 \| (.*?) \| (.*?) \|$", re.MULTILINE)
EXECUTABLE_OPEN_LEAVES = frozenset()  # corte actual: #725/PR #977 cerrados y fusionados
PR_LINK = re.compile(r"\[PR #(\d+) MERGED\]\(https://github\.com/pl0n3r/Condor/pull/\1\)")


def split_modules(value: str) -> list[str]:
    text = value.strip().replace("; ", ", ")
    text = re.sub(r"\s+y\s+(?=[^,]+$)", ", ", text)
    return [part.strip() for part in text.split(",") if part.strip()]


def valid_in_progress(issue: str, evidence: str, open_leaves=EXECUTABLE_OPEN_LEAVES) -> bool:
    if not re.fullmatch(r"#\d+", issue):
        return False
    number = int(issue[1:])
    return (number in open_leaves
            and "factory-plan-task" in evidence
            and bool(re.search(r"PR #\d+ ABIERTO", evidence)))


class MatrizCoberturaTests(unittest.TestCase):
    @classmethod
    def setUpClass(cls):
        cls.doc = MATRIX.read_text(encoding="utf-8")
        cls.rows = [dict(id=a, source=b, requirement=c, state=d, evidence=e, issue=f)
                    for a, b, c, d, e, f in ROWS.findall(cls.doc)]
        cls.ids = {r["id"] for r in cls.rows}

    def test_cubre_todo_el_alcance(self):
        spec = SPEC.read_text(encoding="utf-8")
        enterprise = ENTERPRISE.read_text(encoding="utf-8")
        operations = OPERATIONS.read_text(encoding="utf-8")
        decisions = set(re.findall(r"^### (D-\d{3}) \u2014", spec, re.MULTILINE))
        self.assertEqual({f"D-{n:03d}" for n in range(1, 55)}, decisions)
        self.assertTrue(decisions.issubset(self.ids))
        module_rows = re.findall(r"^\|\s*(\d{1,2})\s*\|([^|]*)\|([^|]*)\|", enterprise, re.MULTILINE)
        self.assertEqual(16, len(module_rows))
        for number, name, submodules in module_rows:
            with self.subTest(module=number):
                prefix = f"GEST-M{int(number):02d}"
                self.assertIn(prefix, self.ids)
                for index, submodule in enumerate(split_modules(submodules), 1):
                    key = f"{prefix}-S{index:02d}"
                    self.assertIn(key, self.ids)
                    row = next(r for r in self.rows if r["id"] == key)
                    self.assertIn(submodule, row["requirement"])
        spec_sections = re.findall(r"(?m)^#### ([^\n]+)$", spec)
        self.assertGreaterEqual(len([i for i in self.ids if re.match(r"D-\d{3}-S\d{2}$", i)]), len(spec_sections))
        limits = re.search(r"## Límites no negociables([\s\S]*?)## Catálogo de módulos", enterprise)
        self.assertIsNotNone(limits)
        for n, _ in enumerate(re.findall(r"(?m)^- ", limits.group(1)), 1):
            self.assertIn(f"GEST-LIM-{n:02d}", self.ids)
        for n in range(1, 6):
            self.assertIn(f"GEST-FLUJO-{n}", self.ids)
        acceptance = re.search(r"## Criterios de aceptación transversales([\s\S]*?)## Trazabilidad", enterprise)
        self.assertIsNotNone(acceptance)
        for n, _ in enumerate(re.findall(r"(?m)^- ", acceptance.group(1)), 1):
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
        for name, file in (("README", README), ("AGENTES", AGENTS)):
            self.assertTrue(file.is_file())
            ids = [row["id"] for row in self.rows if row["id"].startswith(name + "-")]
            self.assertGreaterEqual(len(ids), 5, f"Faltan requisitos de {name}")
            self.assertGreater(len(set(ids)), 4)
            self.assertTrue(all(" — " in r["requirement"] for r in self.rows
                                if r["id"].startswith(name + "-")))
        self.assertGreaterEqual(len(self.rows), 400)

    def test_cubre_todas_las_fuentes(self):
        self.test_cubre_todo_el_alcance()

    def test_sin_cobertura_tiene_issue(self):
        for row in self.rows:
            with self.subTest(id=row["id"]):
                if row["state"] == "SIN_COBERTURA":
                    self.assertRegex(row["issue"], r"^#(?:[2-9]|\d{2,})\d*$")
                    self.assertIn("factory-plan-task", row["evidence"])
                if row["state"] == "EN_CURSO":
                    self.assertTrue(valid_in_progress(row["issue"], row["evidence"]),
                                    f"No usar épico/roadmap como EN_CURSO: {row['id']}")

    def test_formato_y_estados(self):
        self.assertIn("| ID / fuente | Requisito resumido | Estado | Evidencia y límite de verificación | Issue |", self.doc)
        self.assertEqual(len(self.rows), len(self.ids), "IDs duplicados")
        self.assertEqual(len(self.rows), len([line for line in self.doc.splitlines()
                            if line.startswith("| \x60") and " · \x60" in line]))
        for row in self.rows:
            with self.subTest(id=row["id"]):
                self.assertIn(row["state"], STATES)
                self.assertIn(row["source"], SOURCES)
                self.assertTrue(row["requirement"].strip())
                self.assertGreater(len(row["evidence"]), 30)
                self.assertRegex(row["issue"], r"^#\d+$")
                if row["state"] == "DESCARTADO":
                    self.assertRegex(row["evidence"].lower(), r"razón|motivo|descart")
                elif row["state"] == "CUBIERTO":
                    self.assertRegex(row["evidence"], PR_LINK)
                    self.assertRegex(row["evidence"], r"(?:src/|frontend/)")
                    self.assertRegex(row["evidence"], r"tests/(?:php/|Unit/|test_)")
                elif row["state"] == "NO_VERIFICABLE":
                    self.assertRegex(row["evidence"].lower(), r"falta|pendiente|sin evidencia|requiere")
                    self.assertIn(row["id"], row["evidence"])
                    self.assertNotIn("Falta comprobación integral", row["evidence"])

    def test_en_curso_rechaza_epicos_y_roadmaps(self):
        for issue in ("#1", "#802", "#963", "#989", "#978", "#742"):
            with self.subTest(issue=issue):
                self.assertFalse(valid_in_progress(issue, "factory-plan-task PR #999 ABIERTO"))
        self.assertFalse(valid_in_progress("#725", "PR #977 ABIERTO"))
        self.assertFalse(valid_in_progress("#725", "factory-plan-task PR #977 MERGED"))
        self.assertFalse(valid_in_progress("#725", "factory-plan-task PR #977 ABIERTO"))
        # Solo una hoja realmente abierta sería EN_CURSO; fixture positivo puro.
        self.assertTrue(valid_in_progress("#725", "factory-plan-task PR #977 ABIERTO",
                                          open_leaves=frozenset({725})))

    def test_evidencia_cubierta_con_archivo_pr_y_prueba(self):
        covers = [r for r in self.rows if r["state"] == "CUBIERTO"]
        self.assertGreaterEqual(len(covers), 20)
        for row in covers:
            with self.subTest(id=row["id"]):
                self.assertTrue(PR_LINK.search(row["evidence"]))
                paths = re.findall(r"(?:src|frontend|tests)/[A-Za-z0-9_./-]+\.(?:php|py|tsx|js)", row["evidence"])
                self.assertGreaterEqual(len(paths), 2)
                for p in paths:
                    self.assertTrue((ROOT / p).is_file(), p)

    def test_resumen_final(self):
        counts = collections.Counter(row["state"] for row in self.rows)
        for state in sorted(STATES):
            self.assertIn(f"| {state} | {counts[state]} |", self.doc)
        self.assertIn(f"| **TOTAL** | **{len(self.rows)}** |", self.doc)
        pct = 100 * counts["CUBIERTO"] / len(self.rows)
        self.assertIn(f"**Porcentaje \x60CUBIERTO\x60: {pct:.1f} %**", self.doc)
        self.assertRegex(self.doc, r"Hojas nuevas creadas: \d+")
        self.assertIn("08a08bf8-b700-4427-97f6-a0836313c7d7", self.doc)

    def test_resumen_por_estado(self):
        self.test_resumen_final()


if __name__ == "__main__":
    unittest.main()
