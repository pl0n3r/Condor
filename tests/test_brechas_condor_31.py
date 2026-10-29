#!/usr/bin/env python3
"""Condor #1226: evidencia local acotada de cinco brechas operativas.

Los asserts de pruebas versionadas solo prueban que existe evidencia parcial.
No acreditan integración total, conciliación, alertas, CI, despliegue o live.
No hay red, DB, secretos, usuarios ni fixtures productivos.
"""
from __future__ import annotations

import ast
from pathlib import Path
import re
import unittest

ROOT = Path(__file__).resolve().parents[1]
MATRIX = "docs/matriz-cobertura.md"
GUIDE = "docs/modulos-operativos.md"
CATALOG = "tests/php/Http/CatalogControllerTest.php"
CHECKOUT = "tests/php/Http/StorefrontCheckoutControllerTest.php"
ORDERS = "tests/php/Http/OrderControllerTest.php"
INVENTORY = "tests/php/Http/InventoryControllerTest.php"
UNKNOWN = "UNKNOWN"
PARTIAL = "LOCAL_PARTIAL"
IDS = (
    "OPS-M01-PRUEBA", "OPS-M02-PRUEBA", "OPS-M03-PENDIENTE",
    "OPS-M03-PRUEBA", "OPS-M04-PENDIENTE",
)
# ID -> normative source section, exact row text, concrete outstanding gap, PHP
# test methods with their characteristic assertions (not merely method names).
CLAUSES = {
    "OPS-M01-PRUEBA": (
        "### 1. Catálogo y precios",
        "crear un catálogo pequeño y revisar variantes, unidades y reglas de precios con datos sintéticos.",
        "una prueba de montos, precedencias, moneda COP y rechazo de transacción inválida",
        ((CATALOG, "testOwnerCanCreateVariantEditAndListCatalog",
          ("assertResponseStatusCodeSame(201)", "assertInstanceOf(Product::class", "assertSame('TEE-BLACK-M'")),
         (CHECKOUT, "testCheckoutRecalculatesServerStateAndIsIdempotent",
          ("assertSame(250000", "InventoryBalance::class"))),
    ),
    "OPS-M02-PRUEBA": (
        "### 2. Tienda y checkout",
        "configurar escaparate y simular un pedido antes de habilitar ventas reales.",
        "una regresión de consistencia entre controlador, persistencia y permisos del tenant",
        ((CHECKOUT, "testCheckoutRecalculatesServerStateAndIsIdempotent",
          ("assertResponseStatusCodeSame(201)", "assertSame(250000", "InventoryBalance::class")),
         (CHECKOUT, "testCheckoutRejectsClientSuppliedCommercialState",
          ("assertResponseStatusCodeSame(422)",)),
         (CHECKOUT, "testCheckoutRejectsOversellWithoutBackorder",
          ("assertResponseStatusCodeSame(422)", "assertNull("))),
    ),
    "OPS-M03-PENDIENTE": (
        "### 3. Pedidos y ventas",
        "el flujo completo de recepción, facturación y conciliación operativa depende de gates independientes.",
        "una prueba de montos, precedencias, moneda COP y rechazo de transacción inválida",
        ((ORDERS, "testOwnerCreatesManualOrderAndReplayIsIdempotent",
          ("assertResponseStatusCodeSame(201)", "assertSame(2000", "reservedQuantity()")),),
    ),
    "OPS-M03-PRUEBA": (
        "### 3. Pedidos y ventas",
        "definir estados de pedido y probar cambios de stock en entorno de prueba.",
        "una regresión de consistencia entre controlador, persistencia y permisos del tenant",
        ((ORDERS, "testOwnerCreatesManualOrderAndReplayIsIdempotent",
          ("InventoryBalance::class", "reservedQuantity()")),
         (ORDERS, "testBranchUserCannotSeeCancelOrConsumeAnotherBranchOrder",
          ("assertSame([],", "assertResponseStatusCodeSame(404)"))),
    ),
    "OPS-M04-PENDIENTE": (
        "### 4. Inventario y sedes",
        "alertas automáticas de stock bajo y escaneo de códigos en el admin siguen en hojas separadas; no afirmar que están integradas.",
        "una regresión de consistencia entre controlador, persistencia y permisos del tenant",
        ((INVENTORY, "testOwnerCanAdjustTransferAndReadInventory",
          ("assertCount(2, $snapshot['sources'])", "assertCount(3, $snapshot['movements'])")),
         (INVENTORY, "testDelegatedViewerCannotAdjustInventory",
          ("assertResponseStatusCodeSame(403)",)),
         (INVENTORY, "testInventoryRejectsCrossTenantIdentifiers",
          ("assertResponseStatusCodeSame(404)",))),
    ),
}
SOURCES = (MATRIX, GUIDE, CATALOG, CHECKOUT, ORDERS, INVENTORY)


def read_sources() -> dict[str, str]:
    """Only checked-in files; never contact network or production."""
    return {path: (ROOT / path).read_text(encoding="utf-8") for path in SOURCES}


def matrix_rows(raw: str) -> dict[str, tuple[str, ...]]:
    """Every target row must be unique, exact and still NO_VERIFICABLE."""
    if not isinstance(raw, str):
        raise ValueError("UNKNOWN: matrix unreadable")
    found = {}
    for key, (heading, summary, gap, _tests) in CLAUSES.items():
        prefix = "| `" + key + "` · `" + GUIDE + "` |"
        matches = [line for line in raw.splitlines() if line.startswith(prefix)]
        if len(matches) != 1:
            raise ValueError("UNKNOWN: missing/duplicate " + key)
        cells = tuple(cell.strip() for cell in matches[0].strip("|").split("|"))
        if (len(cells) != 5
                or cells[0] != "`" + key + "` · `" + GUIDE + "`"
                or cells[1] != summary
                or cells[2] != "`NO_VERIFICABLE`"
                or "Falta vincular para " + key + " (" not in cells[3]
                or gap not in cells[3]
                or "no se identificó tríada código+prueba+PR fusionado" not in cells[3]
                or cells[4] != "#1"):
            raise ValueError("UNKNOWN: changed contract " + key)
        found[key] = cells
    return found


def section(raw: str, heading: str) -> str | None:
    """Avoid matching a similar sentence in an unrelated operational module."""
    if not isinstance(raw, str):
        return None
    hits = list(re.finditer(r"(?m)^" + re.escape(heading) + r"\s*$", raw))
    if len(hits) != 1:
        return None
    tail = raw[hits[0].end():]
    end = re.search(r"(?m)^#{2,3} +", tail)
    return tail[:end.start()] if end else tail


def php_method(source: str, name: str) -> str | None:
    """Extract the real method body; an assertion in another method is irrelevant."""
    if not isinstance(source, str):
        return None
    matches = list(re.finditer(
        r"(?m)^\s*public function " + re.escape(name) + r"\(\): void\s*$", source))
    if len(matches) != 1:
        return None
    tail = source[matches[0].end():]
    end = re.search(r"(?m)^\s*public function\s+", tail)
    return tail[:end.start()] if end else tail


def local_evidence(key: str, data: dict[str, str]) -> str:
    """Indicate local *partial* coverage; never promote full module support."""
    if key not in CLAUSES or not isinstance(data, dict):
        return UNKNOWN
    if any(not isinstance(data.get(p), str) or not data[p] for p in SOURCES):
        return UNKNOWN
    try:
        matrix_rows(data[MATRIX])
    except ValueError:
        return UNKNOWN
    heading, summary, _gap, tests = CLAUSES[key]
    body = section(data[GUIDE], heading)
    if body is None or summary not in body:
        return UNKNOWN
    for path, method_name, assertions in tests:
        method = php_method(data[path], method_name)
        if (method is None
                or "self::assert" not in method
                or any(part not in method for part in assertions)):
            return UNKNOWN
    return PARTIAL


def synthetic_authorize(actor: object, target: object) -> str:
    """Illustrative negative isolation, not an application permission test."""
    if not isinstance(actor, dict) or not isinstance(target, dict):
        return UNKNOWN
    required = ("tenant", "branch")
    if any(not isinstance(actor.get(k), str) or not actor[k]
           or not isinstance(target.get(k), str) or not target[k]
           for k in required):
        return UNKNOWN
    return "DENY" if any(actor[k] != target[k] for k in required) else "SYNTHETIC_ONLY"


def synthetic_commercial(amount: object, count: object, currency: object) -> str:
    """Fail closed on malformed simulated COP amounts; not a pricing rule engine."""
    if (type(amount) is not int or type(count) is not int
            or amount <= 0 or count <= 0 or currency != "COP"):
        return UNKNOWN
    return "SYNTHETIC_ONLY"


def operational_verdict(_key: str, _local: object = None) -> str:
    """No inferred receipt/invoicing, low-stock alarms, CI or production."""
    return UNKNOWN


class BrechasCondor31Tests(unittest.TestCase):
    def test_contrato_principal(self) -> None:
        data = read_sources()
        self.assertEqual(5, len(CLAUSES))
        self.assertEqual(set(IDS), set(matrix_rows(data[MATRIX])))
        for key in IDS:
            with self.subTest(row=key):
                self.assertEqual(PARTIAL, local_evidence(key, data))
                self.assertEqual(UNKNOWN, operational_verdict(key, PARTIAL))
        context = {"tenant": "synthetic-a", "branch": "synthetic-one"}
        self.assertEqual("SYNTHETIC_ONLY", synthetic_authorize(context, context))
        self.assertEqual("DENY", synthetic_authorize(
            context, {**context, "tenant": "synthetic-other"}))
        self.assertEqual("DENY", synthetic_authorize(
            context, {**context, "branch": "synthetic-other"}))
        self.assertEqual("SYNTHETIC_ONLY", synthetic_commercial(250000, 2, "COP"))

    def test_falla_cerrado(self) -> None:
        data = read_sources()
        raw = data[MATRIX]
        for key in IDS:
            with self.subTest(key=key):
                row = next(line for line in raw.splitlines() if line.startswith(
                    "| `" + key + "` · "))
                with self.assertRaisesRegex(ValueError, "missing/duplicate"):
                    matrix_rows(raw + "\n" + row)
                with self.assertRaisesRegex(ValueError, "missing/duplicate"):
                    matrix_rows(raw.replace(row + "\n", "", 1))
                with self.assertRaisesRegex(ValueError, "changed contract"):
                    matrix_rows(raw.replace(row, row.replace("`NO_VERIFICABLE`", "`CUBIERTO`")))
                for path in SOURCES:
                    self.assertEqual(UNKNOWN, local_evidence(
                        key, {**data, path: ""}))
                self.assertEqual(UNKNOWN, local_evidence(key, {
                    **data, MATRIX: raw.replace(row, row.replace("Falta vincular", "Acreditado"))}))
        self.assertEqual(UNKNOWN, local_evidence("OPS-UNKNOWN", data))
        self.assertEqual(UNKNOWN, synthetic_authorize({}, {}))
        self.assertEqual(UNKNOWN, synthetic_commercial(0, 2, "COP"))
        self.assertEqual(UNKNOWN, synthetic_commercial(1000, -1, "COP"))
        self.assertEqual(UNKNOWN, synthetic_commercial(1000, 1, "USD"))
        self.assertEqual(UNKNOWN, synthetic_commercial("1000", 1, "COP"))
        self.assertIsNone(php_method("", "testOwnerCanCreateVariantEditAndListCatalog"))
        self.assertIsNone(section("", "### 1. Catálogo y precios"))

    def test_sin_datos_reales(self) -> None:
        own = Path(__file__).read_text(encoding="utf-8")
        import_names = {name.names[0].name.split(".")[0] for name in
                        ast.walk(ast.parse(own)) if isinstance(name, ast.Import)}
        self.assertFalse(import_names & {"requests", "urllib", "httpx", "socket", "pymysql"})
        self.assertTrue(all(key in own for key in IDS))
        self.assertEqual(set(SOURCES), set(read_sources()))
        self.assertNotIn("BEGIN " + "PRIVATE KEY", own)
        self.assertNotIn("curl_" + "exec(", own)
        self.assertEqual(UNKNOWN, operational_verdict("OPS-M04-PENDIENTE"))

    def test_estados_unknown_explicitos(self) -> None:
        data = read_sources()
        for key in IDS:
            with self.subTest(key=key):
                self.assertEqual(PARTIAL, local_evidence(key, data))
                for claimed in (None, True, "success", {"ci": "success"}, PARTIAL):
                    self.assertEqual(UNKNOWN, operational_verdict(key, claimed))
                self.assertEqual(UNKNOWN, local_evidence(key, {
                    **data, GUIDE: data[GUIDE].replace(CLAUSES[key][1], "", 1)}))
        self.assertEqual(UNKNOWN, synthetic_commercial(None, 1, "COP"))
        self.assertEqual(UNKNOWN, synthetic_commercial(True, 1, "COP"))


if __name__ == "__main__":
    unittest.main()