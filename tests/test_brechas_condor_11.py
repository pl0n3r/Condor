#!/usr/bin/env python3
"""Condor #1206, D-041-S04..S08: deliberately scoped offline contracts.

Static source checks and synthetic negative cases establish only local evidence.
They do not certify the full design system, actual permission enforcement,
360px browser rendering, WCAG 2.2 AA, all interface states, or production.
The coverage matrix remains NO_VERIFICABLE pending independent reconciliation.
"""
from __future__ import annotations

import re
import unittest
from pathlib import Path

ROOT = Path(__file__).resolve().parents[1]
MATRIX = ROOT / "docs/matriz-cobertura.md"
SPEC = ROOT / "ESPECIFICACIONES.md"
SHELL = ROOT / "frontend/admin/AdminShell.tsx"
APP = ROOT / "frontend/admin/AdminApp.tsx"
PLATFORM = ROOT / "frontend/admin/PlatformOwnerApp.tsx"
OVERVIEW = ROOT / "frontend/admin/OverviewGrid.tsx"
CSS = ROOT / "public/app.css"
ADMIN_CSS = ROOT / "frontend/admin/admin.css"
BACKEND_TEST = ROOT / "tests/Unit/PlatformOwnerNavigationTest.php"

GAPS = {
    "D-041-S04": "Componentes reutilizables mínimos",
    "D-041-S05": "Navegación administrativa",
    "D-041-S06": "Responsive",
    "D-041-S07": "Accesibilidad",
    "D-041-S08": "Estados de interfaz",
}
UNKNOWN = "UNKNOWN"
LOCAL_PARTIAL = "LOCAL_PARTIAL"


def coverage_rows(document: str) -> dict[str, tuple[str, ...]]:
    """Never silently omit a damaged or duplicate row bearing a target ID."""
    rows: dict[str, tuple[str, ...]] = {}
    for line in document.splitlines():
        match = re.match(r"^\|\s*" + chr(96) + r"(D-041-S0[4-8])" +
                         chr(96) + r"(?=\W|$)", line)
        if not match:
            continue
        key = match.group(1)
        columns = tuple(v.strip() for v in line.strip().strip("|").split("|"))
        first_expected = chr(96) + key + chr(96) + " · " + chr(96) + (
            "ESPECIFICACIONES.md") + chr(96)
        if (key in rows or len(columns) != 5 or not all(columns) or
                columns[0] != first_expected or columns[1] != GAPS[key]):
            raise ValueError("UNKNOWN: duplicate or malformed " + key)
        rows[key] = columns
    if set(rows) != set(GAPS):
        raise ValueError("UNKNOWN: incomplete D-041 inventory")
    return rows


def css_body(document: str, opening: str) -> str:
    """Extract a CSS block by balancing braces, not a greedy regex."""
    start = document.find(opening)
    if start < 0:
        raise ValueError("UNKNOWN: missing CSS block " + opening)
    curly = document.find("{", start + len(opening))
    if curly < 0:
        raise ValueError("UNKNOWN: missing CSS opening brace")
    depth = 1
    for position in range(curly + 1, len(document)):
        if document[position] == "{":
            depth += 1
        if document[position] == "}":
            depth -= 1
            if depth == 0:
                return document[curly + 1:position]
    raise ValueError("UNKNOWN: unbalanced CSS")


def local_evidence(source: dict[str, str]) -> dict[str, str]:
    """Prove narrow local contracts; missing sources fail to UNKNOWN."""
    required = {"shell", "app", "platform", "overview", "css", "admin_css",
                "backend_test"}
    if set(source) != required or any(not isinstance(source[k], str) or
                                      not source[k].strip() for k in required):
        return {k: UNKNOWN for k in GAPS}
    shell, app, platform = source["shell"], source["app"], source["platform"]
    overview, css = source["overview"], source["css"]
    admin_css, backend = source["admin_css"], source["backend_test"]
    checks = {
        "D-041-S04": (
            "export function OverviewGrid(" in overview
            and "items.map((item)" in overview
            and "aria-label={ariaLabel}" in overview
            and "import { OverviewGrid }" in app
            and "export function AdminShell(" in shell
            and all(token in css for token in (
                "--surface:", "--text:", "--border:", "--accent:",
                "--focus:", "--space-1: 4px;", "--space-4: 16px;"))
        ),
        "D-041-S05": (
            "navItems.map((item)" in shell
            and "<nav aria-label={navLabel}>" in shell
            and "aria-current={item.current ? 'page' : undefined}" in shell
            and "navLabel=" in app
            and "navItems={[" in app
            and "navItems={[" in platform
            and "response.status === 403" in app
            and "testNewSectionsDenyNonPlatformOwnersAndRenderForOwner" in backend
            and "self::assertResponseStatusCodeSame(403)" in backend
        ),
        "D-041-S06": (
            ".admin-shell { grid-template-columns: 240px 1fr; }" in css
            and ".admin-shell { grid-template-columns: 1fr; }" in
                css_body(css, "@media (max-width: 860px)")
            and ".home-actions .button { width: 100%; }" in
                css_body(css, "@media (max-width: 560px)")
            and ".sidebar a { min-height: 44px;" in css
            and "@media (max-width: 700px)" in admin_css
            and "grid-template-columns: 1fr;" in
                css_body(admin_css, "@media (max-width: 700px)")
        ),
        "D-041-S07": (
            "input:focus-visible" in css
            and "outline: 3px solid var(--focus);" in css
            and "aria-label={navLabel}" in shell
            and "aria-current={item.current ? 'page' : undefined}" in shell
            and "aria-live=" in shell
            and "role=" in app
            and "label htmlFor={fieldId}" in shell
            and "aria-keyshortcuts=" in shell
            and "event.key === 'ArrowDown'" in shell
            and "event.key === 'Escape'" in shell
        ),
        "D-041-S08": (
            all(needle in app for needle in (
                "status: 'loading'", "status: 'ready'",
                "status: 'denied'", "status: 'error'",
                "context.status === 'loading'",
                "context.status === 'error'",
                "context.status === 'denied'"))
            and all(needle in platform for needle in (
                "status: 'permission-denied'",
                "status: 'not-found'",
                "status: 'error'",
                "state.status === 'permission-denied'",
                "state.status === 'not-found'",
                "state.status === 'error'"))
            and all(needle in shell for needle in (
                "status === 'loading'", "status === 'empty'",
                "status === 'error'", "role="))
            and "Recarga la" in app and "Reintentar" in platform
        ),
    }
    return {key: LOCAL_PARTIAL if passed else UNKNOWN
            for key, passed in checks.items()}


def full_coverage(*, local: str, production: object = None,
                  viewport: object = None, accessibility: object = None) -> str:
    """Partial or untrusted external evidence never becomes integral coverage."""
    return UNKNOWN


class BrechasCondor11Tests(unittest.TestCase):
    @classmethod
    def setUpClass(cls) -> None:
        cls.rows = coverage_rows(MATRIX.read_text(encoding="utf-8"))
        cls.spec = SPEC.read_text(encoding="utf-8")
        cls.sources = {
            "shell": SHELL.read_text(encoding="utf-8"),
            "app": APP.read_text(encoding="utf-8"),
            "platform": PLATFORM.read_text(encoding="utf-8"),
            "overview": OVERVIEW.read_text(encoding="utf-8"),
            "css": CSS.read_text(encoding="utf-8"),
            "admin_css": ADMIN_CSS.read_text(encoding="utf-8"),
            "backend_test": BACKEND_TEST.read_text(encoding="utf-8"),
        }
        cls.evidence = local_evidence(cls.sources)

    def test_contrato_principal(self) -> None:
        """AC-01: five separate real-source assertions or explicit UNKNOWN."""
        self.assertEqual(set(self.rows), set(GAPS))
        self.assertIn("### D-041 — Baseline de sistema de diseño", self.spec)
        for key, title in GAPS.items():
            with self.subTest(clause=key):
                self.assertEqual(self.rows[key][1], title)
                self.assertEqual(self.rows[key][2], "NO_VERIFICABLE")
                self.assertIn("Falta evidencia integral", self.rows[key][3])
                self.assertIn("#### " + title, self.spec)
                self.assertIn(self.evidence[key], {LOCAL_PARTIAL, UNKNOWN})
                if self.evidence[key] == UNKNOWN:
                    # An unverified local clause is retained explicitly, not
                    # declared as satisfied or silently dropped.
                    self.assertEqual(full_coverage(local=UNKNOWN), UNKNOWN)
                else:
                    self.assertEqual(self.evidence[key], LOCAL_PARTIAL)
                    self.assertEqual(full_coverage(local=LOCAL_PARTIAL), UNKNOWN)

    def test_falla_cerrado(self) -> None:
        """AC-02: loss/duplication/malformed data or source is not success."""
        content = MATRIX.read_text(encoding="utf-8")
        row = next(x for x in content.splitlines()
                   if x.startswith("| " + chr(96) + "D-041-S04" + chr(96)))
        for damaged in (
            content.replace(row, ""),
            content + "\n" + row,
            content.replace(row, "| " + chr(96) + "D-041-S04" + chr(96) + " :: broken |"),
        ):
            with self.subTest(damage=len(damaged)):
                with self.assertRaises(ValueError):
                    coverage_rows(damaged)
        with self.assertRaises(ValueError):
            css_body(".a { color: red;", ".a")
        with self.assertRaises(ValueError):
            css_body("", "@media (max-width: 860px)")
        missing = dict(self.sources)
        missing["shell"] = ""
        self.assertEqual(set(local_evidence(missing).values()), {UNKNOWN})
        missing = dict(self.sources)
        del missing["backend_test"]
        self.assertEqual(set(local_evidence(missing).values()), {UNKNOWN})
        altered = dict(self.sources)
        altered["css"] = altered["css"].replace(
            ".admin-shell { grid-template-columns: 1fr; }", "")
        self.assertEqual(local_evidence(altered)["D-041-S06"], UNKNOWN)
        altered = dict(self.sources)
        altered["shell"] = altered["shell"].replace(
            "aria-current={item.current ? 'page' : undefined}", "")
        self.assertEqual(local_evidence(altered)["D-041-S05"], UNKNOWN)

    def test_sin_datos_reales(self) -> None:
        """AC-03: fixed test paths, no credentials, networking or user records."""
        self.assertEqual(set(self.sources),
                         {"shell", "app", "platform", "overview",
                          "css", "admin_css", "backend_test"})
        self.assertTrue(all(p.is_file() for p in (
            MATRIX, SPEC, SHELL, APP, PLATFORM, OVERVIEW,
            CSS, ADMIN_CSS, BACKEND_TEST)))
        synthetic = {"role": "demo-viewer", "tenant": "synthetic-tenant",
                     "viewport": 360, "permissions": []}
        self.assertFalse(synthetic["permissions"])
        self.assertIn("synthetic", synthetic["tenant"])
        self.assertNotIn("@", synthetic["tenant"])
        self.assertEqual(full_coverage(local=UNKNOWN, production=synthetic), UNKNOWN)

    def test_estados_unknown_explicitos(self) -> None:
        """AC-04 test: partial evidence cannot certify operation, WCAG or live."""
        self.assertEqual(set(GAPS), set(self.rows))
        for key in GAPS:
            with self.subTest(clause=key):
                self.assertEqual(self.rows[key][2], "NO_VERIFICABLE")
                for candidate in (None, "", "pass", {"ci": "success"},
                                  {"status": "LIVE"},
                                  {"viewport": 360, "rendered": True},
                                  {"wcag": "AA"}, {"permissions": ["admin"]}):
                    self.assertEqual(
                        full_coverage(local=self.evidence[key],
                                      production=candidate,
                                      accessibility=candidate,
                                      viewport=candidate), UNKNOWN)


if __name__ == "__main__":
    unittest.main()
