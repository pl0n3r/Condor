#!/usr/bin/env python3
"""#1231: offline conservative checks for Condor operational journeys C/D.

Documentation and synthetic fixtures cannot assert live capabilities or E2E acceptance.
"""
from __future__ import annotations

import ast
from decimal import Decimal, InvalidOperation, ROUND_HALF_UP
from pathlib import Path
import re
import unittest

ROOT = Path(__file__).resolve().parents[1]
MATRIX = "docs/matriz-cobertura.md"
GUIDE = "docs/modulos-operativos.md"
CONTRAST = "docs/contraste-cd_ops.md"
UNKNOWN = "UNKNOWN"
DENY = "DENY"
GAP = "BRECHA_DOCUMENTADA"
CLAUSES = {
    "OPS-REC-C-P1": (
        "Definir sedes y permisos; usar **Inventario y sedes** con fuentes por sede.",
        "regresión de denegación cross-tenant/entidad/sede y prueba de privilegio mínimo",
        "### Recorrido C — Distribuidora con sedes"),
    "OPS-REC-C-P2": (
        "Probar **Catálogo y precios**, **Clientes y categorías** y **Pedidos y ventas**.",
        "prueba de montos, precedencias, moneda COP y rechazo de transacción inválida",
        "### Recorrido C — Distribuidora con sedes"),
    "OPS-REC-C-P3": (
        "Añadir pruebas de **Compras y proveedores** y contrastar **Reportes CSV** por tenant.",
        "regresión de denegación cross-tenant/entidad/sede y prueba de privilegio mínimo",
        "### Recorrido C — Distribuidora con sedes"),
    "OPS-REC-C-P4": (
        "Transferencias automáticas intersede, alertas y escaneo UI están **en construcción** hasta que sus hojas estén integradas.",
        "regresión de denegación cross-tenant/entidad/sede y prueba de privilegio mínimo",
        "### Recorrido C — Distribuidora con sedes"),
    "OPS-REC-D": (
        "Recorrido D (preparación, no producción)",
        "evidencia de ejecución sobre SHA exacto, resultado terminal y prueba de fail-closed",
        "### Recorrido D — Grupo de empresas"),
}


def read_contracts():
    return {p: (ROOT / p).read_text(encoding="utf-8")
            for p in (MATRIX, GUIDE, CONTRAST)}


def matrix_rows(raw):
    """Five exact distinct rows must remain explicitly NO_VERIFICABLE."""
    if not isinstance(raw, str):
        raise ValueError(UNKNOWN)
    result = {}
    for key, (title, reason, _heading) in CLAUSES.items():
        first = chr(96) + key + chr(96) + " · " + chr(96) + GUIDE + chr(96)
        lines = [line for line in raw.splitlines()
                 if line.startswith("| " + first + " |")]
        if len(lines) != 1:
            raise ValueError(UNKNOWN + ": row missing/duplicated: " + key)
        cells = tuple(part.strip() for part in lines[0].strip("|").split("|"))
        if (len(cells) != 5 or cells[0] != first or cells[1] != title
                or cells[2] != chr(96) + "NO_VERIFICABLE" + chr(96)
                or "Falta vincular para " + key + " (" not in cells[3]
                or reason not in cells[3]
                or "no se identificó tríada código+prueba+PR fusionado" not in cells[3]
                or cells[4] != "#1"):
            raise ValueError(UNKNOWN + ": invalid row: " + key)
        result[key] = cells
    return result


def contrast_rows(raw):
    """Independent contrast must also say NO_VERIFICABLE for all five."""
    if not isinstance(raw, str):
        raise ValueError(UNKNOWN)
    result = {}
    for key in CLAUSES:
        first = chr(96) + key + chr(96)
        rows = [line for line in raw.splitlines()
                if line.startswith("| " + first + " |")]
        if len(rows) != 1:
            raise ValueError(UNKNOWN + ": contrast missing/duplicate " + key)
        cells = tuple(part.strip() for part in rows[0].strip("|").split("|"))
        if (len(cells) < 7 or cells[0] != first
                or cells[1] != chr(96) + GUIDE + chr(96)
                or cells[2] != chr(96) + "NO_VERIFICABLE" + chr(96)
                or "Falta para " + key not in cells[-1]):
            raise ValueError(UNKNOWN + ": invalid contrast " + key)
        result[key] = cells
    return result


def scoped(raw, heading):
    if not isinstance(raw, str):
        return None
    matches = list(re.finditer(r"(?m)^" + re.escape(heading) + r"[ \t]*$", raw))
    if len(matches) != 1:
        return None
    following = raw[matches[0].end():]
    next_heading = re.search(r"(?m)^#{2,3} ", following)
    return following[:next_heading.start()] if next_heading else following


def assess(key, documents):
    """Returns gap documentation only, never an accepted business journey."""
    if key not in CLAUSES or not isinstance(documents, dict):
        return UNKNOWN
    if any(not isinstance(documents.get(p), str) or not documents[p]
           for p in (MATRIX, GUIDE, CONTRAST)):
        return UNKNOWN
    try:
        matrix_rows(documents[MATRIX])
        contrast_rows(documents[CONTRAST])
    except ValueError:
        return UNKNOWN
    title, _reason, heading = CLAUSES[key]
    content = scoped(documents[GUIDE], heading)
    if content is None:
        return UNKNOWN
    if key == "OPS-REC-D":
        required = ("Modelar cada empresa como tenant separado",
                    "no consolidar información saltando permisos",
                    "no ofrecer visibilidad cruzada sin una autorización explícita",
                    "en construcción")
    elif key == "OPS-REC-C-P4":
        required = (title, "en construcción")
    else:
        required = (title,)
    if (any(fragment.casefold() not in content.casefold()
            for fragment in required)
            or "No activar live" not in documents[GUIDE]):
        return UNKNOWN
    return GAP


def synthetic_scope(request, actor, required_role="operations_manager"):
    """Synthetic negative probe: not actual server-side authorization."""
    fields = ("tenant", "entity", "site")
    if (not isinstance(request, dict) or not isinstance(actor, dict)
            or any(not isinstance(request.get(f), str) or not request[f]
                   or not isinstance(actor.get(f), str) or not actor[f]
                   for f in fields)
            or not isinstance(actor.get("role"), str)):
        return UNKNOWN
    if any(request[f] != actor[f] for f in fields) or actor["role"] != required_role:
        return DENY
    return "SYNTHETIC_SCOPE_ONLY"


def synthetic_quote(quote):
    """Illustrative COP price precedence, not platform pricing implementation."""
    if not isinstance(quote, dict) or quote.get("currency") != "COP":
        return UNKNOWN
    if quote.get("authorized") is not True:
        return DENY
    try:
        qty = Decimal(str(quote["quantity"]))
        unit = Decimal(str(quote["unit_cop"]))
        category = Decimal(str(quote["category_discount_pct"]))
        line = Decimal(str(quote["line_discount_pct"]))
    except (KeyError, InvalidOperation, ValueError, TypeError):
        return UNKNOWN
    if (not all(v.is_finite() for v in (qty, unit, category, line))
            or qty != qty.to_integral_value() or qty < 1 or qty > 10000
            or unit != unit.to_integral_value() or unit < 0
            or category < 0 or category > 100 or line < 0 or line > 100):
        return DENY
    result = (qty * unit * (100 - category) / 100
              * (100 - line) / 100).quantize(Decimal("1"), rounding=ROUND_HALF_UP)
    return ("SYNTHETIC_COP_ONLY", result)


def synthetic_group_export(request, actor):
    scope = synthetic_scope(request, actor, required_role="auditor")
    return "SYNTHETIC_TENANT_ONLY" if scope == "SYNTHETIC_SCOPE_ONLY" else scope


def operational_state(_key, _claim=None):
    """No synthetic evidence can certify real commerce, privileges or production."""
    return UNKNOWN


class BrechasCondor36Tests(unittest.TestCase):
    def test_contrato_principal(self):
        docs = read_contracts()
        self.assertEqual(5, len(CLAUSES))
        self.assertEqual(set(CLAUSES), set(matrix_rows(docs[MATRIX])))
        self.assertEqual(set(CLAUSES), set(contrast_rows(docs[CONTRAST])))
        for key in CLAUSES:
            with self.subTest(key=key):
                self.assertEqual(GAP, assess(key, docs))
        scope = dict(tenant="fake-a", entity="fake-e", site="fake-s")
        operator = {**scope, "role": "operations_manager"}
        self.assertEqual("SYNTHETIC_SCOPE_ONLY", synthetic_scope(scope, operator))
        for field in ("tenant", "entity", "site"):
            self.assertEqual(DENY, synthetic_scope(
                scope, {**operator, field: "fake-other"}))
        self.assertEqual(DENY, synthetic_scope(
            scope, {**operator, "role": "viewer"}))
        quote = dict(currency="COP", authorized=True, quantity=2,
                     unit_cop=10000, category_discount_pct=10,
                     line_discount_pct=5)
        self.assertEqual(("SYNTHETIC_COP_ONLY", Decimal(17100)),
                         synthetic_quote(quote))
        self.assertEqual(DENY, synthetic_quote({**quote, "authorized": False}))
        self.assertEqual(UNKNOWN, synthetic_quote({**quote, "currency": "USD"}))
        auditor = {**scope, "role": "auditor"}
        self.assertEqual("SYNTHETIC_TENANT_ONLY",
                         synthetic_group_export(scope, auditor))
        self.assertEqual(DENY, synthetic_group_export(
            scope, {**auditor, "tenant": "fake-other"}))

    def test_falla_cerrado(self):
        docs = read_contracts()
        for key in CLAUSES:
            line = next(x for x in docs[MATRIX].splitlines()
                        if x.startswith("| " + chr(96) + key + chr(96) + " · "))
            for bad in (docs[MATRIX].replace(line, "", 1),
                        docs[MATRIX] + "\n" + line,
                        docs[MATRIX].replace(
                            line, line.replace("NO_VERIFICABLE", "CUBIERTO", 1), 1)):
                with self.subTest(key=key):
                    with self.assertRaisesRegex(ValueError, UNKNOWN):
                        matrix_rows(bad)
                    self.assertEqual(UNKNOWN, assess(key, {**docs, MATRIX: bad}))
            row = next(x for x in docs[CONTRAST].splitlines()
                       if x.startswith("| " + chr(96) + key + chr(96) + " |"))
            self.assertEqual(UNKNOWN, assess(key, {
                **docs, CONTRAST: docs[CONTRAST].replace(row, "", 1)}))
        self.assertEqual(UNKNOWN, assess("OPS-REC-NONEXISTENT", docs))
        self.assertEqual(UNKNOWN, assess("OPS-REC-D", {}))
        self.assertEqual(UNKNOWN, synthetic_scope({}, {}))
        self.assertEqual(UNKNOWN, synthetic_group_export({}, {}))
        quote = dict(currency="COP", authorized=True, quantity=1, unit_cop=100,
                     category_discount_pct=0, line_discount_pct=0)
        for corrupt in ({"quantity": 0}, {"unit_cop": -1},
                        {"category_discount_pct": 101},
                        {"line_discount_pct": -1}, {"quantity": "NaN"}):
            self.assertEqual(DENY, synthetic_quote({**quote, **corrupt}))
        self.assertEqual(UNKNOWN, synthetic_quote(
            {**quote, "unit_cop": "not-a-number"}))

    def test_sin_datos_reales(self):
        tree = ast.parse(Path(__file__).read_text(encoding="utf-8"))
        imports = {alias.name.split(".")[0] for node in ast.walk(tree)
                   if isinstance(node, ast.Import) for alias in node.names}
        imports |= {node.module.split(".")[0] for node in ast.walk(tree)
                    if isinstance(node, ast.ImportFrom) and node.module}
        self.assertFalse(imports & {"requests", "urllib", "socket", "httpx",
                                    "subprocess", "pymysql", "mysql", "playwright"})
        self.assertEqual({MATRIX, GUIDE, CONTRAST}, set(read_contracts()))
        self.assertEqual(UNKNOWN, operational_state("OPS-REC-D",
                         {"authorized": True, "live": True}))

    def test_estados_unknown_explicitos(self):
        docs = read_contracts()
        for key in CLAUSES:
            self.assertEqual(GAP, assess(key, docs))
            for claim in (None, {}, True, {"sha": "a" * 40, "ci": "success"},
                          {"state": "VALIDATED_IN_PRODUCTION"},
                          {"commercial_policy": "APPROVED"},
                          {"cross_tenant": "APPROVED"},
                          {"review": "APPROVED"}):
                with self.subTest(key=key, claim=str(claim)):
                    self.assertEqual(UNKNOWN, operational_state(key, claim))
        self.assertEqual(UNKNOWN, synthetic_quote(
            {"currency": "COP", "authorized": True}))


if __name__ == "__main__":
    unittest.main()
