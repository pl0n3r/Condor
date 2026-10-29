#!/usr/bin/env python3
"""Condor #1225: documentary coverage contracts with synthetic negative probes.

BRECHA_DOCUMENTADA is not implementation, legal approval, live access or CI.
"""
from __future__ import annotations
import ast
from pathlib import Path
import re
import unittest

ROOT = Path(__file__).resolve().parents[1]
MATRIX = "docs/matriz-cobertura.md"
NORM = "docs/gestion-empresarial-integral.md"
OPS = "docs/modulos-operativos.md"
UNKNOWN = "UNKNOWN"
GAP = "BRECHA_DOCUMENTADA"
CLAUSES = {
    "GEST-NORM-04": (NORM, "Permisos y seguridad",
        "regresión de denegación cross-tenant/entidad/sede y prueba de privilegio mínimo",
        "## Permisos y seguridad",
        ("autorización antes de leer y escribir", "prohibición de enumeración cross-tenant",
         "pruebas negativas de permisos"), "#802"),
    "GEST-NORM-05": (NORM, "Referencias normativas colombianas y método de actualización",
        "evidencia de ejecución sobre SHA exacto, resultado terminal y prueba de fail-closed",
        "## Referencias normativas colombianas y método de actualización",
        ("fuentes oficiales", "artículo, emisor, versión, fecha de verificación",
         "responsable de revisión", "nunca se modela como universal"), "#802"),
    "GEST-NORM-06": (NORM, "Criterios de aceptación transversales",
        "evidencia de ejecución sobre SHA exacto, resultado terminal y prueba de fail-closed",
        "## Criterios de aceptación transversales",
        ("REQUIERE_REVISION", "no modifica por sí misma el estado a",
         "ninguna lectura, exportación, alerta o enlace"), "#802"),
    "GEST-NORM-07": (NORM, "Trazabilidad",
        "aserción de aceptación que ejercite el comportamiento exacto, incluidas sus entradas de error y permisos",
        "## Trazabilidad",
        ("Roadmap único", "Épico legal", "Bloqueo de datos reales", "decisiones.yml"),
        "#802"),
    "OPS-M01-PENDIENTE": (OPS,
        "confirmar cada política comercial/mayorista y su interfaz con los criterios del negocio antes de venderla como función definitiva.",
        "evidencia de ejecución sobre SHA exacto, resultado terminal y prueba de fail-closed",
        "### 1. Catálogo y precios",
        ("**En construcción:** confirmar cada política comercial/mayorista",
         "**Empezar por:** crear un catálogo pequeño", "datos sintéticos"),
        "#1"),
}

def sources():
    return {p: (ROOT / p).read_text(encoding="utf-8")
            for p in (MATRIX, NORM, OPS)}

def matrix_rows(raw):
    if type(raw) is not str:
        raise ValueError(UNKNOWN)
    found = {}
    for key, (path, title, evidence, _heading, _parts, owner) in CLAUSES.items():
        prefix = "| " + chr(96) + key + chr(96) + " · "
        matches = [line for line in raw.splitlines() if line.startswith(prefix)]
        if len(matches) != 1:
            raise ValueError(f"{UNKNOWN}: missing/duplicate {key}")
        cells = tuple(x.strip() for x in matches[0].strip("|").split("|"))
        if (len(cells) != 5
                or cells[0] != chr(96) + key + chr(96) + " · " + chr(96) + path + chr(96)
                or cells[1] != title or cells[2] != chr(96) + "NO_VERIFICABLE" + chr(96)
                or f"Falta vincular para {key} (" not in cells[3]
                or evidence not in cells[3]
                or "no se identificó tríada código+prueba+PR fusionado" not in cells[3]
                or cells[4] != owner):
            raise ValueError(f"{UNKNOWN}: damaged {key}")
        found[key] = cells
    return found

def scoped_text(source, heading):
    if not isinstance(source, str):
        return None
    matches = list(re.finditer(r"(?m)^" + re.escape(heading) + r"[ \t]*$", source))
    if len(matches) != 1:
        return None
    tail = source[matches[0].end():]
    end = re.search(r"(?m)^#{2,3}\s", tail)
    return tail[:end.start()] if end else tail

def assess(key, docs):
    if key not in CLAUSES or not isinstance(docs, dict):
        return UNKNOWN
    if any(not isinstance(docs.get(p), str) or not docs[p]
           for p in (MATRIX, NORM, OPS)):
        return UNKNOWN
    try:
        matrix_rows(docs[MATRIX])
    except ValueError:
        return UNKNOWN
    path, _title, _evidence, heading, parts, _owner = CLAUSES[key]
    text = scoped_text(docs[path], heading)
    if text is None or any(x.casefold() not in text.casefold() for x in parts):
        return UNKNOWN
    return GAP

def synthetic_scope(ctx):
    """Demonstrate denial only; no real authorization code is executed."""
    names = ("tenant", "entity", "site",
             "actor_tenant", "actor_entity", "actor_site", "role")
    if not isinstance(ctx, dict) or any(
        not isinstance(ctx.get(n), str) or not ctx[n] for n in names
    ):
        return UNKNOWN
    if (ctx["tenant"] != ctx["actor_tenant"]
            or ctx["entity"] != ctx["actor_entity"]
            or ctx["site"] != ctx["actor_site"]
            or ctx["role"] != "compliance_manager"):
        return "DENY"
    return "SYNTHETIC_ONLY"

def synthetic_normative(fields):
    required = ("source", "article", "issuer", "version", "checked_at",
                "validity", "condition", "reviewer")
    if not isinstance(fields, dict) or any(
        not isinstance(fields.get(k), str) or not fields[k].strip()
        for k in required
    ):
        return UNKNOWN
    return "REQUIERE_REVISION"

def synthetic_audit(event, actor):
    """An isolated negative audit model; not the product audit service."""
    fields = ("tenant", "entity", "site", "action", "reason")
    if (not isinstance(event, dict) or not isinstance(actor, dict)
            or any(not isinstance(event.get(k), str) or not event[k] for k in fields)
            or any(not isinstance(actor.get(k), str) or not actor[k]
                   for k in ("tenant", "entity", "site", "role"))):
        return UNKNOWN
    if (any(event[k] != actor[k] for k in ("tenant", "entity", "site"))
            or actor["role"] != "auditor"):
        return "DENY"
    return "SYNTHETIC_TRACE_ONLY"

def synthetic_evidence(uploaded, reviewer_authorized):
    """Uploading evidence never grants compliance without a real review."""
    if type(uploaded) is not bool or type(reviewer_authorized) is not bool:
        return UNKNOWN
    return "DENY" if not reviewer_authorized else "SYNTHETIC_PENDING_REVIEW"

def operational_state(_key, _evidence=None):
    """Neither a green CI check nor a synthetic approval certifies real use."""
    return UNKNOWN

class BrechasCondor30Tests(unittest.TestCase):
    def test_contrato_principal(self):
        docs = sources()
        self.assertEqual(5, len(CLAUSES))
        self.assertEqual(set(CLAUSES), set(matrix_rows(docs[MATRIX])))
        for key in CLAUSES:
            with self.subTest(key=key):
                self.assertEqual(GAP, assess(key, docs))
        ctx = dict(tenant="synthetic-a", entity="synthetic-e", site="synthetic-s",
                   actor_tenant="synthetic-a", actor_entity="synthetic-e",
                   actor_site="synthetic-s", role="compliance_manager")
        self.assertEqual("SYNTHETIC_ONLY", synthetic_scope(ctx))
        for field in ("actor_tenant", "actor_entity", "actor_site", "role"):
            self.assertEqual("DENY", synthetic_scope(
                {**ctx, field: "synthetic-foreign"}))
        norm = {k: "synthetic-value" for k in
                ("source", "article", "issuer", "version", "checked_at",
                 "validity", "condition", "reviewer")}
        self.assertEqual("REQUIERE_REVISION", synthetic_normative(norm))
        event = {"tenant": "synthetic-a", "entity": "synthetic-e",
                 "site": "synthetic-s", "action": "review", "reason": "synthetic"}
        auditor = {"tenant": "synthetic-a", "entity": "synthetic-e",
                   "site": "synthetic-s", "role": "auditor"}
        self.assertEqual("SYNTHETIC_TRACE_ONLY", synthetic_audit(event, auditor))
        self.assertEqual("DENY", synthetic_audit(
            event, {**auditor, "entity": "synthetic-foreign"}))
        self.assertEqual("DENY", synthetic_audit(
            event, {**auditor, "role": "tenant_owner"}))
        self.assertEqual("SYNTHETIC_PENDING_REVIEW",
                         synthetic_evidence(True, True))
        self.assertNotEqual("CUMPLE", synthetic_evidence(True, True))

    def test_falla_cerrado(self):
        docs = sources()
        for key in CLAUSES:
            row = next(x for x in docs[MATRIX].splitlines() if x.startswith(
                "| " + chr(96) + key + chr(96) + " · "))
            for bad in (docs[MATRIX].replace(row, "", 1),
                        docs[MATRIX] + "\n" + row,
                        docs[MATRIX].replace(row, row.replace(
                            "NO_VERIFICABLE", "CUBIERTO", 1), 1)):
                with self.subTest(key=key):
                    with self.assertRaisesRegex(ValueError, UNKNOWN):
                        matrix_rows(bad)
                    self.assertEqual(UNKNOWN, assess(key, {**docs, MATRIX: bad}))
            path, _, _, _, fragments, _ = CLAUSES[key]
            self.assertEqual(UNKNOWN, assess(key, {**docs, path:
                docs[path].replace(fragments[0], "", 1)}))
        self.assertEqual(UNKNOWN, assess("MISSING", docs))
        self.assertEqual(UNKNOWN, assess("GEST-NORM-04", {}))
        self.assertEqual(UNKNOWN, synthetic_scope({}))
        self.assertEqual(UNKNOWN, synthetic_normative({}))
        self.assertEqual(UNKNOWN, synthetic_audit(None, {}))
        self.assertEqual(UNKNOWN, synthetic_audit({"tenant": "t"}, {}))
        self.assertEqual("DENY", synthetic_evidence(True, False))
        self.assertEqual(UNKNOWN, synthetic_evidence(None, True))

    def test_sin_datos_reales(self):
        tree = ast.parse(Path(__file__).read_text(encoding="utf-8"))
        imports = {alias.name.split(".")[0] for node in ast.walk(tree)
                   if isinstance(node, ast.Import) for alias in node.names}
        imports |= {node.module.split(".")[0] for node in ast.walk(tree)
                    if isinstance(node, ast.ImportFrom) and node.module}
        self.assertFalse(imports & {"requests", "urllib", "socket", "httpx",
                                    "subprocess", "pymysql", "mysql", "playwright"})
        self.assertEqual({MATRIX, NORM, OPS}, set(sources()))
        self.assertEqual(UNKNOWN, operational_state(
            "OPS-M01-PENDIENTE", {"human_approval": True}))

    def test_estados_unknown_explicitos(self):
        docs = sources()
        for key in CLAUSES:
            self.assertEqual(GAP, assess(key, docs))
            for alleged in (None, {}, True, {"sha": "a" * 40, "ci": "success"},
                            {"state": "VALIDATED_IN_PRODUCTION"},
                            {"legal_review": "APPROVED"},
                            {"commercial_policy": "APPROVED"},
                            {"evidence": "uploaded", "status": "CUMPLE"}):
                with self.subTest(key=key, claim=str(alleged)):
                    self.assertEqual(UNKNOWN, operational_state(key, alleged))
        self.assertEqual(UNKNOWN, synthetic_normative(
            {"source": "synthetic-only"}))

if __name__ == "__main__":
    unittest.main()
