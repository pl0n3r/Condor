#!/usr/bin/env python3
from __future__ import annotations

import subprocess
import unittest
from pathlib import Path

ROOT = Path(__file__).resolve().parents[1]
PHP_TEST = ROOT / "tests/php/Http/ProductionMaterialsControllerTest.php"
CONTROLLER = ROOT / "src/Http/Controller/ProductionMaterialsController.php"


class ProductionMaterialsHttpTests(unittest.TestCase):
    def phpunit(self, pattern: str) -> None:
        runner = ROOT / "vendor/bin/simple-phpunit"
        if not runner.exists():
            self.fail("vendor/bin/simple-phpunit no está disponible")
        result = subprocess.run(
            [str(runner), "--filter", pattern, str(PHP_TEST)],
            cwd=ROOT,
            text=True,
            capture_output=True,
            check=False,
        )
        self.assertEqual(0, result.returncode, result.stdout + result.stderr)

    def test_snapshot_lists_tenant_materials_branch_balances_and_recent_movements_only_when_entitled(self) -> None:
        source = CONTROLLER.read_text(encoding="utf-8")
        self.assertIn("SubscriptionEntitlementContextFactory", source)
        self.assertIn("EntitlementResolver", source)
        self.assertIn("InventorySource::TYPE_BRANCH", source)
        self.assertIn("'inventory.view'", source)
        self.phpunit(
            "testSnapshotListsTenantMaterialsBranchBalancesAndRecentMovementsOnlyWhenEntitled"
        )

    def test_mutations_require_inventory_permissions_csrf_and_use_domain_services(self) -> None:
        source = CONTROLLER.read_text(encoding="utf-8")
        self.assertIn("$this->requireCsrf($request);", source)
        self.assertIn("'inventory.create'", source)
        self.assertIn("'inventory.update'", source)
        self.assertIn("'inventory.delete'", source)
        self.assertIn("$this->materials->create(", source)
        self.assertIn("$this->materials->update(", source)
        self.assertIn("$this->materials->deactivate(", source)
        self.assertIn("$this->materialInventory->adjust(", source)
        self.phpunit(
            "testMutationsRequireInventoryPermissionsCsrfAndUseDomainServices"
        )

    def test_cross_tenant_missing_entitlement_foreign_material_or_missing_branch_source_fails_closed(self) -> None:
        source = CONTROLLER.read_text(encoding="utf-8")
        self.assertIn("Producción Lite no está habilitada para este tenant.", source)
        self.assertIn("'tenant' => $tenant", source)
        self.assertIn("'branch' => $branch", source)
        self.assertIn("Fuente de inventario activa para la sede no encontrada.", source)
        self.phpunit(
            "testCrossTenantMissingEntitlementForeignMaterialOrMissingBranchSourceFailsClosed"
        )


if __name__ == "__main__":
    unittest.main()
