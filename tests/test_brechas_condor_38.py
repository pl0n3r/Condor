#!/usr/bin/env python3
"""Condor #1233: five README gap contracts, synthetic and fail-closed.

A README link, local HTTP regression, or a fake issue is not evidence of a
merged PR for that row, live operation, permissions or production readiness.
"""
from __future__ import annotations

import ast
from pathlib import Path
import re
import unittest
from urllib.parse import parse_qs, urlsplit

ROOT = Path(__file__).resolve().parents[1]
MATRIX = "docs/matriz-cobertura.md"
README = "README.md"
CATALOG = "src/Http/Controller/CatalogController.php"
PRICING = "src/Http/Controller/PricingController.php"
CATALOG_TEST = "tests/test_catalog_http_isolation.py"
PRICING_TEST = "tests/test_pricing_http_negatives.py"
SOURCES = (MATRIX, README, CATALOG, PRICING, CATALOG_TEST, PRICING_TEST)
UNKNOWN = "UNKNOWN"
GAP = "BRECHA_DOCUMENTADA"
TICK = chr(96)

CLAUSES = {
    "README-02-01": "Work Queue — NOW: Issues reservados y trabajos en revisión.",
    "README-02-02": "Work Queue — NEXT: Issues críticos disponibles, respetando reservas y dependencias.",
    "README-02-03": "Work Queue — LATER: Roadmap canónico #1 y módulos operativos.",
    "README-02-04": "Work Queue — BLOCKED: Issues bloqueados; cada Issue conserva la causa y condición de desbloqueo.",
    "README-03-01": "Qué hace el producto — Catálogo, variantes y precios: CatalogController y PricingController.",
}
EXPECTED_QUERIES = {
    "NOW": (
        'is:issue is:open label:"estado: reservado"',
        'is:issue is:open label:"estado: en revisión"',
    ),
    "NEXT": ('is:issue is:open label:"prioridad: crítica" label:"estado: disponible"',),
    "BLOCKED": ('is:issue is:open label:"estado: bloqueado"',),
}
DOC_LINKS = (
    "https://github.com/pl0n3r/Condor/issues/1",
    "https://github.com/pl0n3r/Condor/blob/main/docs/modulos-operativos.md",
)
CONTROLLER_PROOFS = {
    CATALOG: (
        "class CatalogController",
        "authorizedScope(",
        "'catalog.view'",
        "'catalog.create'",
        "ProductVariant::class",
        "'tenant' => $tenant",
        "$this->requireCsrf($request)",
    ),
    PRICING: (
        "class PricingController",
        "authorizedBranchScope(",
        "PriceList::class",
        "public function effective(",
        "'tenant' => $tenant",
        "$this->requireCsrf($request)",
    ),
    CATALOG_TEST: (
        "$tenantB = new Tenant(",
        "$variantB",
        "self::assertResponseStatusCodeSame(404)",
    ),
    PRICING_TEST: (
        "$tenantB = new Tenant(",
        "$listB",
        "self::assertResponseStatusCodeSame(404)",
        "self::assertResponseStatusCodeSame(422)",
        "self::assertResponseStatusCodeSame(403)",
    ),
}


def sources() -> dict[str, str]:
    """Only read tracked, local UTF-8 files; no GitHub API or database."""
    return {path: (ROOT / path).read_text(encoding="utf-8") for path in SOURCES}


def matrix_rows(raw: object) -> dict[str, tuple[str, ...]]:
    """Never silently accept duplicate, promoted, missing or altered rows."""
    if type(raw) is not str:
        raise ValueError("UNKNOWN: matrix missing")
    result: dict[str, tuple[str, ...]] = {}
    for key, summary in CLAUSES.items():
        prefix = f"| {TICK}{key}{TICK} · "
        matches = [line for line in raw.splitlines() if line.startswith(prefix)]
        if len(matches) != 1:
            raise ValueError("UNKNOWN: missing/duplicate " + key)
        cells = tuple(x.strip() for x in matches[0].strip("|").split("|"))
        if (
            len(cells) != 5
            or cells[0] != f"{TICK}{key}{TICK} · {TICK}{README}{TICK}"
            or cells[1] != summary
            or cells[2] != f"{TICK}NO_VERIFICABLE{TICK}"
            or "Falta prueba independiente de la obligación" not in cells[3]
            or "PR fusionado vinculado a esa obligación específica" not in cells[3]
            or f"({key})" not in cells[3]
            or "contraste-cd_ops.md" not in cells[3]
            or cells[4] != "#1"
        ):
            raise ValueError("UNKNOWN: malformed " + key)
        result[key] = cells
    return result


def section(raw: object, title: str) -> str | None:
    if type(raw) is not str:
        return None
    lines = raw.splitlines()
    heading = "## " + title
    if lines.count(heading) != 1:
        return None
    start = lines.index(heading) + 1
    end = next((i for i in range(start, len(lines))
                if lines[i].startswith("## ")), len(lines))
    return "\n".join(lines[start:end])


def links(section_text: str, label: str) -> tuple[str, ...] | None:
    records = [line for line in section_text.splitlines()
               if line.startswith(f"- **{label}:** ")]
    if len(records) != 1:
        return None
    return tuple(re.findall(r"\]\(([^)]+)\)", records[0]))


def issue_search_query(url: object) -> str | None:
    """Allow GitHub issue search only; malformed query is not a candidate."""
    if type(url) is not str:
        return None
    parsed = urlsplit(url)
    if (parsed.scheme != "https" or parsed.netloc != "github.com"
            or parsed.path != "/pl0n3r/Condor/issues"
            or parsed.fragment or not parsed.query):
        return None
    query = parse_qs(parsed.query, keep_blank_values=True)
    if set(query) != {"q"} or len(query["q"]) != 1:
        return None
    return query["q"][0]


def documented_queue(readme: object, key: str) -> bool:
    work = section(readme, "Work Queue")
    if not work or "no son un segundo planificador" not in work:
        return False
    for label, expected in EXPECTED_QUERIES.items():
        found = links(work, label)
        if found is None or tuple(issue_search_query(u) for u in found) != expected:
            return False
    if links(work, "LATER") != DOC_LINKS:
        return False
    if "respetando reservas y dependencias" not in work:
        return False
    if "cada Issue conserva la causa y condición de desbloqueo" not in work:
        return False
    return key in CLAUSES and key.startswith("README-02-")


def synthetic_query(issue: object, query: object) -> str:
    """Contract example only; never treats GitHub search as V3 authority."""
    if type(issue) is not dict or type(query) is not str:
        return UNKNOWN
    expected = {value for selectors in EXPECTED_QUERIES.values()
                for value in selectors}
    if query not in expected:
        return UNKNOWN
    if (set(issue) != {"kind", "state", "labels", "depends_on", "reason", "unblock"}
            or issue["kind"] != "issue"
            or issue["state"] not in ("open", "closed")
            or type(issue["labels"]) is not list
            or not all(type(x) is str and x for x in issue["labels"])
            or len(issue["labels"]) != len(set(issue["labels"]))
            or type(issue["depends_on"]) is not list
            or not all(type(x) is int and x > 0 for x in issue["depends_on"])
            or type(issue["reason"]) is not str
            or type(issue["unblock"]) is not str):
        return UNKNOWN
    labels = re.findall(r'label:"([^"]+)"', query)
    if not query.startswith("is:issue is:open "):
        return UNKNOWN
    if issue["state"] != "open" or any(label not in issue["labels"] for label in labels):
        return "NO_MATCH"
    return "SYNTHETIC_MATCH_ONLY"


def dispatch_candidate(issue: object, query: object) -> str:
    """A search hit cannot bypass dependencies, reservation or missing state."""
    selected = synthetic_query(issue, query)
    if selected != "SYNTHETIC_MATCH_ONLY":
        return UNKNOWN if selected == UNKNOWN else "DENY"
    if query != EXPECTED_QUERIES["NEXT"][0]:
        return UNKNOWN
    if any(issue["depends_on"]) or "estado: reservado" in issue["labels"]:
        return "DENY"
    return "SYNTHETIC_ELIGIBLE_ONLY"


def blocked_record(issue: object, query: object) -> str:
    if (synthetic_query(issue, query) != "SYNTHETIC_MATCH_ONLY"
            or query != EXPECTED_QUERIES["BLOCKED"][0]):
        return UNKNOWN
    if not all(type(issue[k]) is str and issue[k].strip()
               for k in ("reason", "unblock")):
        return UNKNOWN
    return "SYNTHETIC_BLOCKED_ONLY"


def documented_catalog(data: dict[str, str]) -> bool:
    product = section(data[README], "Qué hace el producto")
    if product is None:
        return False
    items = [line for line in product.splitlines() if line.startswith("- **")]
    if len(items) != 8:
        return False
    first = items[0]
    if not first.startswith("- **Catálogo, variantes y precios:**"):
        return False
    if (f"https://github.com/pl0n3r/Condor/blob/main/{CATALOG}" not in first
            or f"https://github.com/pl0n3r/Condor/blob/main/{PRICING}" not in first
            or "«presente en código» **no** significa" not in product):
        return False
    return all(all(marker in data[path] for marker in markers)
               for path, markers in CONTROLLER_PROOFS.items())


def classify(key: object, data: object) -> str:
    """At best a documented local gap; no PR/readiness promotion."""
    if type(key) is not str or key not in CLAUSES or type(data) is not dict:
        return UNKNOWN
    if set(data) != set(SOURCES) or any(type(data[p]) is not str or not data[p]
                                       for p in SOURCES):
        return UNKNOWN
    try:
        matrix_rows(data[MATRIX])
    except ValueError:
        return UNKNOWN
    if key.startswith("README-02-"):
        valid = documented_queue(data[README], key)
    else:
        valid = documented_catalog(data)
    return GAP if valid else UNKNOWN


def operational_state(_key: object, _evidence: object = None) -> str:
    """A synthetic query, source file, or user-provided status is not CI/live."""
    return UNKNOWN


class BrechasCondor38Tests(unittest.TestCase):
    def test_contrato_principal(self) -> None:
        data = sources()
        rows = matrix_rows(data[MATRIX])
        self.assertEqual(set(CLAUSES), set(rows))
        self.assertEqual(5, len(rows))
        for key in CLAUSES:
            with self.subTest(gap=key):
                self.assertEqual(GAP, classify(key, data))
                self.assertEqual(UNKNOWN, operational_state(key, data))
        work = section(data[README], "Work Queue")
        self.assertIsNotNone(work)
        assert work is not None
        synthetic = {
            "kind": "issue", "state": "open", "labels": ["estado: reservado"],
            "depends_on": [], "reason": "", "unblock": "",
        }
        self.assertEqual("SYNTHETIC_MATCH_ONLY",
                         synthetic_query(synthetic, EXPECTED_QUERIES["NOW"][0]))
        self.assertEqual("NO_MATCH",
                         synthetic_query(synthetic, EXPECTED_QUERIES["NOW"][1]))
        synthetic["labels"] = ["prioridad: crítica", "estado: disponible"]
        self.assertEqual("SYNTHETIC_ELIGIBLE_ONLY",
                         dispatch_candidate(synthetic, EXPECTED_QUERIES["NEXT"][0]))
        synthetic["depends_on"] = [9999]
        self.assertEqual("DENY", dispatch_candidate(
            synthetic, EXPECTED_QUERIES["NEXT"][0]))
        synthetic["labels"] = ["estado: bloqueado"]
        synthetic["reason"] = "fixture: otra hoja"
        synthetic["unblock"] = "fixture: validar dependencia"
        self.assertEqual("SYNTHETIC_BLOCKED_ONLY",
                         blocked_record(synthetic, EXPECTED_QUERIES["BLOCKED"][0]))
        self.assertTrue(documented_catalog(data))

    def test_falla_cerrado(self) -> None:
        data = sources()
        raw = data[MATRIX]
        for key in CLAUSES:
            line = next(line for line in raw.splitlines()
                        if line.startswith(f"| {TICK}{key}{TICK} ·"))
            for bad in (
                raw.replace(line, "", 1),
                raw + "\n" + line,
                raw.replace(line, line.replace("NO_VERIFICABLE", "CUBIERTO"), 1),
                raw.replace(line, line.replace("| #1 |", "| #1234 |"), 1),
            ):
                with self.subTest(gap=key):
                    with self.assertRaisesRegex(ValueError, UNKNOWN):
                        matrix_rows(bad)
                    altered = dict(data, **{MATRIX: bad})
                    self.assertEqual(UNKNOWN, classify(key, altered))
        for key, marker in (
            ("README-02-01", "is%3Aopen+label%3A%22estado%3A+reservado%22"),
            ("README-02-02", "label%3A%22prioridad%3A+cr%C3%ADtica%22"),
            ("README-02-03", "docs/modulos-operativos.md"),
            ("README-02-04", "estado%3A+bloqueado%22"),
            ("README-03-01", "CatalogController.php"),
        ):
            changed = dict(data)
            self.assertIn(marker, changed[README])
            changed[README] = changed[README].replace(marker, "invalid-fixture", 1)
            self.assertEqual(UNKNOWN, classify(key, changed))
        changed = dict(data)
        changed[CATALOG] = data[CATALOG].replace("'tenant' => $tenant", "unknown", 1)
        self.assertEqual(UNKNOWN, classify("README-03-01", changed))
        changed = dict(data)
        changed[PRICING_TEST] = data[PRICING_TEST].replace(
            "self::assertResponseStatusCodeSame(422)", "unproven", 1)
        self.assertEqual(UNKNOWN, classify("README-03-01", changed))
        self.assertEqual(UNKNOWN, classify("README-99-99", data))
        self.assertEqual(UNKNOWN, classify("README-02-01", {}))
        self.assertIsNone(issue_search_query("http://example.com/issue?q=is:issue"))
        self.assertEqual(UNKNOWN, synthetic_query(None, EXPECTED_QUERIES["NEXT"][0]))

    def test_sin_datos_reales(self) -> None:
        code = (ROOT / "tests/test_brechas_condor_38.py").read_text(encoding="utf-8")
        tree = ast.parse(code)
        imported: set[str] = set()
        for node in ast.walk(tree):
            if isinstance(node, ast.Import):
                imported.update(alias.name.split(".")[0] for alias in node.names)
            elif isinstance(node, ast.ImportFrom):
                imported.add((node.module or "").split(".")[0])
        self.assertLessEqual(imported, {
            "__future__", "ast", "pathlib", "re", "unittest", "urllib",
        })
        self.assertNotIn("https://" + "api.github.com", code)
        self.assertEqual(set(SOURCES), set(sources()))
        self.assertIn("testInvalidPricesCurrenciesAndForeignListsPreservePersistedData",
                      sources()[PRICING_TEST])
        self.assertIn("$tenantB = new Tenant(", sources()[CATALOG_TEST])

    def test_estados_unknown_explicitos(self) -> None:
        data = sources()
        for key in CLAUSES:
            with self.subTest(gap=key):
                self.assertEqual(GAP, classify(key, data))
                for claim in (None, {}, {"CI": "success"}, {"health": "GREEN"},
                              {"approved": True, "sha": "f" * 40},
                              {"real_customers": True}, {"deployed": True}):
                    self.assertEqual(UNKNOWN, operational_state(key, claim))
        query = EXPECTED_QUERIES["BLOCKED"][0]
        blocked = {
            "kind": "issue", "state": "open", "labels": ["estado: bloqueado"],
            "depends_on": [99], "reason": "", "unblock": "",
        }
        self.assertEqual(UNKNOWN, blocked_record(blocked, query))
        blocked["reason"] = "synthetic blocker"
        self.assertEqual(UNKNOWN, blocked_record(blocked, query))
        blocked["unblock"] = "synthetic resolution"
        self.assertEqual("SYNTHETIC_BLOCKED_ONLY", blocked_record(blocked, query))
        blocked["state"] = "closed"
        self.assertEqual(UNKNOWN, blocked_record(blocked, query))


if __name__ == "__main__":
    unittest.main()
