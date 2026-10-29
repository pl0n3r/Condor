#!/usr/bin/env python3
"""Condor #1234: independent offline evidence for README-03-02..06.

Source and existing synthetic regression assertions are only *partial local
evidence*: they never prove a merged PR for a coverage row, live payment,
customer data, production readiness or an integrated commercial flow.
"""
from __future__ import annotations

import ast
from pathlib import Path
import unittest

ROOT = Path(__file__).resolve().parents[1]
MATRIX = "docs/matriz-cobertura.md"
README = "README.md"
UNKNOWN = "UNKNOWN"
GAP = "BRECHA_DOCUMENTADA"
TICK = chr(96)

CLAUSES = {
    "README-03-02": (
        "Tienda y checkout",
        "StorefrontCheckoutController",
        "Tienda y checkout: StorefrontCheckoutController; no implica pagos habilitados.",
        "src/Http/Controller/StorefrontCheckoutController.php",
        "tests/test_checkout_http_negatives.py",
        ("final class StorefrontCheckoutController",
         "public function __invoke(Request $request): JsonResponse",
         "if ($tenant === null)", "!$channel->isPublishable()",
         "OrderService::ORIGIN_ECOMMERCE"),
        ("class CheckoutNegativasHttpTests", "def test_acceptance",
         "foreign_variant", "client_unit_price", "tenant_a_server_total",
         "tenant_b_server_total"),
    ),
    "README-03-03": (
        "Pedidos y ventas",
        "OrderController",
        "Pedidos y ventas: OrderController.",
        "src/Http/Controller/OrderController.php",
        "tests/test_orders_http_isolation.py",
        ("final class OrderController", "use BranchApiSupport;",
         "'orders.view'", "'orders.create'",
         "'tenant' => $tenant", "$this->requireCsrf($request);"),
        ("class PedidosAislamientoHttpTests", "def test_acceptance",
         "testTenantOrdersAreHiddenAndMutationsDenied"),
    ),
    "README-03-04": (
        "Clientes y categorías",
        "CustomerController",
        "Clientes y categorías: CustomerController.",
        "src/Http/Controller/CustomerController.php",
        "tests/test_customers_http_isolation.py",
        ("final class CustomerController", "use BranchApiSupport;",
         "public function createCategory(", "public function createCustomer(",
         "'tenant' => $tenant", "$this->requireCsrf($request);"),
        ("class ClientesAislamientoHttpTests", "def test_acceptance",
         "testCustomerListsAndMutationsAreTenantScoped",
         "assertResponseStatusCodeSame(404)"),
    ),
    "README-03-05": (
        "Inventario por sede",
        "InventoryController",
        "Inventario por sede: InventoryController.",
        "src/Http/Controller/InventoryController.php",
        "tests/test_inventory_http_negatives.py",
        ("final class InventoryController", "use BranchApiSupport;",
         "public function snapshot(", "public function transfer(",
         "'inventory.view'", "$this->requireCsrf($request);"),
        ("class InventarioNegativasHttpTests", "def test_acceptance",
         "testNegativeQuantitiesAndForeignScopesKeepStockUnchanged",
         "assertResponseStatusCodeSame(404)"),
    ),
    "README-03-06": (
        "Compras y proveedores",
        "PurchasingController",
        "Compras y proveedores: PurchasingController.",
        "src/Http/Controller/PurchasingController.php",
        "tests/test_purchasing_http_api.py",
        ("final class PurchasingController", "use BranchApiSupport;",
         "public function suppliers(", "public function createOrder(",
         "'inventory.view'", "'tenant' => $tenant",
         "$this->requireCsrf($request);"),
        ("class ComprasApiHttpTests", "def test_acceptance",
         "testAuthenticatedTenantAndBranchIsolation",
         "testPurchaseOrderRejectsCrossTenantSupplierAndMaterial"),
    ),
}
SOURCES = (MATRIX, README) + tuple(
    path for item in CLAUSES.values() for path in (item[3], item[4])
)
CAPABILITIES = {
    "README-03-02": "storefront.checkout",
    "README-03-03": "orders.view",
    "README-03-04": "customers.view",
    "README-03-05": "inventory.view",
    "README-03-06": "inventory.view",
}


def sources() -> dict[str, str]:
    """Read checked-in UTF-8 sources only: no application, API or database."""
    return {path: (ROOT / path).read_text(encoding="utf-8") for path in SOURCES}


def matrix_rows(raw: object) -> dict[str, tuple[str, ...]]:
    """Exact row IDs, states and obligation text; never auto-promote the matrix."""
    if type(raw) is not str:
        raise ValueError("UNKNOWN: unreadable matrix")
    result: dict[str, tuple[str, ...]] = {}
    for key, (_, _, summary, _, _, _, _) in CLAUSES.items():
        matches = [line for line in raw.splitlines()
                   if line.startswith(f"| {TICK}{key}{TICK} ·")]
        if len(matches) != 1:
            raise ValueError("UNKNOWN: missing/duplicated row " + key)
        cells = tuple(value.strip() for value in matches[0].strip("|").split("|"))
        if (len(cells) != 5
                or cells[0] != f"{TICK}{key}{TICK} · {TICK}{README}{TICK}"
                or cells[1] != "Qué hace el producto — " + summary
                or cells[2] != f"{TICK}NO_VERIFICABLE{TICK}"
                or f"«{summary}»" not in cells[3]
                or "Falta prueba independiente de la obligación" not in cells[3]
                or "PR fusionado vinculado a esa obligación específica" not in cells[3]
                or "contraste-cd_ops.md" not in cells[3]
                or f"({key})" not in cells[3]
                or cells[4] != "#1"):
            raise ValueError("UNKNOWN: inconsistent coverage " + key)
        result[key] = cells
    return result


def product_section(raw: object) -> tuple[str, ...] | None:
    if type(raw) is not str:
        return None
    lines = raw.splitlines()
    heading = "## Qué hace el producto"
    if lines.count(heading) != 1:
        return None
    start = lines.index(heading)
    end = next((i for i in range(start + 1, len(lines))
                if lines[i].startswith("## ")), len(lines))
    region = tuple(lines[start + 1:end])
    if ("**multi-tenant**" not in "\n".join(region)
            or "«presente en código» **no** significa" not in "\n".join(region)):
        return None
    return region


def readme_entry(key: object, readme: object) -> bool:
    if type(key) is not str or key not in CLAUSES:
        return False
    section = product_section(readme)
    if section is None:
        return False
    label, controller, _, path, _, _, _ = CLAUSES[key]
    expected = (
        f"- **{label}:** [{controller}]"
        f"(https://github.com/pl0n3r/Condor/blob/main/{path})"
    )
    if key == "README-03-02":
        expected += "; no implica pagos habilitados."
    else:
        expected += "."
    return (section.count(expected) == 1
            and sum(line.startswith(f"- **{label}:** ") for line in section) == 1
            and sum(line.startswith("- **") for line in section) == 8)


def proof_markers(key: object, data: object) -> bool:
    if type(key) is not str or key not in CLAUSES or type(data) is not dict:
        return False
    _, _, _, controller, test, controller_proofs, test_proofs = CLAUSES[key]
    if set(data) != set(SOURCES):
        return False
    if any(type(data[path]) is not str or not data[path] for path in SOURCES):
        return False
    return (all(needle in data[controller] for needle in controller_proofs)
            and all(needle in data[test] for needle in test_proofs))


def classify(key: object, data: object) -> str:
    """Never infer coverage just from a README link or a test filename."""
    if type(key) is not str or key not in CLAUSES or type(data) is not dict:
        return UNKNOWN
    try:
        matrix_rows(data.get(MATRIX))
    except ValueError:
        return UNKNOWN
    if (not proof_markers(key, data)
            or not readme_entry(key, data.get(README))):
        return UNKNOWN
    return GAP


def synthetic_access(key: object, request: object) -> str:
    """An invented policy example, NOT the PHP application's authorization."""
    if type(key) is not str or key not in CAPABILITIES or type(request) is not dict:
        return UNKNOWN
    fields = {"tenant", "resource_tenant", "branch", "resource_branch",
              "grants", "csrf", "mutation", "publishable", "payload_keys"}
    if set(request) != fields:
        return UNKNOWN
    if (any(type(request[x]) is not str or not request[x]
            for x in ("tenant", "resource_tenant", "branch", "resource_branch"))
            or type(request["grants"]) is not list
            or not all(type(v) is str and v for v in request["grants"])
            or len(request["grants"]) != len(set(request["grants"]))
            or any(type(request[x]) is not bool
                   for x in ("csrf", "mutation", "publishable"))
            or type(request["payload_keys"]) is not list
            or not all(type(v) is str and v for v in request["payload_keys"])
            or len(request["payload_keys"]) != len(set(request["payload_keys"]))):
        return UNKNOWN
    if (request["tenant"] != request["resource_tenant"]
            or request["branch"] != request["resource_branch"]
            or CAPABILITIES[key] not in request["grants"]):
        return "SYNTHETIC_DENY"
    if key == "README-03-02":
        if not request["publishable"] or any(
            p not in ("idempotency_key", "items") for p in request["payload_keys"]
        ):
            return "SYNTHETIC_DENY"
    elif request["mutation"] and not request["csrf"]:
        return "SYNTHETIC_DENY"
    return "SYNTHETIC_ALLOWED_ONLY"


def operational_verdict(_key: object, _receipt: object = None) -> str:
    """Code, tests, fictional permission or green badges never attest live."""
    return UNKNOWN


class BrechasCondor39Tests(unittest.TestCase):
    def test_contrato_principal(self) -> None:
        """AC-01: each exact row has tested partial evidence or explicit gap."""
        data = sources()
        self.assertEqual(set(CLAUSES), set(matrix_rows(data[MATRIX])))
        self.assertEqual(len(CLAUSES), 5)
        for key in CLAUSES:
            with self.subTest(row=key):
                self.assertTrue(readme_entry(key, data[README]))
                self.assertTrue(proof_markers(key, data))
                self.assertEqual(classify(key, data), GAP)
                self.assertEqual(operational_verdict(key, data), UNKNOWN)
                self.assertEqual(matrix_rows(data[MATRIX])[key][2],
                                 f"{TICK}NO_VERIFICABLE{TICK}")
                synthetic = {
                    "tenant": "tenant-synthetic-a",
                    "resource_tenant": "tenant-synthetic-a",
                    "branch": "branch-synthetic-a",
                    "resource_branch": "branch-synthetic-a",
                    "grants": [CAPABILITIES[key]],
                    "csrf": True, "mutation": False, "publishable": True,
                    "payload_keys": ["idempotency_key", "items"]
                        if key == "README-03-02" else [],
                }
                self.assertEqual(synthetic_access(key, synthetic),
                                 "SYNTHETIC_ALLOWED_ONLY")
                self.assertEqual(synthetic_access(
                    key, {**synthetic, "resource_tenant": "tenant-synthetic-b"}
                ), "SYNTHETIC_DENY")
                self.assertEqual(synthetic_access(
                    key, {**synthetic, "resource_branch": "branch-synthetic-b"}
                ), "SYNTHETIC_DENY")
                if key == "README-03-02":
                    self.assertEqual(synthetic_access(
                        key, {**synthetic, "publishable": False}
                    ), "SYNTHETIC_DENY")
                    self.assertEqual(synthetic_access(
                        key, {**synthetic, "payload_keys": ["total_amount_minor"]}
                    ), "SYNTHETIC_DENY")
                else:
                    self.assertEqual(synthetic_access(
                        key, {**synthetic, "mutation": True, "csrf": False}
                    ), "SYNTHETIC_DENY")

    def test_falla_cerrado(self) -> None:
        """AC-02: missing/duplicate/promoted row, swapped link, lost test."""
        data = sources()
        raw = data[MATRIX]
        for key, (_, controller, _, controller_path, test_path, _, _) in CLAUSES.items():
            original = next(line for line in raw.splitlines()
                            if line.startswith(f"| {TICK}{key}{TICK} ·"))
            for altered in (
                raw.replace(original, "", 1),
                raw + "\n" + original,
                raw.replace(original, original.replace("NO_VERIFICABLE", "CUBIERTO"), 1),
                raw.replace(original, original.replace("| #1 |", "| #999 |"), 1),
            ):
                with self.subTest(row=key):
                    with self.assertRaisesRegex(ValueError, UNKNOWN):
                        matrix_rows(altered)
                    self.assertEqual(
                        classify(key, {**data, MATRIX: altered}), UNKNOWN)
            broken_readme = data[README].replace(
                f"[{controller}]", "[WrongController]", 1)
            self.assertNotEqual(broken_readme, data[README])
            self.assertEqual(
                classify(key, {**data, README: broken_readme}), UNKNOWN)
            for path in (controller_path, test_path):
                self.assertEqual(classify(key, {**data, path: ""}), UNKNOWN)
                self.assertEqual(classify(
                    key, {**data, path: data[path].replace(
                        CLAUSES[key][5][0] if path == controller_path
                        else CLAUSES[key][6][0], "", 1)}
                ), UNKNOWN)
        self.assertEqual(classify("README-03-XX", data), UNKNOWN)
        self.assertEqual(classify("README-03-02", {}), UNKNOWN)
        for bogus in (None, False, [], {"tenant": "fixture"}, {"grants": []}):
            self.assertEqual(synthetic_access("README-03-02", bogus), UNKNOWN)

    def test_sin_datos_reales(self) -> None:
        """AC-03: suite only reads tracked synthetic sources, no network/PHP."""
        file = ROOT / "tests/test_brechas_condor_39.py"
        tree = ast.parse(file.read_text(encoding="utf-8"))
        imports = {alias.name.split(".")[0] for node in ast.walk(tree)
                   if isinstance(node, ast.Import) for alias in node.names}
        imports |= {node.module.split(".")[0] for node in ast.walk(tree)
                    if isinstance(node, ast.ImportFrom) and node.module}
        self.assertLessEqual(imports, {"__future__", "ast", "pathlib", "unittest"})
        self.assertEqual(len(SOURCES), len(set(SOURCES)))
        self.assertTrue(all((ROOT / path).is_file() for path in SOURCES))
        self.assertTrue(all(path.startswith(("docs/", "tests/", "src/", "README"))
                            for path in SOURCES))
        self.assertNotIn("data:application", data := sources()[README])
        self.assertIn("no implica pagos habilitados", data)

    def test_estados_unknown_explicitos(self) -> None:
        """AC-04: all live/commercial/payment assertions remain UNKNOWN."""
        data = sources()
        for key in CLAUSES:
            self.assertEqual(classify(key, data), GAP)
            for claimed in (
                None, {}, True, "APPROVED", {"sha": "f" * 40, "ci": "success"},
                {"payment": "enabled", "amount_minor": 100, "currency": "COP"},
                {"provider": "live", "tenant": "tenant-synthetic-a"},
                {"status": "VALIDATED_IN_PRODUCTION", "pending": []},
                {"release": "latest", "badge": "green"},
            ):
                with self.subTest(row=key):
                    self.assertEqual(operational_verdict(key, claimed), UNKNOWN)
        self.assertEqual(classify("README-03-02", {**data, README: ""}), UNKNOWN)


if __name__ == "__main__":
    unittest.main()
