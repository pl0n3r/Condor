"""Acceptance contract for the stock-alert admin UI (#726).

This suite checks that the UI is connected to the shipped HTTP contract,
does not manufacture alerts when the backend reports none, and fails closed.
The TypeScript build and browser E2E gates remain separate prerequisites.
"""
import re
import shutil
import subprocess
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


    def test_duplicate_alerts_use_newest_ulid_independent_of_server_order(self):
        """An unsorted inbox must never replace a newer alert with an older one."""
        self.assertIn("existing?.alert && existing.alert.id >= alert.id", PANEL)
        self.assertIn("if (existing?.alert && existing.alert.id >= alert.id) continue;", PANEL)
        id_factory = (ROOT / "src/Shared/Id/UlidFactory.php").read_text(
            encoding="utf-8"
        )
        self.assertIn("new Ulid()", id_factory)

    def test_runtime_prefers_latest_alert_on_both_api_orderings(self):
        """Execute the real panel reducer in Node, not a copy of its logic."""
        node = shutil.which("node")
        if node is None:
            self.skipTest("Node runtime is not installed in this environment")
        start = "  const displayed = useMemo(() => {\n"
        end = "  }, [ready]);"
        self.assertEqual(PANEL.count(start), 1)
        expression = PANEL.split(start, 1)[1].split(end, 1)[0]
        typed_map = (
            "new Map<string, { variantId: string; minimum: number; "
            "alert?: StockAlert }>()"
        )
        self.assertIn(typed_map, expression)
        expression = expression.replace(typed_map, "new Map()")
        script = (
            "import assert from 'node:assert/strict';\n"
            "function displayed(ready) {\n" + expression + "\n}\n"
            "const old = {id:'01J00000000000000000000000',"
            "variant_id:'variant-1',minimum:4,quantity:2};\n"
            "const recent = {...old,id:'01K00000000000000000000000',quantity:1};\n"
            "for (const alerts of [[old,recent],[recent,old]]) {\n"
            "  const result=displayed({alerts,minimums:[{variant_id:'variant-1',minimum:7}]});\n"
            "  assert.equal(result.length,1);\n"
            "  assert.equal(result[0].alert.id,recent.id);\n"
            "  assert.equal(result[0].minimum,7);\n"
            "}\n"
            "const empty=displayed({alerts:[],minimums:[]});\n"
            "assert.equal(empty.length,0);\n"
        )
        result = subprocess.run(
            [node, "--input-type=module", "-e", script],
            text=True, capture_output=True, timeout=15, check=False,
        )
        self.assertEqual(result.returncode, 0, result.stderr)

    def test_save_rejects_invalid_or_stale_receipts(self):
        """A PUT success requires an exact tenant-scoped response and live request."""
        # 401/403 never fall through to an editable success state.
        self.assertIn("status === 401 || status === 403", PANEL)
        self.assertIn("setState({ status: 'denied' })", PANEL)
        # 404/422 are API errors; a rejected or malformed response is not success.
        self.assertIn("status === 404", PANEL)
        self.assertIn("status === 422", PANEL)
        self.assertIn("if (!response.ok)", PANEL)
        self.assertIn("throw apiError(response.status)", PANEL)
        self.assertIn("!stockMinimum(result.minimum, ready.sourceId)", PANEL)
        self.assertIn("result.minimum.variant_id !== variantId", PANEL)
        self.assertIn("result.minimum.minimum !== minimum", PANEL)
        self.assertIn("throw new Error('El servidor no confirmó el nuevo mínimo.')", PANEL)
        # A branch switch invalidates both success and error callbacks.
        self.assertIn("const requestId = requestSequence.current", PANEL)
        self.assertGreaterEqual(
            PANEL.count("if (requestId !== requestSequence.current) return"), 3,
        )
        self.assertIn("if (requestId === requestSequence.current) setSavingVariant(null)", PANEL)
        # Range and permission are validated before any network mutation.
        self.assertIn("if (!canUpdate || savingVariant !== null || ready === null) return;", PANEL)
        self.assertIn("if (minimum === null)", PANEL)
        self.assertIn("value <= 2147483647", PANEL)


if __name__ == "__main__":
    unittest.main()
