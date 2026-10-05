#!/usr/bin/env python3
from __future__ import annotations

import unittest
from pathlib import Path

ROOT = Path(__file__).resolve().parents[1]
APP = (ROOT / "frontend/admin/AdminApp.tsx").read_text(encoding="utf-8")
PANEL = (ROOT / "frontend/admin/ProductionManagement.tsx").read_text(
    encoding="utf-8"
)
API = (ROOT / "frontend/admin/api.ts").read_text(encoding="utf-8")
CSS = (ROOT / "frontend/admin/admin.css").read_text(encoding="utf-8")
VERSION = (ROOT / "config/version.php").read_text(encoding="utf-8")


class ProductionAdminUiTests(unittest.TestCase):
    def test_admin_mounts_production_panel_with_branch_scoped_api_and_inventory_view_gate(
        self,
    ) -> None:
        self.assertIn(
            "import { ProductionManagement } from './ProductionManagement';",
            APP,
        )
        self.assertIn(
            "context.data.permissions.includes('inventory.view')",
            APP,
        )
        self.assertIn("<ProductionManagement", APP)
        self.assertIn("branchId={context.data.active_branch.id}", APP)
        self.assertIn("permissions={context.data.permissions}", APP)
        self.assertIn("csrfToken={accessToken}", APP)

        for helper in (
            "productionMaterialsPath",
            "productionMaterialPath",
            "productionMaterialAdjustmentPath",
            "productionBomsPath",
            "productionOrdersPath",
            "productionOrderCompletePath",
        ):
            self.assertIn(f"export function {helper}", API)

        self.assertIn("'/api/v1/branches/' + safeUlid(branchId)", API)
        self.assertIn("productionMaterialsPath(branchId)", PANEL)
        self.assertIn("productionBomsPath(branchId)", PANEL)
        self.assertIn("productionOrdersPath(branchId)", PANEL)
        self.assertIn("inventoryPath(branchId)", PANEL)
        self.assertNotIn("tenant_id:", PANEL)
        self.assertNotIn("source_id:", PANEL)
        self.assertIn("'version' => '0.1.180'", VERSION)

    def test_material_bom_and_order_mutations_use_csrf_permissions_and_refresh_without_domain_duplication(
        self,
    ) -> None:
        for permission in (
            "inventory.create",
            "inventory.update",
            "inventory.delete",
        ):
            self.assertIn(f"can('{permission}')", PANEL)

        for operation in (
            "createMaterial",
            "updateMaterial",
            "deactivateMaterial",
            "adjustMaterial",
            "createBom",
            "createOrder",
            "completeOrder",
        ):
            self.assertIn(f"function {operation}", PANEL)

        self.assertIn("'X-CSRF-Token': csrfToken", PANEL)
        self.assertIn("await loadProduction();", PANEL)
        self.assertIn("stableIdempotency(", PANEL)
        self.assertIn("idempotency_key:", PANEL)
        self.assertIn("responseMessage(", PANEL)
        self.assertIn("throw new Error(message)", PANEL)

        # La UI selecciona datos API; no reimplementa invariantes de dominio.
        self.assertNotIn("version + 1", PANEL)
        self.assertNotIn("completed_quantity > target_quantity", PANEL)
        self.assertNotIn("balance -", PANEL)
        self.assertNotIn("allowedTransitions", PANEL)
        self.assertNotIn("productionRules", PANEL)

        self.assertIn(".production-layout", CSS)
        self.assertIn("@media (max-width: 900px)", CSS)
        self.assertIn("@media (max-width: 620px)", CSS)

    def test_missing_entitlement_empty_and_error_states_are_fail_closed_and_accessible(
        self,
    ) -> None:
        self.assertIn("class ProductionUnavailable extends Error", PANEL)
        self.assertIn("response.status === 422", PANEL)
        self.assertIn(
            "Producción Lite no está habilitada",
            PANEL,
        )
        self.assertIn("status: 'disabled'", PANEL)
        self.assertIn("state.status === 'disabled'", PANEL)
        self.assertIn("state.status === 'error'", PANEL)
        self.assertIn('role="alert"', PANEL)
        self.assertIn('aria-live="polite"', PANEL)
        self.assertGreaterEqual(PANEL.count('role="status"'), 4)

        for empty_copy in (
            "Aún no hay materias primas registradas.",
            "Aún no hay BOM registradas.",
            "Aún no hay órdenes de producción.",
        ):
            self.assertIn(empty_copy, PANEL)

        disabled = PANEL.index("state.status === 'disabled'")
        ready = PANEL.index("{ready && (")
        self.assertLess(disabled, ready)
        self.assertIn("if (!canView)", PANEL)
        self.assertIn("return null;", PANEL)


if __name__ == "__main__":
    unittest.main()
