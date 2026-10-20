#!/usr/bin/env python3
"""Aceptación del flujo admin de compras, sin dependencias de servicios reales."""
from pathlib import Path
import unittest

ROOT = Path(__file__).resolve().parents[1]
APP = (ROOT / "frontend/admin/AdminApp.tsx").read_text(encoding="utf-8")
PANEL = (ROOT / "frontend/admin/PurchasingManagement.tsx").read_text(encoding="utf-8")
BUNDLE = (ROOT / "public/build/admin.js").read_text(encoding="utf-8")
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
        self.assertIn("Array.isArray(ordersResult.orders)", PANEL)
        self.assertIn("new Set(lines.map((line) => line.material_id))", PANEL)
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

    def test_historical_cost_failure_does_not_block_order_workflow(self):
        """El histórico no disponible nunca inventa un costo ni oculta 403."""
        self.assertIn("const historicalRequest =", PANEL)
        self.assertIn("readJson<{ material_costs: MaterialCost[] }>(materialCostsPath)", PANEL)
        self.assertIn("error.message === 'PERMISSION_DENIED'", PANEL)
        self.assertIn("throw error;", PANEL)
        self.assertIn("historyStatus: 'verified' | 'unavailable'", PANEL)
        self.assertIn("data.historyStatus === 'unavailable'", PANEL)
        self.assertIn("data.historyStatus === 'verified'", PANEL)
        self.assertIn("const historyValid = historical.available", PANEL)
        self.assertIn("historical.result !== null", PANEL)
        self.assertIn("typeof historical.result === 'object'", PANEL)
        self.assertIn("cost === null", PANEL)
        self.assertIn("typeof cost !== 'object'", PANEL)
        self.assertIn("const materialCosts = historyValid ? historical.result.material_costs : [];", PANEL)
        self.assertIn("historyStatus: historyValid", PANEL)
        self.assertIn("materialCosts,", PANEL)
        self.assertNotIn("materialCostsResult.material_costs.some(", PANEL)
        self.assertNotIn("historyStatus: historical.available", PANEL)
        self.assertIn("No se pudo verificar el costo histórico.", PANEL)
        self.assertIn("Los proveedores y órdenes siguen disponibles", PANEL)
        self.assertNotIn("average_unit_cost: '0'", PANEL)
        self.assertNotIn("average_unit_cost: 0", PANEL)

    def test_compiled_admin_contains_purchasing_and_fail_closed_messages(self):
        """La entrega compilada realmente incorpora la UI y los estados seguros."""
        self.assertIn("Proveedores, órdenes y recepción", BUNDLE)
        self.assertIn("No se pudo verificar el costo histórico.", BUNDLE)
        self.assertIn("Sin costo promedio histórico verificado", BUNDLE)
        self.assertIn("Recepción registrada sin duplicar stock.", BUNDLE)
        self.assertNotIn("historyStatus: historical.available", PANEL)

    def test_mutation_refresh_keeps_form_focus(self):
        """Recargar tras POST no desmonta formularios ni pierde el foco."""
        self.assertIn("async function load(options: { keepData?: boolean } = {})", PANEL)
        self.assertIn("if (!options.keepData) setState({ status: 'loading' });", PANEL)
        self.assertIn("await load({ keepData: true });", PANEL)
        self.assertIn("if (canView) {", PANEL)
        self.assertIn("void load();", PANEL)

    def test_weighted_cost_requires_verified_server_history(self):
        self.assertIn("const materialCostsPath = basePath + '/material-costs'", PANEL)
        self.assertIn("readJson<{ material_costs: MaterialCost[] }>(materialCostsPath)", PANEL)
        self.assertIn("Array.isArray(historical.result.material_costs)", PANEL)
        self.assertIn("cost.state !== 'KNOWN' && cost.state !== 'UNKNOWN'", PANEL)
        self.assertIn("cost.average_unit_cost !== null", PANEL)
        self.assertIn("cost.receipt_count < 0", PANEL)
        self.assertIn("materialCosts,", PANEL)
        self.assertIn("historical?.state === 'KNOWN'", PANEL)
        self.assertIn("Sin costo promedio histórico verificado", PANEL)
        self.assertIn("Costo promedio ponderado de materiales", PANEL)
        self.assertIn("Los materiales sin historial se muestran como desconocidos", PANEL)
        self.assertNotIn("parseFloat(", PANEL)
        self.assertNotIn("parseInt(", PANEL)
        self.assertNotIn("Number(historical.average_unit_cost)", PANEL)
        self.assertNotIn("tenant_id:", PANEL)


if __name__ == "__main__":
    unittest.main()
