#!/usr/bin/env python3
"""#928: read-only order CSV export UI bound to the real backend contract."""
from __future__ import annotations

import unittest
from pathlib import Path

ROOT = Path(__file__).resolve().parents[1]
UI = (ROOT / "frontend/admin/OrdersManagement.tsx").read_text(encoding="utf-8")
API = (ROOT / "frontend/admin/api.ts").read_text(encoding="utf-8")
ROUTE = (ROOT / "src/Http/Controller/OrderExportController.php").read_text(
    encoding="utf-8"
)
CSV = (ROOT / "src/Application/Orders/OrderCsvExporter.php").read_text(
    encoding="utf-8"
)


class UiExportPedidosTests(unittest.TestCase):
    def test_acceptance(self) -> None:
        """Date selector, exact GET, safe download and failure feedback."""
        self.assertIn("Exportar CSV", UI)
        self.assertIn('aria-label="Exportar pedidos a CSV"', UI)
        self.assertIn("async function exportOrders(event: FormEvent<HTMLFormElement>)", UI)
        self.assertIn("new URLSearchParams({ from: exportFrom, to: exportTo })", UI)
        self.assertIn("fetch('/api/orders/export.csv?' + query.toString()", UI)
        self.assertIn("method: 'GET'", UI)
        self.assertIn("credentials: 'same-origin'", UI)
        self.assertIn("headers: { Accept: 'text/csv' }", UI)
        self.assertIn("response.blob()", UI)
        self.assertIn("URL.createObjectURL(csv)", UI)
        self.assertIn("link.download = 'pedidos-' + exportFrom + '-' + exportTo + '.csv'", UI)
        self.assertIn("link.click()", UI)
        self.assertIn("URL.revokeObjectURL(url)", UI)
        self.assertIn("!response.ok", UI)
        self.assertIn("setExportError(", UI)
        self.assertIn('role="alert"', UI)
        self.assertIn('role="status"', UI)
        self.assertIn("disabled={exportBusy || !exportFrom || !exportTo}", UI)
        self.assertIn('type="date" required value={exportFrom}', UI)
        self.assertIn('type="date" required value={exportTo}', UI)

    def test_scope_is_server_authoritative_and_fail_closed(self) -> None:
        self.assertIn("export function ordersPath(branchId: string)", API)
        self.assertIn("'/api/orders/export.csv'", ROUTE)
        self.assertIn("methods: ['GET']", ROUTE)
        self.assertIn("['from', 'to']", ROUTE)
        self.assertIn("['orders.view']", ROUTE)
        self.assertIn("CurrentTenantForUser", ROUTE)
        self.assertIn("authorizedSources", ROUTE)
        self.assertIn("o.tenant = :tenant", ROUTE)
        self.assertIn("o.inventorySource IN (:sources)", ROUTE)
        self.assertIn("Content-Disposition", ROUTE)
        self.assertIn("text/csv", ROUTE)
        self.assertIn("DateTimeZone('America/Bogota')", CSV)
        self.assertIn("ROW_LIMIT = 10000", CSV)
        self.assertNotIn("branch_id:", UI[UI.index("async function exportOrders"):UI.index("async function submitOrder")])
        self.assertNotIn("X-CSRF-Token", UI[UI.index("async function exportOrders"):UI.index("async function submitOrder")])

    def test_validation_and_no_inline_style_or_reflected_csv(self) -> None:
        segment = UI[UI.index("async function exportOrders"):UI.index("async function submitOrder")]
        self.assertIn("days < 0 || days >= 366", segment)
        self.assertIn("setExportBusy(true)", segment)
        self.assertIn("setExportBusy(false)", segment)
        self.assertIn("response.headers.get('content-type')", segment)
        self.assertIn("catch {", segment)
        self.assertNotIn("response.text()", segment)
        self.assertNotIn("response.json()", segment)
        self.assertNotIn("style={{", segment)
        self.assertIn("canView && (", UI)
        self.assertIn("Incluye los pedidos de todas tus sedes autorizadas", UI)


if __name__ == "__main__":
    unittest.main()
