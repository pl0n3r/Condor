#!/usr/bin/env python3
"""Aceptación del flujo admin de compras, sin dependencias de servicios reales."""
from pathlib import Path
import unittest

ROOT = Path(__file__).resolve().parents[1]
APP = (ROOT / "frontend/admin/AdminApp.tsx").read_text(encoding="utf-8")
PANEL = (ROOT / "frontend/admin/PurchasingManagement.tsx").read_text(encoding="utf-8")
CONTROLLER = (ROOT / "src/Http/Controller/PurchasingController.php").read_text(encoding="utf-8")


class ComprasUiAdminTests(unittest.TestCase):
    def test_acceptance(self):
        """La UI permite proveedor → orden → recepción y hace visibles los fallos."""
        self.assertIn("import { PurchasingManagement } from './PurchasingManagement';", APP)
        self.assertIn("<PurchasingManagement", APP)
        self.assertIn("id=\"purchasing\"", PANEL)
        self.assertIn("{ href: '/admin#purchasing', label: 'Compras' }", APP)
        self.assertIn("branchId={context.data.active_branch.id}", APP)
        self.assertIn("permissions={context.data.permissions}", APP)
        self.assertIn("csrfToken={accessToken}", APP)
        self.assertIn("function createSupplier(", PANEL)
        self.assertIn("function createOrder(", PANEL)
        self.assertIn("function receiveOrder(", PANEL)
        self.assertIn("supplier_id: supplierId, lines", PANEL)
        self.assertIn("idempotency_key: key", PANEL)
        self.assertIn("receiveKeys.current.set(order.id, key)", PANEL)
        self.assertIn("receiveKeys.current.delete(order.id)", PANEL)
        self.assertIn("crypto.randomUUID()", PANEL)
        for check in ("inventory.view", "inventory.create", "inventory.update"):
            self.assertIn(check, PANEL)
        for route in ("purchasing/suppliers", "purchasing/orders", "purchasing/orders/{orderId}/receive"):
            self.assertIn(route, CONTROLLER)
        self.assertIn("'X-CSRF-Token': csrfToken", PANEL)
        self.assertIn("credentials: 'same-origin'", PANEL)
        self.assertIn("state.status === 'loading'", PANEL)
        self.assertIn("state.status === 'denied'", PANEL)
        self.assertIn("state.status === 'error'", PANEL)
        self.assertIn("data.suppliers.length === 0", PANEL)
        self.assertIn("data.orders.length === 0", PANEL)
        self.assertIn('role="alert"', PANEL)
        self.assertIn('role="status"', PANEL)
        self.assertIn('aria-live="polite"', PANEL)
        self.assertNotIn("tenant_id:", PANEL)
        self.assertNotIn("legal_entity_id:", PANEL)
        self.assertNotIn("source_id:", PANEL)

    def test_no_unverified_weighted_cost_is_presented_as_fact(self):
        self.assertIn("API actual no expone ese valor", PANEL)
        self.assertNotIn("average_unit_cost:", PANEL)
        self.assertNotIn("parseFloat(", PANEL)


if __name__ == "__main__":
    unittest.main()
