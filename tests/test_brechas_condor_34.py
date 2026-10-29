#!/usr/bin/env python3
"""Condor #1229: five OPS-REC gaps with local-only evidence and fail-closed UNKNOWN.

A source/test on main never proves real payments, production, legal approval or
the complete business flow; these require independent exact-SHA evidence.
"""
from __future__ import annotations

import ast
from pathlib import Path
import unittest

ROOT = Path(__file__).resolve().parents[1]
MATRIX = "docs/matriz-cobertura.md"
GUIDE = "docs/modulos-operativos.md"
PRICE = "tests/test_effective_price_explainer.py"
CHECKOUT = "tests/test_checkout_http_negatives.py"
TENANT = "tests/test_new_modules_tenant_isolation.py"
MODULES = "tests/test_docs_modulos_operativos.py"
SOURCES = (MATRIX, GUIDE, PRICE, CHECKOUT, TENANT, MODULES)
UNKNOWN = "UNKNOWN"
GAP = "BRECHA_DOCUMENTADA"
TICK = chr(96)

GAPS = {
    "OPS-REC-A-P1": (
        "Iniciar por **Catálogo y precios** con un puñado de variantes.",
        "1. Iniciar por **Catálogo y precios** con un puñado de variantes.",
        "prueba de montos, precedencias, moneda COP y rechazo de transacción inválida",
    ),
    "OPS-REC-A-P2": (
        "Probar **Inventario y sedes**, **Tienda y checkout** y **Pedidos y ventas** en ese orden.",
        "2. Probar **Inventario y sedes**, **Tienda y checkout** y **Pedidos y ventas** en ese orden.",
        "regresión de denegación cross-tenant/entidad/sede y prueba de privilegio mínimo",
    ),
    "OPS-REC-A-P3": (
        "Comprobar que el precio y el stock son recalculados/validados por el servidor; la aceptación de pagos y la salida a producción siguen **en construcción**.",
        "3. Comprobar que el precio y el stock son recalculados/validados por el servidor; la aceptación de pagos y la salida a producción siguen **en construcción**.",
        "prueba de montos, precedencias, moneda COP y rechazo de transacción inválida",
    ),
    "OPS-REC-A-P4": (
        "Usar **Clientes y categorías** solo cuando el flujo comercial lo requiera.",
        "4. Usar **Clientes y categorías** solo cuando el flujo comercial lo requiera.",
        "evidencia de ejecución sobre SHA exacto, resultado terminal y prueba de fail-closed",
    ),
    "OPS-REC-B": (
        "Recorrido B (preparación, no producción)",
        "### Recorrido B — Taller",
        "evidencia de ejecución sobre SHA exacto, resultado terminal y prueba de fail-closed",
    ),
}
LOCAL = {
    "OPS-REC-A-P1": ((PRICE, "selected_rule_id"), (PRICE, "unit_amount_minor"), (CHECKOUT, "'COP'")),
    "OPS-REC-A-P2": ((CHECKOUT, "other_tenant_variant"), (TENANT, "testPurchasingRejectsForeignSupplierAndPurchaseAssociations")),
    "OPS-REC-A-P3": ((CHECKOUT, "client_total"), (CHECKOUT, "client_unit_price"), (CHECKOUT, "tenant_a_server_total"), (PRICE, "Overflow monetario")),
    "OPS-REC-A-P4": ((MODULES, "Clientes y categorías"), (MODULES, "scenario_")),
    "OPS-REC-B": ((MODULES, "scenario_"), (TENANT, "testProductionCostRejectsForeignOrderAndMaterialBeforeRead")),
}
B_STEPS = (
    "1. Configurar **Catálogo y precios** e **Inventario y sedes** para los materiales.",
    "2. Registrar BOM y órdenes de prueba en **Producción y materiales**.",
    "3. Evaluar **Compras y proveedores** para la reposición.",
    "4. El costo por orden integrado en pantalla sigue **en construcción**, y no se debe prometer un ERP contable.",
)


def sources() -> dict[str, str]:
    """Read local tracked files only: no credentials, HTTP, DB or subprocess."""
    return {p: (ROOT / p).read_text(encoding="utf-8") for p in SOURCES}


def matrix_rows(raw: object) -> dict[str, tuple[str, ...]]:
    if type(raw) is not str:
        raise ValueError("UNKNOWN: matriz ilegible")
    found: dict[str, tuple[str, ...]] = {}
    for key, (title, _line, obligation) in GAPS.items():
        prefix = f"| {TICK}{key}{TICK} ·"
        matches = [line for line in raw.splitlines() if line.startswith(prefix)]
        if len(matches) != 1:
            raise ValueError("UNKNOWN: fila ausente/duplicada " + key)
        cells = tuple(x.strip() for x in matches[0].strip("|").split("|"))
        if (len(cells) != 5
                or cells[0] != f"{TICK}{key}{TICK} · {TICK}{GUIDE}{TICK}"
                or cells[1] != title
                or cells[2] != f"{TICK}NO_VERIFICABLE{TICK}"
                or f"Falta vincular para {key} " not in cells[3]
                or obligation not in cells[3]
                or "no se identificó tríada código+prueba+PR fusionado" not in cells[3]
                or "no constituye aceptación ni go-live" not in cells[3]
                or cells[4] != "#1"):
            raise ValueError("UNKNOWN: fila inválida " + key)
        found[key] = cells
    return found


def section(raw: object, heading: str) -> str:
    if type(raw) is not str:
        return ""
    lines = raw.splitlines()
    if lines.count(heading) != 1:
        return ""
    start = lines.index(heading)
    end = next((i for i in range(start + 1, len(lines))
                if lines[i].startswith("### ") or lines[i].startswith("## ")),
               len(lines))
    return "\n".join(lines[start:end])


def documented_route(raw: object, key: object) -> bool:
    if type(key) is not str or key not in GAPS or type(raw) is not str:
        return False
    if ("**Estado general: construcción/pre-live.**" not in raw
            or "**no** significa que la pantalla esté terminada" not in raw):
        return False
    a = section(raw, "### Recorrido A — Tienda")
    b = section(raw, "### Recorrido B — Taller")
    if not a or not b:
        return False
    if any(a.splitlines().count(GAPS[k][1]) != 1 for k in GAPS if k.startswith("OPS-REC-A-")):
        return False
    if any(b.splitlines().count(step) != 1 for step in B_STEPS):
        return False
    if key == "OPS-REC-B":
        return "**en construcción**" in b and "ERP contable" in b
    return GAPS[key][1] in a


def classify(key: object, data: object) -> str:
    """At best a documented gap. Local evidence never certifies readiness."""
    if type(key) is not str or key not in GAPS or type(data) is not dict:
        return UNKNOWN
    if set(data) != set(SOURCES):
        return UNKNOWN
    if any(type(data[p]) is not str or not data[p] for p in SOURCES):
        return UNKNOWN
    try:
        matrix_rows(data[MATRIX])
    except ValueError:
        return UNKNOWN
    if not documented_route(data[GUIDE], key):
        return UNKNOWN
    if any(marker not in data[path] for path, marker in LOCAL[key]):
        return UNKNOWN
    return GAP


def operational_state(_key: object, _evidence: object = None) -> str:
    """Simulated input never proves permissions, checkout or go-live."""
    return UNKNOWN


class BrechasCondor34Tests(unittest.TestCase):
    def test_contrato_principal(self) -> None:
        """AC-01: exactly five claims, each bound to a partial test or gap."""
        data = sources()
        self.assertEqual(len(GAPS), 5)
        rows = matrix_rows(data[MATRIX])
        self.assertEqual(set(rows), set(GAPS))
        for key, (_title, _line, obligation) in GAPS.items():
            with self.subTest(gap=key):
                self.assertIn(obligation, rows[key][3])
                self.assertTrue(documented_route(data[GUIDE], key))
                self.assertEqual(classify(key, data), GAP)
                self.assertEqual(operational_state(key, data), UNKNOWN)

    def test_falla_cerrado(self) -> None:
        """AC-02: absence, duplicates, promoted status and altered proof reject."""
        data = sources()
        raw = data[MATRIX]
        for key in GAPS:
            line = next(s for s in raw.splitlines() if s.startswith(f"| {TICK}{key}{TICK} ·"))
            for bad in (
                raw.replace(line, "", 1),
                raw + "\n" + line,
                raw.replace(line, line.replace("NO_VERIFICABLE", "CUBIERTO"), 1),
                raw.replace(line, line.replace(GAPS[key][2], "certificado sin ejecución"), 1),
                raw.replace(line, line.replace("| #1 |", "| #999 |"), 1),
            ):
                with self.subTest(gap=key):
                    with self.assertRaisesRegex(ValueError, "UNKNOWN"):
                        matrix_rows(bad)
                    changed = dict(data)
                    changed[MATRIX] = bad
                    self.assertEqual(classify(key, changed), UNKNOWN)
            altered_doc = dict(data)
            altered_doc[GUIDE] = data[GUIDE].replace(GAPS[key][1], "", 1)
            self.assertEqual(classify(key, altered_doc), UNKNOWN)
            for malformed_guide in (
                data[GUIDE] + "\n### Recorrido B — Taller\n",
                data[GUIDE].replace(
                    "**Estado general: construcción/pre-live.**",
                    "**Estado general: listo para operación.**", 1),
            ):
                tampered = dict(data)
                tampered[GUIDE] = malformed_guide
                self.assertEqual(classify(key, tampered), UNKNOWN)
            path, marker = LOCAL[key][0]
            altered_source = dict(data)
            altered_source[path] = data[path].replace(marker, "")
            self.assertEqual(classify(key, altered_source), UNKNOWN)
        self.assertEqual(classify("OPS-REC-NO-EXISTE", data), UNKNOWN)
        self.assertEqual(classify("OPS-REC-B", {}), UNKNOWN)
        self.assertEqual(classify("OPS-REC-B", None), UNKNOWN)

    def test_sin_datos_reales(self) -> None:
        """AC-03: no network, subprocess, PII fixtures or real customer data."""
        tree = ast.parse((ROOT / "tests/test_brechas_condor_34.py").read_text(encoding="utf-8"))
        imported: set[str] = set()
        for node in ast.walk(tree):
            if isinstance(node, ast.Import):
                imported.update(a.name.split(".")[0] for a in node.names)
            elif isinstance(node, ast.ImportFrom):
                imported.add((node.module or "").split(".")[0])
        self.assertLessEqual(imported, {"__future__", "ast", "pathlib", "unittest"})
        self.assertIn("synthetic", sources()[CHECKOUT].lower())
        self.assertIn("sin red/DB", sources()[PRICE])
        self.assertIn("ninguna BD", sources()[TENANT])
        self.assertEqual(operational_state("OPS-REC-A-P3", {"paid": True}), UNKNOWN)

    def test_estados_unknown_explicitos(self) -> None:
        """AC-04: fake approvals, amounts, SHA or tenants remain UNKNOWN."""
        data = sources()
        claims = (None, {}, {"approved": True}, {"sha": "fake", "result": "success"},
                  {"currency": "COP", "amount_minor": 10000},
                  {"tenant": "fixture-a", "permission": "allow"})
        for key in GAPS:
            with self.subTest(gap=key):
                self.assertEqual(classify(key, data), GAP)
                for claim in claims:
                    self.assertEqual(operational_state(key, claim), UNKNOWN)
        invalid = dict(data)
        invalid[GUIDE] = data[GUIDE].replace("### Recorrido B — Taller", "### Recorrido B — Producción")
        self.assertFalse(documented_route(invalid[GUIDE], "OPS-REC-A-P1"))
        self.assertEqual(classify("OPS-REC-B", invalid), UNKNOWN)


if __name__ == "__main__":
    unittest.main()
