#!/usr/bin/env python3
"""Condor #1230: five operational-journey B/C gaps, synthetic and fail-closed.

A documented step or an isolated local test is not an integrated real-world
journey, an exact-HEAD CI verdict, a production validation, or a go-live.
Only checked-in documentation/tests are read; no network, DB or credentials.
"""
from __future__ import annotations

import ast
from decimal import Decimal
from pathlib import Path
import unittest

ROOT = Path(__file__).resolve().parents[1]
MATRIX = "docs/matriz-cobertura.md"
GUIDE = "docs/modulos-operativos.md"
CONTRAST = "docs/contraste-cd_ops.md"
ISOLATION = "tests/test_new_modules_tenant_isolation.py"
PRICING = "tests/test_effective_price_explainer.py"
GUIDE_TEST = "tests/test_docs_modulos_operativos.py"
SOURCES = (MATRIX, GUIDE, CONTRAST, ISOLATION, PRICING, GUIDE_TEST)
UNKNOWN = "UNKNOWN"
GAP = "BRECHA_DOCUMENTADA"
DENY = "DENY"
TICK = chr(96)

CLAUSES = {
    "OPS-REC-B-P1": (
        "Configurar **Catálogo y precios** e **Inventario y sedes** para los materiales.",
        "regresión de denegación cross-tenant/entidad/sede y prueba de privilegio mínimo",
        "### Recorrido B — Taller",
        ((ISOLATION, "testProductionCostRejectsForeignOrderAndMaterialBeforeRead"),
         (GUIDE_TEST, "scenario_")),
    ),
    "OPS-REC-B-P2": (
        "Registrar BOM y órdenes de prueba en **Producción y materiales**.",
        "evidencia de ejecución sobre SHA exacto, resultado terminal y prueba de fail-closed",
        "### Recorrido B — Taller",
        ((ISOLATION, "testProductionCostRejectsForeignOrderAndMaterialBeforeRead"),
         (GUIDE_TEST, "ProductionOperationsController.php")),
    ),
    "OPS-REC-B-P3": (
        "Evaluar **Compras y proveedores** para la reposición.",
        "regresión de consistencia entre controlador, persistencia y permisos del tenant",
        "### Recorrido B — Taller",
        ((ISOLATION, "testPurchasingRejectsForeignSupplierAndPurchaseAssociations"),
         (GUIDE_TEST, "PurchasingController.php")),
    ),
    "OPS-REC-B-P4": (
        "El costo por orden integrado en pantalla sigue **en construcción**, y no se debe prometer un ERP contable.",
        "prueba de montos, precedencias, moneda COP y rechazo de transacción inválida",
        "### Recorrido B — Taller",
        ((PRICING, "selected_rule_id"), (PRICING, "total_amount_minor"),
         (PRICING, "Overflow monetario")),
    ),
    "OPS-REC-C": (
        "Recorrido C (preparación, no producción)",
        "evidencia de ejecución sobre SHA exacto, resultado terminal y prueba de fail-closed",
        "### Recorrido C — Distribuidora con sedes",
        ((GUIDE_TEST, "Distribuidora con sedes"),
         (ISOLATION, "testPurchasingRejectsForeignSupplierAndPurchaseAssociations")),
    ),
}


def read_sources() -> dict[str, str]:
    return {path: (ROOT / path).read_text(encoding="utf-8") for path in SOURCES}


def matrix_rows(raw: object) -> dict[str, tuple[str, ...]]:
    if type(raw) is not str:
        raise ValueError("UNKNOWN: matriz ausente")
    found = {}
    for key, (title, reason, _heading, _local) in CLAUSES.items():
        prefix = f"| {TICK}{key}{TICK} · "
        lines = [line for line in raw.splitlines() if line.startswith(prefix)]
        if len(lines) != 1:
            raise ValueError("UNKNOWN: fila ausente/duplicada " + key)
        cells = tuple(v.strip() for v in lines[0].strip("|").split("|"))
        if (len(cells) != 5
                or cells[0] != f"{TICK}{key}{TICK} · {TICK}{GUIDE}{TICK}"
                or cells[1] != title
                or cells[2] != f"{TICK}NO_VERIFICABLE{TICK}"
                or f"Falta vincular para {key} (" not in cells[3]
                or reason not in cells[3]
                or "no se identificó tríada código+prueba+PR fusionado" not in cells[3]
                or cells[4] != "#1"):
            raise ValueError("UNKNOWN: fila inconsistente " + key)
        found[key] = cells
    return found


def contrast_rows(raw: object) -> dict[str, tuple[str, ...]]:
    if type(raw) is not str:
        raise ValueError("UNKNOWN: contraste ausente")
    found = {}
    for key in CLAUSES:
        lines = [line for line in raw.splitlines()
                 if line.startswith(f"| {TICK}{key}{TICK} |")]
        if len(lines) != 1:
            raise ValueError("UNKNOWN: contraste ausente/duplicado " + key)
        cells = tuple(v.strip() for v in lines[0].strip("|").split("|"))
        if (len(cells) < 7 or cells[0] != f"{TICK}{key}{TICK}"
                or cells[1] != f"{TICK}{GUIDE}{TICK}"
                or cells[2] != f"{TICK}NO_VERIFICABLE{TICK}"
                or f"Falta para {key} verificar" not in cells[-1]
                or "no equivale a un smoke real" not in cells[-1]):
            raise ValueError("UNKNOWN: contraste inconsistente " + key)
        found[key] = cells
    return found


def section(raw: object, heading: str) -> str:
    if type(raw) is not str:
        return ""
    lines = raw.splitlines()
    if lines.count(heading) != 1:
        return ""
    start = lines.index(heading) + 1
    end = next((i for i in range(start, len(lines))
                if lines[i].startswith(("### ", "## "))), len(lines))
    return "\n".join(lines[start:end])


def classify(key: object, documents: object) -> str:
    """Return only documentary gap evidence; never mark capability CUBIERTO."""
    if type(key) is not str or key not in CLAUSES or type(documents) is not dict:
        return UNKNOWN
    if set(documents) != set(SOURCES):
        return UNKNOWN
    if any(type(documents[path]) is not str or not documents[path] for path in SOURCES):
        return UNKNOWN
    try:
        matrix_rows(documents[MATRIX])
        contrast_rows(documents[CONTRAST])
    except ValueError:
        return UNKNOWN
    title, _reason, heading, markers = CLAUSES[key]
    guide = documents[GUIDE]
    text = section(guide, heading)
    if (not text or "Estado general: construcción/pre-live" not in guide
            or "No activar live" not in guide
            or any(marker not in documents[path] for path, marker in markers)):
        return UNKNOWN
    if key == "OPS-REC-C":
        required = ("Definir sedes y permisos", "Inventario y sedes",
                    "Compras y proveedores", "Reportes CSV",
                    "Transferencias automáticas intersede",
                    "**en construcción**")
    else:
        required = (title,)
        if key == "OPS-REC-B-P4":
            required += ("no se debe prometer un ERP contable",)
    if any(fragment not in text for fragment in required):
        return UNKNOWN
    return GAP


def synthetic_scope(request: object, actor: object) -> str:
    """Illustrative deny-only scope model; not application authorization."""
    fields = ("tenant", "entity", "site")
    if (type(request) is not dict or type(actor) is not dict
            or any(type(request.get(k)) is not str or not request[k]
                   or type(actor.get(k)) is not str or not actor[k] for k in fields)
            or type(actor.get("role")) is not str):
        return UNKNOWN
    if actor["role"] != "operations_manager" or any(
            request[k] != actor[k] for k in fields):
        return DENY
    return "SYNTHETIC_SCOPE_ONLY"


def synthetic_cop_quote(fields: object) -> object:
    """Offline arithmetic boundary, not Condor's pricing/ERP implementation."""
    if type(fields) is not dict:
        return UNKNOWN
    if fields.get("currency") != "COP":
        return UNKNOWN
    if fields.get("authorized") is not True:
        return DENY
    for key in ("quantity", "unit_amount_minor", "discount_pct"):
        if type(fields.get(key)) is not int:
            return UNKNOWN
    qty, unit, pct = (fields[k] for k in
                      ("quantity", "unit_amount_minor", "discount_pct"))
    if (qty < 1 or qty > 10000 or unit < 0 or unit > 9007199254740991
            or pct < 0 or pct > 100
            or qty * unit > 9007199254740991):
        return DENY
    amount = (Decimal(qty) * Decimal(unit)
              * Decimal(100 - pct) / Decimal(100))
    return ("SYNTHETIC_COP_ONLY", int(amount.quantize(Decimal("1"))))


def operational_state(_key: object, _claim: object = None) -> str:
    """External CI, production, payments and full journey remain unobserved."""
    return UNKNOWN


class BrechasCondor35Tests(unittest.TestCase):
    def test_contrato_principal(self) -> None:
        data = read_sources()
        self.assertEqual(5, len(CLAUSES))
        self.assertEqual(set(CLAUSES), set(matrix_rows(data[MATRIX])))
        self.assertEqual(set(CLAUSES), set(contrast_rows(data[CONTRAST])))
        for key in CLAUSES:
            with self.subTest(row=key):
                self.assertEqual(GAP, classify(key, data))
                self.assertEqual(UNKNOWN, operational_state(key, data))
        scope = {"tenant": "synthetic-a", "entity": "synthetic-e",
                 "site": "synthetic-s"}
        self.assertEqual("SYNTHETIC_SCOPE_ONLY",
                         synthetic_scope(scope, {**scope, "role": "operations_manager"}))
        for changed in ("tenant", "entity", "site"):
            self.assertEqual(DENY, synthetic_scope(
                scope, {**scope, "role": "operations_manager",
                        changed: "synthetic-foreign"}))
        self.assertEqual(DENY, synthetic_scope(
            scope, {**scope, "role": "viewer"}))
        quote = dict(currency="COP", authorized=True, quantity=2,
                     unit_amount_minor=10000, discount_pct=10)
        self.assertEqual(("SYNTHETIC_COP_ONLY", 18000),
                         synthetic_cop_quote(quote))
        self.assertEqual(DENY, synthetic_cop_quote(
            {**quote, "authorized": False}))

    def test_falla_cerrado(self) -> None:
        data = read_sources()
        for key, (_, reason, heading, markers) in CLAUSES.items():
            line = next(s for s in data[MATRIX].splitlines()
                        if s.startswith(f"| {TICK}{key}{TICK} · "))
            for damaged in (
                    data[MATRIX].replace(line, "", 1),
                    data[MATRIX] + "\n" + line,
                    data[MATRIX].replace(line, line.replace(
                        "NO_VERIFICABLE", "CUBIERTO"), 1),
                    data[MATRIX].replace(line, line.replace(reason, "aprobado"), 1)):
                with self.subTest(row=key):
                    with self.assertRaisesRegex(ValueError, UNKNOWN):
                        matrix_rows(damaged)
                    self.assertEqual(UNKNOWN, classify(key, {
                        **data, MATRIX: damaged}))
            changed = {**data, GUIDE: data[GUIDE].replace(heading, "### Otro", 1)}
            self.assertEqual(UNKNOWN, classify(key, changed))
            path, marker = markers[0]
            self.assertEqual(UNKNOWN, classify(key, {
                **data, path: data[path].replace(marker, "", 1)}))
            contrast_line = next(s for s in data[CONTRAST].splitlines()
                                 if s.startswith(f"| {TICK}{key}{TICK} |"))
            self.assertEqual(UNKNOWN, classify(key, {
                **data, CONTRAST: data[CONTRAST].replace(
                    contrast_line, "", 1)}))
        self.assertEqual(UNKNOWN, classify("OPS-REC-UNKNOWN", data))
        self.assertEqual(UNKNOWN, classify("OPS-REC-C", {}))
        self.assertEqual(UNKNOWN, classify("OPS-REC-C", None))
        self.assertEqual(UNKNOWN, synthetic_scope({}, {}))
        quote = dict(currency="COP", authorized=True, quantity=1,
                     unit_amount_minor=1000, discount_pct=0)
        for bad in ({"quantity": 0}, {"quantity": True},
                    {"unit_amount_minor": -1}, {"discount_pct": 101},
                    {"quantity": 9007199254740991}):
            self.assertNotEqual("SYNTHETIC_COP_ONLY",
                                synthetic_cop_quote({**quote, **bad}))

    def test_sin_datos_reales(self) -> None:
        own = (ROOT / "tests/test_brechas_condor_35.py").read_text(
            encoding="utf-8")
        imports = set()
        for node in ast.walk(ast.parse(own)):
            if isinstance(node, ast.Import):
                imports.update(alias.name.split(".")[0] for alias in node.names)
            elif isinstance(node, ast.ImportFrom) and node.module:
                imports.add(node.module.split(".")[0])
        self.assertFalse(imports & {"requests", "urllib", "httpx", "socket",
                                    "subprocess", "pymysql", "mysql", "playwright"})
        self.assertEqual(set(SOURCES), set(read_sources()))
        self.assertNotIn("BEGIN " + "PRIVATE KEY", own)
        self.assertEqual(UNKNOWN, operational_state(
            "OPS-REC-B-P1", {"tenant": "synthetic-a", "live": True}))

    def test_estados_unknown_explicitos(self) -> None:
        data = read_sources()
        for key in CLAUSES:
            self.assertEqual(GAP, classify(key, data))
            for claim in (None, {}, {"sha": "0" * 40, "ci": "success"},
                          {"status": "VALIDATED_IN_PRODUCTION"},
                          {"payment": "APPROVED"}, {"erp": "ready"},
                          {"permissions": "allowed"}):
                with self.subTest(row=key, claim=claim):
                    self.assertEqual(UNKNOWN, operational_state(key, claim))
        self.assertEqual(UNKNOWN, synthetic_cop_quote(
            {"currency": "USD", "authorized": True}))


if __name__ == "__main__":
    unittest.main()
