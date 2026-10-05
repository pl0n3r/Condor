#!/usr/bin/env python3
"""Aceptación ejecutable de Producción Lite V1 / órdenes (#574)."""

from __future__ import annotations

import re
import subprocess
import unittest
from pathlib import Path

ROOT = Path(__file__).resolve().parents[1]
PHP_TEST = ROOT / "tests/php/Domain/Production/ProductionOrderTest.php"
ORDER = ROOT / "src/Domain/Production/Entity/ProductionOrder.php"
BALANCE = ROOT / "src/Domain/Production/Entity/MaterialInventoryBalance.php"
MOVEMENT = ROOT / "src/Domain/Production/Entity/MaterialInventoryMovement.php"
MATERIAL_SERVICE = ROOT / "src/Application/Production/MaterialInventoryService.php"
ORDER_SERVICE = ROOT / "src/Application/Production/ProductionOrderService.php"
INVENTORY_SERVICE = ROOT / "src/Application/Inventory/InventoryService.php"
MIGRATION = ROOT / "migrations/Version20261004153000.php"
VERSION = ROOT / "config/version.php"


class ProductionOrderTests(unittest.TestCase):
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

    def test_completion_consumes_bom_materials_and_adds_finished_variant_exactly_once(self) -> None:
        service = ORDER_SERVICE.read_text(encoding="utf-8")
        material_service = MATERIAL_SERVICE.read_text(encoding="utf-8")
        inventory = INVENTORY_SERVICE.read_text(encoding="utf-8")

        self.assertIn("$entitlements->addOn('production-lite')", service)
        self.assertIn("MaterialInventoryBalance::multiply(", service)
        self.assertIn("$this->materialInventory->consumeForProduction(", service)
        self.assertIn("$this->inventory->adjust(", service)
        self.assertIn("':finished'", service)
        self.assertIn("production_order_id", service)
        self.assertIn("isTransactionActive()", inventory)
        self.assertIn("LockMode::PESSIMISTIC_WRITE", material_service)
        self.assertIn("ksort($normalized)", material_service)
        self.phpunit(
            "testCompletionConsumesBomMaterialsAndAddsFinishedVariantExactlyOnce"
        )

    def test_material_ledger_rejects_negative_cross_tenant_inactive_or_reused_idempotency_with_different_payload(self) -> None:
        balance = BALANCE.read_text(encoding="utf-8")
        movement = MOVEMENT.read_text(encoding="utf-8")
        service = MATERIAL_SERVICE.read_text(encoding="utf-8")

        self.assertIn("DECIMAL", MIGRATION.read_text(encoding="utf-8"))
        self.assertIn("$next < 0", balance)
        self.assertIn("TYPE_PRODUCTION_CONSUMPTION", movement)
        self.assertIn("array<string, scalar|null>", movement)
        self.assertIn("uniq_production_material_movement_tenant_key", movement)
        self.assertIn("assertSameMovement", service)
        self.assertIn("assertOwnedScope", service)
        self.assertIn("assertSourceActive", service)
        self.phpunit(
            "testMaterialLedgerRejectsNegativeCrossTenantInactiveOrReusedIdempotencyWithDifferentPayload"
        )

    def test_completion_is_atomic_fail_closed_and_requires_production_lite(self) -> None:
        order = ORDER.read_text(encoding="utf-8")
        service = ORDER_SERVICE.read_text(encoding="utf-8")

        self.assertIn("STATUS_DRAFT", order)
        self.assertIn("STATUS_COMPLETED", order)
        self.assertIn("completionIdempotencyKey", order)
        self.assertIn("$completedQuantity !== $this->targetQuantity", order)
        self.assertIn("wrapInTransaction(", service)
        self.assertIn("$order->complete(", service)
        self.assertIn("$variant->product()->isActive()", service)
        self.phpunit(
            "testCompletionIsAtomicFailClosedAndRequiresProductionLite"
        )

    def test_schema_is_additive_tenant_scoped_and_materials_do_not_pollute_product_inventory_ledger(self) -> None:
        source = MIGRATION.read_text(encoding="utf-8")
        up = source.split("public function up", 1)[1].split("public function down", 1)[0]
        movement = MOVEMENT.read_text(encoding="utf-8")
        inventory = INVENTORY_SERVICE.read_text(encoding="utf-8")

        self.assertEqual(
            [
                "condor_production_order",
                "condor_production_material_balance",
                "condor_production_material_movement",
            ],
            re.findall(r"CREATE\s+TABLE\s+([a-z0-9_]+)", up, flags=re.IGNORECASE),
        )
        for pattern in (
            r"\bALTER\s+TABLE\b",
            r"\bDROP\s+",
            r"\bDELETE\s+FROM\b",
            r"\bTRUNCATE\b",
            r"\bRENAME\s+",
        ):
            with self.subTest(pattern=pattern):
                self.assertIsNone(re.search(pattern, up, flags=re.IGNORECASE))

        self.assertIn(
            "REFERENCES condor_inventory_source (tenant_id, id)",
            up,
        )
        self.assertIn(
            "REFERENCES condor_production_material (tenant_id, id)",
            up,
        )
        self.assertIn("CHECK (quantity >= 0)", up)
        self.assertNotIn("ProductVariant", movement)
        self.assertNotIn("Material $material", inventory)
        self.assertIn(
            "'version' => '0.1.173'",
            VERSION.read_text(encoding="utf-8"),
        )


if __name__ == "__main__":
    unittest.main()
