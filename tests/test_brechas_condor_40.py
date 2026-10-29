#!/usr/bin/env python3
"""Condor #1235: five README gap contracts with synthetic, fail-closed evidence.

Static source contracts are not authenticated runtime, deployed infrastructure,
approved tenant access, live browser observations, or a merged PR. The coverage
matrix belongs to another issue; this test never writes to it.
"""
from __future__ import annotations

import json
from pathlib import Path
import unittest

ROOT = Path(__file__).resolve().parents[1]
UNKNOWN = "UNKNOWN"
DOCUMENTED = "BRECHA_DOCUMENTADA"
TICK = chr(96)

MATRIX = "docs/matriz-cobertura.md"
README = "README.md"
FILES = (
    MATRIX, README,
    "src/Http/Controller/BranchApiSupport.php",
    "src/Http/Controller/CatalogController.php",
    "tests/test_catalog_http_isolation.py",
    "tests/test_pricing_http_negatives.py",
    "composer.json", "config/packages/doctrine.yaml",
    "package.json", "tsconfig.json", "vite.config.ts",
    "frontend/admin/main.tsx",
    "templates/base.html.twig", "templates/home/index.html.twig",
    ".github/workflows/ci.yml", "phpstan.neon.dist", "rector.php",
    "playwright.config.mjs",
)

CLAUSES = {
    "README-04-01": ("Arquitectura en 60 segundos",
                      "El backend valida permisos, sede y tenant, no la interfaz."),
    "README-05-01": ("Stack e infraestructura",
                      "Backend y datos: PHP 8.5, Symfony 7.4 LTS, Doctrine, MariaDB/MySQL"),
    "README-05-02": ("Stack e infraestructura",
                      "Admin: React, TypeScript y Vite"),
    "README-05-03": ("Stack e infraestructura",
                      "Público: HTML SSR/Twig para SEO y experiencia móvil"),
    "README-05-05": ("Stack e infraestructura",
                      "CI / pruebas:"),
}


def local_sources() -> dict[str, str]:
    """Load repository files only; no network, subprocesses, credentials or DB."""
    return {name: (ROOT / name).read_text(encoding="utf-8") for name in FILES}


def matrix_rows(raw: object) -> dict[str, tuple[str, ...]]:
    if type(raw) is not str:
        raise ValueError("UNKNOWN: missing coverage matrix")
    rows = {}
    for key in CLAUSES:
        prefix = "| " + TICK + key + TICK + " · " + TICK + README + TICK + " |"
        matches = [line for line in raw.splitlines() if line.startswith(prefix)]
        if len(matches) != 1:
            raise ValueError("UNKNOWN: missing or duplicate " + key)
        columns = tuple(c.strip() for c in matches[0].strip("|").split("|"))
        if (len(columns) != 5 or columns[0] !=
                TICK + key + TICK + " · " + TICK + README + TICK or
                columns[2] != TICK + "NO_VERIFICABLE" + TICK or
                "Falta prueba independiente de la obligación" not in columns[3] or
                "PR fusionado vinculado a esa obligación específica" not in columns[3] or
                "(" + key + ")" not in columns[3] or
                "contraste-cd_ops.md" not in columns[3] or
                columns[4] != "#1" or
                CLAUSES[key][1] not in columns[1]):
            raise ValueError("UNKNOWN: malformed matrix row " + key)
        rows[key] = columns
    return rows


def section(raw: object, heading: str) -> str | None:
    if type(raw) is not str:
        return None
    lines = raw.splitlines()
    title = "## " + heading
    if lines.count(title) != 1:
        return None
    start = lines.index(title) + 1
    end = next((n for n in range(start, len(lines))
                if lines[n].startswith("## ")), len(lines))
    return "\n".join(lines[start:end])


def parsed_json(raw: object) -> dict | None:
    if type(raw) is not str:
        return None
    try:
        value = json.loads(raw)
    except (ValueError, TypeError):
        return None
    return value if type(value) is dict else None


def source_contract(key: object, sources: object) -> str:
    """At most identify a local, testable boundary; never certify operations."""
    if type(key) is not str or key not in CLAUSES or type(sources) is not dict:
        return UNKNOWN
    if set(sources) != set(FILES) or any(type(sources[name]) is not str
                                       or not sources[name] for name in FILES):
        return UNKNOWN
    try:
        matrix_rows(sources[MATRIX])
    except ValueError:
        return UNKNOWN
    heading, phrase = CLAUSES[key]
    readme_part = section(sources[README], heading)
    if readme_part is None or phrase not in readme_part:
        return UNKNOWN
    if key == "README-04-01":
        guard = sources["src/Http/Controller/BranchApiSupport.php"]
        catalog = sources["src/Http/Controller/CatalogController.php"]
        catalog_test = sources["tests/test_catalog_http_isolation.py"]
        pricing_test = sources["tests/test_pricing_http_negatives.py"]
        valid = (
            "private function authorizedBranchScope(" in guard and
            "$this->authorization->require(" in guard and
            "$this->currentTenantForUser->resolve($user)" in guard and
            "'tenant' => $tenant" in guard and
            "$this->authorizedScope(" in catalog and
            "['tenant' => $tenant" in catalog and
            "self::assertResponseStatusCodeSame(404)" in catalog_test and
            "self::assertResponseStatusCodeSame(404)" in pricing_test and
            "self::assertResponseStatusCodeSame(403)" in pricing_test
        )
    elif key == "README-05-01":
        composer = parsed_json(sources["composer.json"])
        if composer is None or type(composer.get("require")) is not dict:
            return UNKNOWN
        required = composer["require"]
        valid = (
            required.get("php") == "^8.5" and
            required.get("symfony/framework-bundle") == "7.4.*" and
            required.get("doctrine/orm", "").startswith("^3.") and
            required.get("doctrine/dbal", "").startswith("^4.") and
            "url: '%env(resolve:DATABASE_URL)%'" in
            sources["config/packages/doctrine.yaml"]
        )
    elif key == "README-05-02":
        package = parsed_json(sources["package.json"])
        tsconfig = parsed_json(sources["tsconfig.json"])
        if package is None or tsconfig is None:
            return UNKNOWN
        deps = package.get("dependencies")
        dev = package.get("devDependencies")
        scripts = package.get("scripts")
        opts = tsconfig.get("compilerOptions")
        if not all(type(x) is dict for x in (deps, dev, scripts, opts)):
            return UNKNOWN
        valid = (
            "react" in deps and "react-dom" in deps and
            "typescript" in dev and "vite" in dev and
            scripts.get("build:admin") == "vite build" and
            opts.get("jsx") == "react-jsx" and
            "plugins: [react()]" in sources["vite.config.ts"] and
            "frontend/admin/main.tsx" in sources["vite.config.ts"] and
            "createRoot(" in sources["frontend/admin/main.tsx"]
        )
    elif key == "README-05-03":
        base = sources["templates/base.html.twig"]
        home = sources["templates/home/index.html.twig"]
        valid = (
            '<html lang="es-CO">' in base and
            'name="viewport"' in base and
            "{% block body %}" in base and
            "{% extends 'base.html.twig' %}" in home and
            'rel="canonical"' in home and
            'name="description"' in home
        )
    else:
        package = parsed_json(sources["package.json"])
        if package is None or type(package.get("scripts")) is not dict:
            return UNKNOWN
        scripts = package["scripts"]
        workflow = sources[".github/workflows/ci.yml"]
        valid = (
            "name: CI Condor" in workflow and
            "name: CI Factory v1" in workflow and
            "name: Validar" in workflow and
            "level: 8" in sources["phpstan.neon.dist"] and
            "RectorConfig::configure()" in sources["rector.php"] and
            scripts.get("typecheck") == "tsc --noEmit" and
            "playwright test --project=chromium" in scripts.get("test:e2e", "") and
            "projects: [" in sources["playwright.config.mjs"] and
            "chromium" in sources["playwright.config.mjs"] and
            "webkit" in sources["playwright.config.mjs"]
        )
    return DOCUMENTED if valid else UNKNOWN


def operational_state(_key: object, _evidence: object = None) -> str:
    """Documentation, source files and synthetic positives never confer live authority."""
    return UNKNOWN


class BrechasCondor40Tests(unittest.TestCase):
    def test_contrato_principal(self) -> None:
        data = local_sources()
        rows = matrix_rows(data[MATRIX])
        self.assertEqual(set(CLAUSES), set(rows))
        for key, (heading, phrase) in CLAUSES.items():
            with self.subTest(requirement=key):
                block = section(data[README], heading)
                self.assertIsNotNone(block)
                self.assertIn(phrase, block)
                self.assertEqual(DOCUMENTED, source_contract(key, data))
                self.assertEqual(UNKNOWN, operational_state(key, data))
        self.assertEqual(len(rows), 5)

    def test_falla_cerrado(self) -> None:
        data = local_sources()
        raw = data[MATRIX]
        for key in CLAUSES:
            prefix = "| " + TICK + key + TICK + " · "
            row = next(x for x in raw.splitlines() if x.startswith(prefix))
            for bad in (
                raw.replace(row, "", 1),
                raw + "\n" + row,
                raw.replace(row, row.replace("NO_VERIFICABLE", "CUBIERTO"), 1),
                raw.replace(row, row.replace("| #1 |", "| #999 |"), 1),
            ):
                with self.subTest(key=key):
                    with self.assertRaisesRegex(ValueError, UNKNOWN):
                        matrix_rows(bad)
                    self.assertEqual(UNKNOWN, source_contract(
                        key, {**data, MATRIX: bad}))
        tamper = (
            ("README-04-01", "src/Http/Controller/BranchApiSupport.php",
             "private function authorizedBranchScope("),
            ("README-05-01", "composer.json", '"php": "^8.5"'),
            ("README-05-02", "vite.config.ts", "plugins: [react()]"),
            ("README-05-03", "templates/home/index.html.twig",
             'rel="canonical"'),
            ("README-05-05", ".github/workflows/ci.yml", "name: CI Factory v1"),
        )
        for key, filename, marker in tamper:
            with self.subTest(mutation=key):
                self.assertIn(marker, data[filename])
                changed = dict(data)
                changed[filename] = data[filename].replace(marker, "fixture-unknown", 1)
                self.assertEqual(UNKNOWN, source_contract(key, changed))
        self.assertEqual(UNKNOWN, source_contract("README-99-99", data))
        self.assertEqual(UNKNOWN, source_contract("README-05-01", {}))
        self.assertEqual(UNKNOWN, source_contract(None, data))
        self.assertIsNone(parsed_json("not valid json"))

    def test_sin_datos_reales(self) -> None:
        """Only local tracked files and synthetic input; no remote effects."""
        data = local_sources()
        self.assertEqual(set(FILES), set(data))
        for value in (
            None, {}, {"status": "success"}, {"production": "GREEN"},
            {"tenant": "fixture-a", "approved": True},
            {"github_pr_merged": True, "ci": "success"},
        ):
            for key in CLAUSES:
                with self.subTest(key=key, value=repr(value)):
                    self.assertEqual(UNKNOWN, operational_state(key, value))
        self.assertFalse(any(key.lower().startswith("http") for key in FILES))
        self.assertTrue(all((ROOT / name).is_file() for name in FILES))

    def test_estados_unknown_explicitos(self) -> None:
        data = local_sources()
        rows = matrix_rows(data[MATRIX])
        for key, cells in rows.items():
            with self.subTest(gap=key):
                self.assertEqual(TICK + "NO_VERIFICABLE" + TICK, cells[2])
                self.assertIn("Falta prueba independiente", cells[3])
                self.assertEqual(DOCUMENTED, source_contract(key, data))
                self.assertEqual(UNKNOWN, operational_state(
                    key, {"scope": "offline", "passed": True}))
                self.assertEqual(UNKNOWN, source_contract(
                    key, {**data, README: data[README].replace(
                        CLAUSES[key][1], "missing-synthetic-contract", 1)}))
        self.assertEqual(set(CLAUSES), set(rows))


if __name__ == "__main__":
    unittest.main()
