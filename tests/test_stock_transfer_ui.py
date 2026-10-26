"""Acceptance tests for physical branch transfers in the Condor admin (#931).

Only synthetic source-contract assertions; backend HTTP/DB integration is
separately covered by tests/test_stock_transfers_api.py (#862).
"""
from pathlib import Path
import re
import unittest

ROOT = Path(__file__).resolve().parents[1]
PANEL = (ROOT / "frontend/admin/StockTransferPanel.tsx").read_text(encoding="utf-8")
APP = (ROOT / "frontend/admin/AdminApp.tsx").read_text(encoding="utf-8")
API = (ROOT / "frontend/admin/api.ts").read_text(encoding="utf-8")
CONTROLLER = (ROOT / "src/Http/Controller/StockTransferController.php").read_text(
    encoding="utf-8"
)


class UiTransferenciasSedesTests(unittest.TestCase):
    def test_acceptance(self):
        """AC-01: stock limit, real movements, and API failures."""
        self.assertIn("export function StockTransferPanel", PANEL)
        self.assertIn("Stock registrado en origen", PANEL)
        self.assertIn("parsedQuantity <= available", PANEL)
        self.assertIn("disabled={!canTransfer}", PANEL)
        self.assertIn("validQuantity && result === null", PANEL)
        self.assertIn("Preparar otra transferencia", PANEL)
        self.assertIn("result.movements.length === 2", PANEL)
        self.assertIn("movement.transfer_id === receipt.id", PANEL)
        self.assertIn("movement.type === 'transfer_out'", PANEL)
        self.assertIn("movement.type === 'transfer_in'", PANEL)
        self.assertIn("responseError(response.status)", PANEL)
        self.assertIn("case 422:", PANEL)
        self.assertIn("case 403:", PANEL)
        self.assertIn("Reintentar", PANEL)

    def test_uses_existing_authorized_http_contract(self):
        self.assertIn("export function inventoryPath", API)
        self.assertIn("inventoryPath(branchId) + '/branch-transfers'", PANEL)
        self.assertIn("/inventory/branch-transfers", CONTROLLER)
        self.assertIn("'source_from_id', 'source_to_id', 'variant_id'", CONTROLLER)
        self.assertIn("'inventory.update'", CONTROLLER)
        self.assertIn("requireCsrf($request)", CONTROLLER)
        for item in [
            "credentials: 'same-origin'",
            "'X-CSRF-Token': csrfToken",
            "'Content-Type': 'application/json'",
            "idempotency_key: idempotency.current.key",
            "source_from_id: from",
            "source_to_id: to",
            "variant_id: variant",
            "quantity: amount",
            "readReceipt(",
        ]:
            self.assertIn(item, PANEL)
        self.assertNotIn("localStorage", PANEL)

    def test_only_eligible_physical_branches_are_selectable(self):
        self.assertIn("source.type === 'branch'", PANEL)
        self.assertIn("source.legal_entity_id === ready?.legal_entity.id", PANEL)
        self.assertIn("source.branch_id === branchId", PANEL)
        self.assertIn("source.branch_id !== branchId", PANEL)
        self.assertIn("selectedOrigin?.id !== selectedDestination?.id", PANEL)
        self.assertIn("readSnapshot(await jsonResponse(response), branchId)", PANEL)
        self.assertIn("readSnapshot(await jsonResponse(refresh), branchId)", PANEL)
        self.assertIn("if (request !== latest.current) return", PANEL)
        self.assertIn("latest.current += 1", PANEL)

    def test_never_fabricates_movements_or_confirms_invalid_receipts(self):
        self.assertIn("value.transfer.status !== 'completed'", PANEL)
        self.assertIn("value.transfer.source_from_id !== from", PANEL)
        self.assertIn("value.transfer.quantity !== quantity", PANEL)
        self.assertIn("movements: []", PANEL)
        self.assertIn("movement.delta === -amount", PANEL)
        self.assertIn("movement.delta === amount", PANEL)
        self.assertIn("El historial reciente no muestra los dos movimientos.", PANEL)
        self.assertIn("No fue posible actualizar los movimientos.", PANEL)
        self.assertIn("transfer_id === receipt.id", PANEL)
        self.assertNotIn("Math.random()", PANEL)
        self.assertNotIn("style={{", PANEL)

    def test_reachable_and_accessible_from_admin(self):
        self.assertIn("import { StockTransferPanel } from './StockTransferPanel';", APP)
        self.assertIn("href: '/admin#stock-transfers'", APP)
        self.assertIn("<StockTransferPanel", APP)
        self.assertIn("key={'stock-transfers-' + context.data.active_branch.id}", APP)
        self.assertIn("branchId={context.data.active_branch.id}", APP)
        self.assertIn("permissions={context.data.permissions}", APP)
        self.assertIn("csrfToken={accessToken}", APP)
        self.assertIn("aria-labelledby=", PANEL)
        self.assertIn("aria-live=", PANEL)
        self.assertIn("onSubmit={(event) => void submit(event)}", PANEL)
        self.assertIn("permissions.includes('inventory.view')", PANEL)
        self.assertIn("permissions.includes('inventory.update')", PANEL)


if __name__ == "__main__":
    unittest.main()
