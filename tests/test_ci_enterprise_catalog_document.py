#!/usr/bin/env python3
"""Acceptance AC-01..03 for the offline enterprise catalog in Condor #975.

The documentation is a product proposal, never legal approval or runtime readiness.
"""

from __future__ import annotations

import re
import unittest
from pathlib import Path


ROOT = Path(__file__).resolve().parents[1]
CATALOG = ROOT / "docs" / "gestion-empresarial-integral.md"
MODULE_ROW = re.compile(r"^\|\s*(\d{1,2})\s*\|\s*([^|\n]+?)\s*\|\s*([^|\n]+)\|\s*([^|\n]+)\|\s*([^|\n]+)\|$", re.MULTILINE)


class EnterpriseCatalogDocumentTests(unittest.TestCase):
    @classmethod
    def setUpClass(cls) -> None:
        cls.document = CATALOG.read_text(encoding="utf-8")

    def test_sixteen_modules_have_unique_catalog_rows(self) -> None:
        doc = self.document
        self.assertIn("## Catálogo de módulos", doc)
        self.assertIn("| # | Módulo | Submódulos y resultado |", doc)
        self.assertIn("| --- | --- | --- | --- | --- |", doc)
        rows = MODULE_ROW.findall(doc)
        self.assertEqual(16, len(rows), "El catálogo debe listar exactamente 16 módulos")
        numbers = [int(row[0]) for row in rows]
        names = [row[1].strip().casefold() for row in rows]
        self.assertEqual(list(range(1, 17)), numbers, "Numeración continua, sin duplicados")
        self.assertEqual(16, len(set(names)), "Los módulos deben ser únicos")
        for number, name, detail, priority, limitation in rows:
            with self.subTest(number=number, name=name):
                self.assertTrue(name.strip())
                self.assertTrue(detail.strip())
                self.assertIn(priority.strip(), {"Crítica", "Alta", "Media"})
                self.assertTrue(limitation.strip())
        self.assertIn("fixtures sintéticos", doc)

    def test_catalog_disclaims_legal_authority_and_live_activation(self) -> None:
        doc = self.document
        for required in (
            "no es un segundo roadmap, una certificación legal ni una autorización de activar datos reales",
            "construccion",
            "fixtures sintéticos",
            "No se guardan historias clínicas ocupacionales en el expediente administrativo",
            "en un compartimento separado",
            "revisión de profesional competente",
            "no acredita producción",
            "Los flujos RR. HH. reales permanecen inaccesibles",
        ):
            with self.subTest(guard=required):
                self.assertIn(required, doc)
        self.assertIn("siempre con explicación, fuente, vigencia y condición", doc)
        self.assertIn("No se crean cifras, fechas, tarifas, plazos ni obligaciones universales", doc)

    def test_catalog_points_to_canonical_roadmap_and_dependencies(self) -> None:
        doc = self.document
        for required in (
            "roadmap canónico #1",
            "https://github.com/pl0n3r/Condor/issues/1",
            "https://github.com/pl0n3r/Condor/issues/802",
            "https://github.com/pl0n3r/Condor/issues/742",
            "D-059",
            "D-063",
            "no es un segundo roadmap",
            "no debe crear una segunda cola frente a #1",
        ):
            with self.subTest(reference=required):
                self.assertIn(required, doc)
        self.assertEqual(1, doc.count("## Catálogo de módulos"))
        self.assertIn("Cada ítem requiere contrato de aceptación propio", doc)


if __name__ == "__main__":
    unittest.main()
