#!/usr/bin/env python3
from __future__ import annotations

import subprocess
import unittest
from pathlib import Path

ROOT = Path(__file__).resolve().parents[1]
PHP_TEST = ROOT / "tests/php/Http/ProductionOperationsControllerTest.php"
CONTROLLER = ROOT / "src/Http/Controller/ProductionOperationsController.php"


class ProductionOperationsHttpTests(unittest.TestCase):
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

    def test_bom_snapshot_and_version_creation_are_tenant_scoped_and_entitled(self) -> None:
        source = CONTROLLER.read_text(encoding="utf-8")
        self.assertIn("/production/boms", source)
        self.assertIn("'inventory.view'", source)
        self.assertIn("'inventory.create'", source)
        self.assertIn("SubscriptionEntitlementContextFactory", source)
        self.assertIn("$this->billOfMaterials->createVersion(", source)
        self.phpunit(
            "testBomSnapshotAndVersionCreationAreTenantScopedAndEntitled"
        )

    def test_order_create_and_complete_use_branch_source_domain_services_and_idempotency(self) -> None:
        source = CONTROLLER.read_text(encoding="utf-8")
        self.assertIn("/production/orders", source)
        self.assertIn("$this->orders->create(", source)
        self.assertIn("$this->orders->complete(", source)
        self.assertIn("InventorySource::TYPE_BRANCH", source)
        self.assertIn("'idempotency_key'", source)
        self.phpunit(
            "testOrderCreateAndCompleteUseBranchSourceDomainServicesAndIdempotency"
        )

    def test_permissions_csrf_cross_tenant_stale_bom_or_foreign_order_fail_closed(self) -> None:
        source = CONTROLLER.read_text(encoding="utf-8")
        self.assertIn("$this->requireCsrf($request);", source)
        self.assertIn("'inventory.update'", source)
        self.assertIn("'tenant' => $tenant", source)
        self.assertIn("'source' => $source", source)
        self.assertIn("BOM no encontrada.", source)
        self.assertIn("Orden de producción no encontrada.", source)
        self.phpunit(
            "testPermissionsCsrfCrossTenantStaleBomOrForeignOrderFailClosed"
        )


if __name__ == "__main__":
    unittest.main()
