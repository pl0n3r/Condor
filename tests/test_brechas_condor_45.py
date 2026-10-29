#!/usr/bin/env python3
"""Condor #1240 / SPEC-BASE-4: offline tenant-consistency gap contract.

The committed Symfony HTTP scenario is independent existing code, not an HTTP
test executed here. Static markers plus a synthetic pure-Python example give
only LOCAL_PARTIAL evidence, NEVER merged-PR, production or access approval.
The coverage matrix remains NO_VERIFICABLE for independent reconciliation.
"""
from __future__ import annotations

import ast
from pathlib import Path
import unittest

ROOT = Path(__file__).resolve().parents[1]
KEY = "SPEC-BASE-4"
UNKNOWN = "UNKNOWN"
LOCAL_PARTIAL = "LOCAL_PARTIAL"
TICK = chr(96)

MATRIX = "docs/matriz-cobertura.md"
SPEC = "ESPECIFICACIONES.md"
CONTROLLER = "src/Http/Controller/CatalogController.php"
SCOPE = "src/Http/Controller/BranchApiSupport.php"
HTTP_TEST = "tests/test_catalog_http_isolation.py"
FILES = (MATRIX, SPEC, CONTROLLER, SCOPE, HTTP_TEST)


def read_sources() -> dict[str, str]:
    """Read tracked public UTF-8 files; never contact a service or database."""
    return {path: (ROOT / path).read_text(encoding="utf-8") for path in FILES}


def matrix_row(raw: object) -> tuple[str, ...]:
    if type(raw) is not str:
        raise ValueError("UNKNOWN: matrix missing")
    prefix = f"| {TICK}{KEY}{TICK} · "
    lines = [line for line in raw.splitlines() if line.startswith(prefix)]
    if len(lines) != 1:
        raise ValueError("UNKNOWN: missing or duplicated " + KEY)
    cells = tuple(cell.strip() for cell in lines[0].strip("|").split("|"))
    expected = f"{TICK}{KEY}{TICK} · {TICK}{SPEC}{TICK}"
    if (len(cells) != 5
            or not all(cells)
            or cells[0] != expected
            or cells[1] != "Definición funcional del producto"
            or cells[2] != TICK + "NO_VERIFICABLE" + TICK
            or "regresión de consistencia entre controlador, persistencia y permisos del tenant" not in cells[3]
            or "no se identificó tríada código+prueba+PR fusionado" not in cells[3]
            or "Documento o épico #1 no constituye aceptación ni go-live." not in cells[3]
            or "contraste-cd_ops.md" not in cells[3]
            or cells[4] != "#1"):
        raise ValueError("UNKNOWN: matrix evidence incomplete/altered " + KEY)
    return cells


def spec_section(raw: object) -> str | None:
    """The product definition is a bounded section, not any arbitrary match."""
    if type(raw) is not str:
        return None
    lines = raw.splitlines()
    title = "## 5. Definición funcional del producto"
    if lines.count(title) != 1:
        return None
    start = lines.index(title) + 1
    end = next((i for i in range(start, len(lines))
                if lines[i].startswith("## ")), len(lines))
    section = "\n".join(lines[start:end])
    required = (
        "### 5.2 Primer frente de producto confirmado",
        "**Presencia web**", "**E-commerce**",
        "**Administración del e-commerce**", "**Manejo de inventarios**",
        "**Administración de precios**",
        "**Condiciones o precios para mayoristas**",
        "**Descuentos por cantidad**",
        "multiempresa/multi-tenant",
    )
    return section if all(token in section for token in required) else None


def source_contract(data: object) -> str:
    """Offline evidence of guards and an existing HTTP regression, not execution."""
    if (type(data) is not dict or set(data) != set(FILES)
            or any(type(data[p]) is not str or not data[p] for p in FILES)):
        return UNKNOWN
    try:
        matrix_row(data[MATRIX])
    except ValueError:
        return UNKNOWN
    if spec_section(data[SPEC]) is None:
        return UNKNOWN
    controller = data[CONTROLLER]
    scope = data[SCOPE]
    integration = data[HTTP_TEST]
    controller_guards = (
        "use BranchApiSupport;",
        "$this->authorizedScope($branchId, 'catalog.view')",
        "$this->authorizedScope(\n            $branchId,\n            'catalog.create',",
        "'tenant' => $tenant",
        "$this->entityManager->persist($product)",
        "$this->entityManager->getRepository(Product::class)",
        "$this->requireCsrf($request);",
    )
    scope_guards = (
        "private function authorizedBranchScope(",
        "$this->authorization->require(",
        "$this->currentTenantForUser->resolve($user)",
        "'tenant' => $tenant",
        "throw new NotFoundHttpException(",
    )
    test_guards = (
        "PHP_HTTP_SCENARIO = r",
        "testTenantCannotReadCreateOrEditForeignCatalog",
        "$em->persist($entity);",
        "$em->flush();",
        "$em->refresh(",
        "self::assertResponseStatusCodeSame(404)",
        "self::assertResponseStatusCodeSame(403)",
        "getRepository(Product::class)->findOneBy(",
        "class CatalogoAislamientoHttpTests(unittest.TestCase)",
        "def test_acceptance(self)",
    )
    if (not all(s in controller for s in controller_guards)
            or not all(s in scope for s in scope_guards)
            or not all(s in integration for s in test_guards)):
        return UNKNOWN
    return LOCAL_PARTIAL


def synthetic_request(request: object, records: object) -> str:
    """Pure fake catalog: no authority over actual PHP/Doctrine/tenant permissions.

    The caller may inspect records after each operation to verify that rejection
    never mutates persistence. A local allow is explicitly named SYNTHETIC_ONLY.
    """
    if (type(request) is not dict or type(records) is not list
            or set(request) != {"actor_tenant", "resource_tenant",
                                "permission", "action", "csrf", "slug"}
            or any(type(request[p]) is not str or not request[p]
                   for p in ("actor_tenant", "resource_tenant", "permission",
                             "action", "slug"))
            or type(request["csrf"]) is not bool
            or any(type(r) is not dict or set(r) != {"tenant", "slug"}
                   or any(type(r[k]) is not str or not r[k]
                          for k in ("tenant", "slug"))
                   for r in records)):
        return UNKNOWN
    action = request["action"]
    if action not in ("read", "create"):
        return UNKNOWN
    if request["resource_tenant"] != request["actor_tenant"]:
        return "DENY_SYNTHETIC"
    required = "catalog.view" if action == "read" else "catalog.create"
    if request["permission"] != required:
        return "DENY_SYNTHETIC"
    if action == "create" and not request["csrf"]:
        return "DENY_SYNTHETIC"
    tenant, slug = request["resource_tenant"], request["slug"]
    if action == "read":
        return ("READ_SYNTHETIC_ONLY" if any(
            item["tenant"] == tenant and item["slug"] == slug
            for item in records) else "NOT_FOUND_SYNTHETIC")
    if any(item["tenant"] == tenant and item["slug"] == slug
           for item in records):
        return "DENY_SYNTHETIC"
    records.append({"tenant": tenant, "slug": slug})
    return "CREATED_SYNTHETIC_ONLY"


def operational_state(_evidence: object) -> str:
    """Never infer deployment, merged PR or real-tenant authorization."""
    return UNKNOWN


class BrechasCondor45Tests(unittest.TestCase):
    def test_contrato_principal(self) -> None:
        files = read_sources()
        self.assertEqual(TICK + "NO_VERIFICABLE" + TICK,
                         matrix_row(files[MATRIX])[2])
        self.assertIsNotNone(spec_section(files[SPEC]))
        self.assertEqual(LOCAL_PARTIAL, source_contract(files))
        self.assertEqual(UNKNOWN, operational_state(files))

        records = [{"tenant": "fixture-b", "slug": "fixture-product"}]
        base = dict(actor_tenant="fixture-a", resource_tenant="fixture-a",
                    permission="catalog.create", action="create",
                    csrf=True, slug="fixture-new")
        self.assertEqual("CREATED_SYNTHETIC_ONLY", synthetic_request(base, records))
        self.assertEqual(2, len(records))
        self.assertEqual("READ_SYNTHETIC_ONLY", synthetic_request(
            {**base, "permission": "catalog.view", "action": "read"}, records))
        before = list(records)
        self.assertEqual("DENY_SYNTHETIC", synthetic_request(
            {**base, "resource_tenant": "fixture-b"}, records))
        self.assertEqual(before, records)
        self.assertEqual("NOT_FOUND_SYNTHETIC", synthetic_request(
            {**base, "permission": "catalog.view", "action": "read",
             "slug": "fixture-product"}, records))
        self.assertEqual(before, records)

    def test_falla_cerrado(self) -> None:
        data = read_sources()
        original = data[MATRIX]
        line = next(s for s in original.splitlines()
                    if s.startswith(f"| {TICK}{KEY}{TICK} · "))
        invalid = (
            None, "", original.replace(line, "", 1),
            original + "\n" + line,
            original.replace(line, line.replace("NO_VERIFICABLE", "CUBIERTO"), 1),
            original.replace(line, line.replace(" | #1 |", " |  |"), 1),
            original.replace(line, line.replace(
                "controlador, persistencia y permisos del tenant", "controlador"), 1),
        )
        for broken in invalid:
            with self.subTest(matrix=str(broken)[:36]):
                with self.assertRaisesRegex(ValueError, UNKNOWN):
                    matrix_row(broken)
                self.assertEqual(UNKNOWN, source_contract({**data, MATRIX: broken}))
        mutations = (
            (CONTROLLER, "$this->authorizedScope(\n            $branchId,\n            'catalog.create',"),
            (CONTROLLER, "$this->entityManager->persist($product)"),
            (SCOPE, "$this->authorization->require("),
            (SCOPE, "'tenant' => $tenant"),
            (HTTP_TEST, "self::assertResponseStatusCodeSame(403)"),
            (SPEC, "multiempresa/multi-tenant"),
        )
        for path, marker in mutations:
            with self.subTest(file=path, guard=marker):
                self.assertIn(marker, data[path])
                changed = {**data, path: data[path].replace(marker, "", 1)}
                self.assertEqual(UNKNOWN, source_contract(changed))
        for absent in FILES:
            with self.subTest(absent=absent):
                incomplete = dict(data)
                del incomplete[absent]
                self.assertEqual(UNKNOWN, source_contract(incomplete))
        self.assertEqual(UNKNOWN, source_contract(None))

        base = dict(actor_tenant="fixture-a", resource_tenant="fixture-a",
                    permission="catalog.create", action="create",
                    csrf=True, slug="fixture-new")
        records: list[dict[str, str]] = []
        for changed in (
            {**base, "permission": "catalog.view"},
            {**base, "csrf": False},
            {**base, "resource_tenant": "fixture-b"},
        ):
            self.assertEqual("DENY_SYNTHETIC", synthetic_request(changed, records))
            self.assertEqual([], records)
        for invalid_request in (None, {}, {**base, "tenant": "fixture-b"},
                                {**base, "csrf": "true"},
                                {**base, "action": "delete"}):
            self.assertEqual(UNKNOWN, synthetic_request(invalid_request, records))
            self.assertEqual([], records)

    def test_sin_datos_reales(self) -> None:
        self.assertEqual(set(FILES), set(read_sources()))
        self.assertTrue(all((ROOT / p).is_file() for p in FILES))
        self.assertTrue(all(not Path(p).is_absolute()
                            and ".." not in Path(p).parts for p in FILES))
        local_file = (ROOT / "tests/test_brechas_condor_45.py").read_text(
            encoding="utf-8")
        parsed = ast.parse(local_file)
        imports = {alias.name for node in ast.walk(parsed)
                   if isinstance(node, ast.Import) for alias in node.names}
        imports |= {node.module or "" for node in ast.walk(parsed)
                    if isinstance(node, ast.ImportFrom)}
        for forbidden in ("requests", "httpx", "urllib", "socket", "subprocess",
                          "mysql", "pymysql", "psycopg2"):
            self.assertFalse(any(
                item == forbidden or item.startswith(forbidden + ".")
                for item in imports))
        self.assertNotIn("BEGIN " + "PRIVATE KEY", local_file)
        self.assertNotIn("DATABASE" + "_URL=", local_file)

    def test_estados_unknown_explicitos(self) -> None:
        files = read_sources()
        row = matrix_row(files[MATRIX])
        self.assertEqual("Definición funcional del producto", row[1])
        self.assertEqual(TICK + "NO_VERIFICABLE" + TICK, row[2])
        self.assertIn("no se identificó tríada código+prueba+PR fusionado", row[3])
        self.assertEqual(LOCAL_PARTIAL, source_contract(files))
        for fake in (None, "", {"ci": "success"}, {"tenant": "fixture-a"},
                     {"merged": True, "code": "passed"},
                     {"production": "GREEN", "approved": True}):
            with self.subTest(evidence=repr(fake)):
                self.assertEqual(UNKNOWN, operational_state(fake))
        self.assertEqual(UNKNOWN, source_contract(
            {**files, SPEC: "## 5. Definición funcional del producto"}))
        self.assertEqual(UNKNOWN, source_contract(
            {**files, HTTP_TEST: ""}))


if __name__ == "__main__":
    unittest.main()
