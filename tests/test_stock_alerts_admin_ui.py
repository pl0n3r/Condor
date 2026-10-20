"""Acceptance contract for the stock-alert admin UI (#726).

This suite checks that the UI is connected to the shipped HTTP contract,
does not manufacture alerts when the backend reports none, and fails closed.
The TypeScript build and browser E2E gates remain separate prerequisites.
"""
import re
import unittest
from pathlib import Path

ROOT = Path(__file__).resolve().parents[1]
PANEL = (ROOT / "frontend/admin/StockAlertsPanel.tsx").read_text(encoding="utf-8")
APP = (ROOT / "frontend/admin/AdminApp.tsx").read_text(encoding="utf-8")
BUNDLE = (ROOT / "public/build/admin.js").read_text(encoding="utf-8")
CONTROLLER = (ROOT / "src/Http/Controller/StockAlertController.php").read_text(
    encoding="utf-8"
)


class AlertasUiAdminTests(unittest.TestCase):
    def test_acceptance(self):
        """Active alerts render with branch, threshold, empty and error states."""
        self.assertIn("export function StockAlertsPanel", PANEL)
        self.assertIn("Alertas de stock bajo", PANEL)
        self.assertIn("ready.alerts.length === 0", PANEL)
        self.assertIn("No hay alertas activas de stock bajo para esta sede.", PANEL)
        self.assertIn("displayed.map(", PANEL)
        self.assertIn("alert.quantity", PANEL)
        self.assertIn("alert.minimum", PANEL)
        self.assertIn("Mínimo actual:", PANEL)
        self.assertIn("sourceId", PANEL)
        self.assertIn("Guardando…", PANEL)
        self.assertIn("Guardar mínimo", PANEL)
        self.assertIn("Mínimo de stock actualizado.", PANEL)
        self.assertIn("Cargando alertas de stock…", PANEL)
        self.assertIn("No fue posible consultar las alertas de stock.", PANEL)
        self.assertIn("Acceso denegado a las alertas de esta sede.", PANEL)
        self.assertIn("Reintentar", PANEL)
        self.assertIn("useState<PanelState>", PANEL)
        self.assertIn("'loading'", PANEL)
        self.assertIn("'ready'", PANEL)
        self.assertIn("'denied'", PANEL)
        self.assertIn("'error'", PANEL)
        self.assertIn("readStockData(alerts, minimums)", PANEL)
        self.assertNotIn("minimum: 0, quantity: 0", PANEL)

    def test_shipped_bundle_contains_stock_alerts(self):
        """Fail if the compiled admin asset omits the approved inventory UI."""
        self.assertIn("Alertas de stock bajo", BUNDLE)
        self.assertIn("No hay alertas activas de stock bajo para esta sede.", BUNDLE)
        self.assertIn("Mínimo de stock actualizado.", BUNDLE)
        self.assertIn("No fue posible consultar las alertas de stock.", BUNDLE)
        self.assertIn("Guardar mínimo", BUNDLE)
        self.assertIn("Acceso denegado a las alertas de esta sede.", BUNDLE)

    def test_branch_context_and_permissions(self):
        """Identity and active branch come from authenticated AdminApp context."""
        self.assertIn("import { StockAlertsPanel } from './StockAlertsPanel';", APP)
        self.assertIn("href: '/admin#stock-alerts'", APP)
        self.assertIn("<StockAlertsPanel", APP)
        self.assertIn("key={'stock-alerts-' + context.data.active_branch.id}", APP)
        self.assertIn("branchId={context.data.active_branch.id}", APP)
        self.assertIn("permissions={context.data.permissions}", APP)
        self.assertIn("csrfToken={accessToken}", APP)
        self.assertIn("permissions.includes('inventory.view')", PANEL)
        self.assertIn("permissions.includes('inventory.update')", PANEL)
        self.assertIn("if (!canView) return;", PANEL)
        self.assertIn("if (!canUpdate || savingVariant !== null", PANEL)
        self.assertIn("credentials: 'same-origin'", PANEL)
        self.assertIn("'X-CSRF-Token': csrfToken", PANEL)
        self.assertIn("body: JSON.stringify({ minimum })", PANEL)
        self.assertNotIn("tenantId", PANEL)
        self.assertNotIn("localStorage", PANEL)

    def test_http_contract_matches_real_controller(self):
        """The PUT path is from validated server items, never arbitrary tenant input."""
        self.assertIn("'/api/v1/branches/' + encodeURIComponent(branchId)", PANEL)
        self.assertIn("'/inventory/stock-alerts'", PANEL)
        self.assertIn("const minimumsPath = branchPath + '/minimums';", PANEL)
        self.assertIn("minimumsPath + '/' + encodeURIComponent(variantId)", PANEL)
        self.assertIn("method: 'PUT'", PANEL)
        self.assertIn("responseJson(alertsResponse)", PANEL)
        self.assertIn("responseJson(minimumsResponse)", PANEL)
        self.assertIn("minimums.source_id !== alerts.source_id", PANEL)
        self.assertIn("value.source_id === sourceId", PANEL)
        self.assertIn("!minimums.minimums.every(", PANEL)
        self.assertIn("!alerts.alerts.every(", PANEL)
        self.assertIn("result.minimum.variant_id !== variantId", PANEL)
        self.assertIn("result.minimum.minimum !== minimum", PANEL)
        for route in (
            "/api/v1/branches/{branchId}/inventory/stock-alerts",
            "/api/v1/branches/{branchId}/inventory/stock-alerts/minimums",
            "/api/v1/branches/{branchId}/inventory/stock-alerts/minimums/{variantId}",
        ):
            self.assertIn(route, CONTROLLER)

    def test_no_stale_tenant_data_or_invalid_minimums(self):
        """Changing branches invalidates prior requests; invalid minima cannot be sent."""
        self.assertIn("requestSequence.current += 1", PANEL)
        self.assertIn("requestId !== requestSequence.current", PANEL)
        self.assertIn("[branchId, canView]", PANEL)
        self.assertIn("if (!/^(0|[1-9][0-9]*)$/.test(value))", PANEL)
        self.assertIn("Number.isSafeInteger(value)", PANEL)
        self.assertIn("value <= 2147483647", PANEL)
        self.assertIn("type=\"number\"", PANEL)
        self.assertIn("max=\"2147483647\"", PANEL)
        self.assertIn("step=\"1\"", PANEL)
        self.assertIn("status === 401 || status === 403", PANEL)
        self.assertIn("status === 422", PANEL)
        self.assertIn("role=\"alert\"", PANEL)
        self.assertIn("aria-live=\"polite\"", PANEL)


if __name__ == "__main__":
    unittest.main()
