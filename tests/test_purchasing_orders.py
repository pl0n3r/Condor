#!/usr/bin/env python3
"""Aceptación ejecutable Compras Lite V1: órdenes y recepción (#655)."""
from pathlib import Path
import re
import subprocess
import unittest

ROOT = Path(__file__).resolve().parents[1]
ORDER = ROOT / "src/Domain/Purchasing/Entity/PurchaseOrder.php"
LINE = ROOT / "src/Domain/Purchasing/Entity/PurchaseOrderLine.php"
SERVICE = ROOT / "src/Application/Purchasing/PurchaseOrderService.php"
MIGRATION = ROOT / "migrations/Version20261012121000.php"
PHP_TEST = ROOT / "tests/php/Domain/Purchasing/PurchaseOrderDomainTest.php"


class PurchasingOrdersTests(unittest.TestCase):
    def phpunit(self, name):
        runner = ROOT / "vendor/bin/simple-phpunit"
        if not runner.exists():
            self.fail("No hay PHPUnit disponible para validar el contrato de compras")
        result = subprocess.run(
            [str(runner), "--filter", name, str(PHP_TEST)],
            cwd=ROOT, text=True, capture_output=True, check=False,
        )
        self.assertEqual(result.returncode, 0, result.stdout + result.stderr)

    def test_order_rejects_supplier_from_another_tenant(self):
        order = ORDER.read_text(encoding="utf-8")
        line = LINE.read_text(encoding="utf-8")
        migration = MIGRATION.read_text(encoding="utf-8")
        self.assertIn("$supplier->tenant()->id() !== $tenant->id()", order)
        self.assertIn("$material->tenant()->id() !== $this->tenant->id()", order)
        self.assertIn("$order->tenant()->id() !== $material->tenant()->id()", line)
        for key in (
            "FOREIGN KEY (tenant_id, supplier_id)",
            "FOREIGN KEY (tenant_id, order_id)",
            "FOREIGN KEY (tenant_id, material_id)",
        ):
            self.assertIn(key, migration)
        self.phpunit("testPurchaseOrderRejectsCrossTenantSupplierAndMaterial")

    def test_receive_is_idempotent_and_monotonic(self):
        order = ORDER.read_text(encoding="utf-8")
        service = SERVICE.read_text(encoding="utf-8")
        migration = MIGRATION.read_text(encoding="utf-8")
        for token in ("STATUS_RECEIVED", "assertSameReceipt", "markReceived",
                      "receiptSourceId", "normalizeReceiptKey"):
            self.assertIn(token, order)
        for token in ("wrapInTransaction", "LockMode::PESSIMISTIC_WRITE",
                      "assertSameReceipt", "$order->markReceived($key, $source->id())",
                      "'receiptKey' => $key"):
            self.assertIn(token, service)
        self.assertIn("UNIQUE INDEX uniq_purchasing_order_tenant_receipt (tenant_id, receipt_key)", migration)
        self.phpunit("testPurchaseOrderStateIsMonotonicAndIdempotent")

    def test_receive_delegates_stock_entry_to_material_inventory_service(self):
        service = SERVICE.read_text(encoding="utf-8")
        order = ORDER.read_text(encoding="utf-8")
        migration = MIGRATION.read_text(encoding="utf-8")
        self.assertIn("MaterialInventoryService", service)
        self.assertRegex(service, re.compile(
            r"\$this->materialInventory->adjust\(\s*\$tenant,\s*\$source,\s*"
            r"\$line->material\(\),\s*\$line->quantity\(\),", re.S))
        self.assertLess(service.index("->adjust("), service.index("$order->markReceived"))
        self.assertIn("'purchase_order_id' => $order->id()", service)
        self.assertIn("'purchase_order_line_id' => $line->id()", service)
        self.assertIn("['persist']", order)
        up = migration.split("public function up", 1)[1].split("public function down", 1)[0]
        self.assertEqual(2, len(re.findall(r"CREATE\s+TABLE", up, flags=re.I)))
        self.assertNotRegex(up, r"\b(DROP|DELETE|TRUNCATE|ALTER)\b")
        down = migration.split("public function down", 1)[1]
        self.assertIn("$this->abortIf(", down)
        self.phpunit("testQuantityCostAndDuplicateMaterialMustBeValid")


if __name__ == "__main__":
    unittest.main()
