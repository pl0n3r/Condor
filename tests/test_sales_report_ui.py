#!/usr/bin/env python3
"""Executable acceptance for Condor #868: mounted, read-only sales reports UI."""
import json
import re
import shutil
import subprocess
import unittest
from pathlib import Path

ROOT = Path(__file__).resolve().parents[1]
PANEL = ROOT / "frontend/admin/SalesReportPanel.tsx"
APP = ROOT / "frontend/admin/AdminApp.tsx"
API = ROOT / "src/Http/Controller/SalesReportController.php"


class UiVentasPorProductoTests(unittest.TestCase):
    def test_acceptance(self):
        """Product/month endpoints, keyboard form, sorting and empty/error states."""
        panel = PANEL.read_text(encoding="utf-8")
        app = APP.read_text(encoding="utf-8")
        api = API.read_text(encoding="utf-8")

        for endpoint in ("sales-by-product", "sales-by-month"):
            with self.subTest(endpoint=endpoint):
                self.assertIn("/api/reports/" + endpoint, api)
                self.assertIn("report('" + endpoint + "'", panel)

        self.assertIn("new URLSearchParams({ from, to })", panel)
        self.assertIn("credentials: 'same-origin'", panel)
        self.assertIn("headers: { Accept: 'application/json' }", panel)
        self.assertIn("permissions.includes('orders.view')", panel)
        self.assertIn("if (!allowed) return null;", panel)
        self.assertIn("if (!validRange(from, to))", panel)
        self.assertIn("event.preventDefault()", panel)
        self.assertRegex(panel, r'<form onSubmit=')
        for field in ("sales-from", "sales-to"):
            self.assertIn('htmlFor="' + field + '"', panel)
            self.assertIn('id="' + field + '" type="date" required', panel)
        self.assertIn("role=\"alert\"", panel)
        self.assertIn("role=\"status\"", panel)
        self.assertIn("No hay ventas por producto", panel)
        self.assertIn("No hay ventas por mes", panel)
        self.assertIn('scope="col"', panel)
        self.assertIn('scope="row"', panel)
        self.assertIn("localeCompare", panel)
        self.assertIn("Intl.NumberFormat('es-CO'", panel)
        self.assertIn("participation_bps / 100", panel)
        self.assertIn("serial !== requestId.current", panel)
        self.assertIn("Promise.all([", panel)
        self.assertIn("data.group_by !== dimension", panel)
        self.assertIn("Array.isArray(data.items)", panel)
        self.assertIn("data.items.every(validateRow)", panel)

        self.assertIn("import { SalesReportPanel }", app)
        self.assertIn('href: \'/admin#sales-reports\'', app)
        self.assertIn("<SalesReportPanel", app)
        self.assertIn("permissions={context.data.permissions}", app)
        self.assertIn("key={'sales-reports-' + context.data.active_branch.id}", app)

    def test_range_validation_is_executable(self):
        """Execute the real pure JS range functions after removing TS annotations."""
        node = shutil.which("node")
        self.assertIsNotNone(node, "Node required to test date-range validation")
        source = PANEL.read_text(encoding="utf-8")
        match = re.search(r"function validDate\(.*?(?=function validMoneyRow\()", source, re.S)
        self.assertIsNotNone(match)
        functions = match.group(0)
        functions = functions.replace("value: string): boolean", "value)")
        functions = functions.replace("from: string, to: string): boolean", "from, to)")
        self.assertNotIn(": boolean", functions)
        payload = functions + """
const samples = [
  ['2026-01-01', '2026-12-31'],
  ['2026-01-01', '2027-01-01'],
  ['2026-01-01', '2027-01-02'],
  ['2026-02-30', '2026-03-01'],
  ['2026-10-05', '2026-10-04'],
  ['2026-01-01;', '2026-02-01'],
];
console.log(JSON.stringify(samples.map(([from, to]) => validRange(from, to))));
"""
        run = subprocess.run(
            [node, "-e", payload], capture_output=True, text=True,
            cwd=ROOT, check=False, timeout=12,
        )
        self.assertEqual(run.returncode, 0, run.stdout + run.stderr)
        self.assertEqual(
            json.loads(run.stdout),
            [True, True, False, False, False, False],
        )

    def test_fail_closed_without_sensitive_scope_or_side_effects(self):
        panel = PANEL.read_text(encoding="utf-8")
        for forbidden in (
            "tenant_id", "localStorage", "sessionStorage",
            "dangerouslySetInnerHTML", "innerHTML", "method: 'POST'",
            "method: 'DELETE'", "window.location.href",
        ):
            with self.subTest(forbidden=forbidden):
                self.assertNotIn(forbidden, panel)
        self.assertIn("response.status === 401 || response.status === 403", panel)
        self.assertIn("Respuesta de reportes inválida.", panel)
        self.assertIn("Number.isSafeInteger(row.revenue_minor)", panel)
        self.assertIn("new Intl.NumberFormat", panel)


if __name__ == "__main__":
    unittest.main()
