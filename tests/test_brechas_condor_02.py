#!/usr/bin/env python3
"""Condor #1197: evidencia offline de cinco brechas AGENTES.

LOCAL_PARTIAL significa una regresión acotada, no cobertura integral de
Factory, migraciones productivas ni del gobierno transversal.
"""
from __future__ import annotations

import json
import re
import tempfile
import unittest
from pathlib import Path
from unittest.mock import patch

from scripts.ci_change_classifier import classify
from scripts import validar_documentacion as docs

ROOT = Path(__file__).resolve().parents[1]
MATRIX = ROOT / "docs/matriz-cobertura.md"
UNKNOWN = "UNKNOWN"
LOCAL_PARTIAL = "LOCAL_PARTIAL"
TICK = chr(96)
GAPS = {
    "AGENTES-04-03": "4. Arquitectura y producto — relaciones de negocio como datos estructurados;",
    "AGENTES-04-06": "4. Arquitectura y producto — migraciones explícitas, expand-compatible y seguras ante despliegues parciales;",
    "AGENTES-05-01": "5. CI: Factory común + extensión Condor — política de decisiones/revisiones;",
    "AGENTES-05-02": "5. CI: Factory común + extensión Condor — clasificador selectivo de cambios;",
    "AGENTES-05-03": "5. CI: Factory común + extensión Condor — validación documental y gobierno local;",
}


def coverage_rows(document: str) -> dict[str, tuple[str, ...]]:
    """Una fila ausente, repetida o que invente CUBIERTO falla cerrada."""
    rows: dict[str, tuple[str, ...]] = {}
    for line in document.splitlines():
        match = re.match(r"^\|\s*\x60(AGENTES-(?:04-0[36]|05-0[123]))\x60\s*·", line)
        if not match:
            continue
        key = match.group(1)
        cells = tuple(value.strip() for value in line.strip().strip("|").split("|"))
        if (key in rows or len(cells) != 5 or not all(cells)
                or cells[0] != f"{TICK}{key}{TICK} · {TICK}AGENTES.md{TICK}"
                or cells[1] != GAPS[key]
                or cells[2] != f"{TICK}NO_VERIFICABLE{TICK}"
                or "Falta evidencia integral" not in cells[3]
                or key not in cells[3]):
            raise ValueError("UNKNOWN: fila inconsistente " + key)
        rows[key] = cells
    if set(rows) != set(GAPS):
        raise ValueError("UNKNOWN: faltan filas AGENTES")
    return rows


def structured_relationship(entities: object, links: object) -> str:
    """Contrato sintético de claves y tenancy, no integración end-to-end."""
    if not isinstance(entities, list) or not isinstance(links, list) or not links:
        return UNKNOWN
    index: dict[str, str] = {}
    for entity in entities:
        if not isinstance(entity, dict) or set(entity) != {"id", "tenant"}:
            return UNKNOWN
        key, tenant = entity["id"], entity["tenant"]
        if (not isinstance(key, str) or not key
                or not isinstance(tenant, str) or not tenant or key in index):
            return UNKNOWN
        index[key] = tenant
    seen: set[tuple[str, str]] = set()
    for link in links:
        if not isinstance(link, dict) or set(link) != {"from", "to"}:
            return UNKNOWN
        start, end = link["from"], link["to"]
        if (not isinstance(start, str) or not isinstance(end, str)
                or start not in index or end not in index
                or start == end or index[start] != index[end]
                or (start, end) in seen):
            return UNKNOWN
        seen.add((start, end))
    return LOCAL_PARTIAL


def partial_deploy(before: object, after: object) -> str:
    """Aditivo: conservar columnas existentes y agregar solo opcionales."""
    if not isinstance(before, dict) or not isinstance(after, dict) or not before:
        return UNKNOWN
    if not all(isinstance(key, str) and key and isinstance(kind, str) and kind
               for key, kind in (*before.items(), *after.items())):
        return UNKNOWN
    if any(after.get(key) != kind for key, kind in before.items()):
        return UNKNOWN
    added = set(after) - set(before)
    if not added or any(not after[key].endswith("?") for key in added):
        return UNKNOWN
    return LOCAL_PARTIAL


def local_decision_policy(raw: object, agents: object) -> str:
    """Revisa el contrato local, pero no atestigua revisiones de Factory."""
    if not isinstance(raw, str) or not isinstance(agents, str):
        return UNKNOWN
    try:
        data = json.loads(raw)
    except (TypeError, ValueError):
        return UNKNOWN
    if not isinstance(data, dict) or data.get("version") != 1:
        return UNKNOWN
    rounds, decisions = data.get("review_round_limit"), data.get("decisions")
    if (type(rounds) is not int or rounds < 1 or not isinstance(decisions, list)
            or not decisions or "decisiones.yml" not in agents
            or "política de decisiones/revisiones" not in agents):
        return UNKNOWN
    ids: set[str] = set()
    for decision in decisions:
        if not isinstance(decision, dict) or set(decision) != {"id", "status", "text"}:
            return UNKNOWN
        key = decision["id"]
        if (not isinstance(key, str) or not re.fullmatch(r"D-\d{3}", key)
                or key in ids or decision["status"] != "active"
                or not isinstance(decision["text"], str)
                or not decision["text"].strip()):
            return UNKNOWN
        ids.add(key)
    return LOCAL_PARTIAL


def integral_coverage(clause: object, evidence: object = None) -> str:
    """Nunca se transforma evidencia parcial/auto-declarada en éxito total."""
    return UNKNOWN


class BrechasCondor02Tests(unittest.TestCase):
    @classmethod
    def setUpClass(cls) -> None:
        cls.document = MATRIX.read_text(encoding="utf-8")
        cls.rows = coverage_rows(cls.document)
        cls.agents = (ROOT / "AGENTES.md").read_text(encoding="utf-8")
        cls.decisions = (ROOT / "decisiones.yml").read_text(encoding="utf-8")

    def test_contrato_principal(self) -> None:
        """AC-01: cinco filas, relaciones, migraciones, CI y documentos."""
        self.assertEqual(set(self.rows), set(GAPS))
        for key, title in GAPS.items():
            with self.subTest(gap=key):
                self.assertEqual(self.rows[key][1], title)
                self.assertEqual(self.rows[key][2], f"{TICK}NO_VERIFICABLE{TICK}")
                self.assertEqual(integral_coverage(key), UNKNOWN)
        self.assertEqual(structured_relationship(
            [{"id": "synthetic:a", "tenant": "synthetic:t"},
             {"id": "synthetic:b", "tenant": "synthetic:t"}],
            [{"from": "synthetic:a", "to": "synthetic:b"}]), LOCAL_PARTIAL)
        self.assertEqual(partial_deploy({"id": "int"},
                                        {"id": "int", "note": "text?"}), LOCAL_PARTIAL)
        self.assertEqual(local_decision_policy(self.decisions, self.agents),
                         LOCAL_PARTIAL)
        # Regresión del clasificador REAL, no una réplica de su algoritmo.
        for path, category, full in (
            ("README.md", "categoria_documentacion", False),
            (".github/labels.json", "categoria_gobierno", False),
            ("tests/test_brechas_condor_02.py", None, False),
            ("frontend/admin/App.tsx", "categoria_frontend", True),
            ("src/Application/PaymentService.php", "categoria_backend", True),
            ("migrations/VersionSynthetic.php", "categoria_migraciones", True),
            ("composer.lock", "categoria_dependencias", True),
            ("config/packages/security.yaml", "categoria_seguridad", True),
            ("config/version.php", "categoria_release", True),
            (".github/workflows/ci.yml", "categoria_gobierno", True),
        ):
            with self.subTest(path=path):
                result = classify([path], "pull_request")
                self.assertEqual(result.validacion_completa, full)
                if category:
                    self.assertTrue(getattr(result, category))
        with tempfile.TemporaryDirectory(prefix="synthetic-docs-") as folder:
            root = Path(folder)
            for name in docs.REQUERIDOS:
                (root / name).write_text("# synthetic\n", encoding="utf-8")
            with patch.object(docs, "RAIZ", root):
                errors: list[str] = []
                docs.validar_archivos_requeridos(errors)
                docs.validar_enlaces_relativos(errors)
                self.assertEqual(errors, [])

    def test_falla_cerrado(self) -> None:
        """AC-02: faltantes, duplicados, tenancy y cambios no compatibles."""
        row = next(line for line in self.document.splitlines()
                   if line.startswith("| " + TICK + "AGENTES-04-03" + TICK))
        for corrupted in (self.document.replace(row, ""),
                          self.document + "\n" + row,
                          self.document.replace(row, row.replace(
                              "NO_VERIFICABLE", "CUBIERTO"))):
            with self.assertRaisesRegex(ValueError, UNKNOWN):
                coverage_rows(corrupted)
        entities = [{"id": "synthetic:a", "tenant": "one"},
                    {"id": "synthetic:b", "tenant": "two"}]
        for links in ([], [{"from": "synthetic:a", "to": "missing"}],
                      [{"from": "synthetic:a", "to": "synthetic:b"}],
                      [{"from": "synthetic:a", "to": "synthetic:a"}]):
            self.assertEqual(structured_relationship(entities, links), UNKNOWN)
        self.assertEqual(structured_relationship(
            entities + [entities[0]], [{"from": "synthetic:a", "to": "synthetic:b"}]), UNKNOWN)
        for after in ({"id": "text"}, {}, {"id": "int", "required": "text"},
                      {"note": "text?"}, {"id": "int", "note": None}):
            self.assertEqual(partial_deploy({"id": "int"}, after), UNKNOWN)
        self.assertEqual(local_decision_policy("{invalid", self.agents), UNKNOWN)
        self.assertEqual(local_decision_policy("{}", self.agents), UNKNOWN)
        self.assertTrue(classify([], "pull_request").validacion_completa)
        self.assertTrue(classify(["unexpected.conf"], "pull_request").validacion_completa)
        with tempfile.TemporaryDirectory(prefix="synthetic-docs-") as folder:
            root = Path(folder)
            (root / "README.md").write_text("[synthetic](missing.md)", encoding="utf-8")
            with patch.object(docs, "RAIZ", root):
                errors: list[str] = []
                docs.validar_archivos_requeridos(errors)
                docs.validar_enlaces_relativos(errors)
                self.assertTrue(any("Falta el archivo canónico" in e for e in errors))
                self.assertTrue(any("enlace relativo roto" in e for e in errors))

    def test_sin_datos_reales(self) -> None:
        """AC-03: fixtures sintéticos, sin red, secretos ni clientes reales."""
        self.assertTrue(MATRIX.is_file())
        fixture = {"id": "synthetic:a", "tenant": "synthetic:t"}
        self.assertTrue(all(value.startswith("synthetic:") for value in fixture.values()))
        self.assertEqual(integral_coverage("AGENTES-04-03", fixture), UNKNOWN)

    def test_estados_unknown_explicitos(self) -> None:
        """AC-04: falsos checks verdes nunca sustituyen evidencia integral."""
        for key in GAPS:
            with self.subTest(gap=key):
                self.assertIn(key, self.rows[key][3])
                for claim in (None, "", "success", "CUBIERTO", LOCAL_PARTIAL,
                              {"ci": "success"}, {"review": "approved"},
                              {"live": True}, {"e2e": "passed"}):
                    self.assertEqual(integral_coverage(key, claim), UNKNOWN)
        self.assertEqual(integral_coverage("AGENTES-99-99"), UNKNOWN)
        self.assertEqual(structured_relationship(None, None), UNKNOWN)
        self.assertEqual(partial_deploy(None, None), UNKNOWN)
        self.assertEqual(local_decision_policy(None, None), UNKNOWN)


if __name__ == "__main__":
    unittest.main()
