"""Contrato para paquete jurídico transversal de siete repositorios (#1447)."""
from __future__ import annotations
import json
import re
import unittest
from pathlib import Path

ROOT = Path(__file__).resolve().parent.parent
PATH = ROOT / "revision-juridica" / "PAQUETE-ABOGADO.md"
PROJECTS = ("Factory", "Condor", "ControlBot", "GrindFlow", "BRVTAL", "FactoryRunner", "AutoFactory")
COUNTS = {"Condor": 18, "ControlBot": 8, "GrindFlow": 14, "BRVTAL": 16, "FactoryRunner": 1, "AutoFactory": 9}
PLACEHOLDER = "[COMPLETAR POR EL DUEÑO]"


def project_sections(doc):
    markers = list(re.finditer(
        r"^## (Factory|Condor|ControlBot|GrindFlow|BRVTAL|FactoryRunner|AutoFactory)$",
        doc, re.M))
    return {m.group(1): doc[m.end():markers[i + 1].start() if i + 1 < len(markers) else len(doc)]
            for i, m in enumerate(markers)}


class PaqueteAbogadoTests(unittest.TestCase):
    def test_criterio_principal(self):
        doc = PATH.read_text(encoding="utf-8")
        parts = project_sections(doc)
        self.assertEqual(set(parts), set(PROJECTS))
        self.assertEqual(len(re.findall(
            r"^## (Factory|Condor|ControlBot|GrindFlow|BRVTAL|FactoryRunner|AutoFactory)$", doc, re.M)), 7)
        self.assertIn("documented_not_legally_approved", doc)
        self.assertIn("## Datos del responsable pendientes de completar", doc)
        for project in PROJECTS:
            with self.subTest(project=project):
                section = parts[project].split("## Consolidación", 1)[0]
                self.assertIn("**Fuentes para revisión:**", section)
                self.assertIn("**Derechos:**", section)
                self.assertIn("### Preguntas concretas:", section)
                self.assertEqual(len(re.findall(r"^\d+\. \*\*Pregunta:\*\*", section, re.M)), 5)
                self.assertEqual(section.count("**Respuesta del abogado (pendiente):**"), 5)
                self.assertIn("| " + project + " | " + " | ".join([PLACEHOLDER] * 4) + " |", doc)
        for project, expected in COUNTS.items():
            with self.subTest(treatments=project):
                part = parts[project].split("### Preguntas concretas:", 1)[0]
                ids = re.findall(r"^\| ([a-z][a-z_0-9]+) \|", part, re.M)
                self.assertEqual(len(ids), expected)
                self.assertEqual(len(set(ids)), expected)
                self.assertIn("review_required", part)
        local = json.loads((ROOT / "datos.yml").read_text(encoding="utf-8"))
        condor_ids = set(re.findall(r"^\| ([a-z][a-z_0-9]+) \|", parts["Condor"], re.M))
        self.assertEqual(condor_ids, {t["id"] for t in local["treatments"]})

    def test_no_inventa_responsables_aprobaciones_o_fuentes(self):
        doc = PATH.read_text(encoding="utf-8")
        self.assertIn("no asesoría ni aprobación legal", doc)
        self.assertIn("no ejecutar cambios productivos", doc)
        self.assertIn("ninguno declarado — NO VERIFICADO", doc)
        self.assertIn("AutoFactory no dispone de carpeta revision-juridica", doc)
        self.assertIn("No existe inventario datos.yml propio de Factory", doc)
        self.assertEqual(doc.count("| " + PLACEHOLDER), 7)

    def test_documentos_verificables_por_producto(self):
        parts = project_sections(PATH.read_text(encoding="utf-8"))
        for name, repo in (("Condor", "Condor"), ("ControlBot", "ControlBot"),
                           ("GrindFlow", "GrindFlow"), ("BRVTAL", "brvtal"),
                           ("FactoryRunner", "FactoryRunner")):
            with self.subTest(project=name):
                self.assertIn("https://github.com/pl0n3r/" + repo + "/blob/main/revision-juridica/00-LEEME.md", parts[name])
                self.assertIn("https://github.com/pl0n3r/" + repo + "/blob/main/docs/privacidad/canal-derechos.md", parts[name])
        self.assertIn("revision-juridica/legal/plantillas", parts["Factory"])
        self.assertIn("https://github.com/pl0n3r/AutoFactory/blob/main/README.md", parts["AutoFactory"])


if __name__ == "__main__":
    unittest.main()
