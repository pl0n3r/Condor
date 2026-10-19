#!/usr/bin/env python3
"""AC-01 #685: enforce branch/tenant scoping and idempotent purchasing HTTP API."""

from __future__ import annotations

import re
import shutil
import subprocess
import unittest
from pathlib import Path

ROOT = Path(__file__).resolve().parents[1]
CONTROLLER = ROOT / "src/Http/Controller/PurchasingController.php"
SUPPORT = ROOT / "src/Http/Controller/BranchApiSupport.php"
ORDERS = ROOT / "src/Application/Purchasing/PurchaseOrderService.php"
PHP_DOMAIN_TEST = ROOT / "tests/php/Domain/Purchasing/PurchaseOrderDomainTest.php"


class ComprasApiHttpTests(unittest.TestCase):
    def test_acceptance(self) -> None:
        controller = CONTROLLER.read_text(encoding="utf-8")
        support = SUPPORT.read_text(encoding="utf-8")
        service = ORDERS.read_text(encoding="utf-8")
        self.assertIn("use BranchApiSupport;", controller)
        self.assertIn("$this->currentTenantForUser->resolve($user)", support)
        self.assertIn("$this->authorization->require(", support)
        self.assertIn("'tenant' => $tenant", support)
        self.assertIn("requireCsrf(Request $request)", support)
        self.assertIn("array_diff(", support)

        for route in (
            "/purchasing/suppliers", "/purchasing/orders", "/receive"
        ):
            self.assertIn(route, controller)
        for permission in ("inventory.view", "inventory.create", "inventory.update"):
            self.assertIn(f"'{permission}'", controller)

        methods = re.findall(
            r"public function (\w+)\([^)]*\): JsonResponse\s*\{(.*?)(?=\n    (?:public|private) function|\n    /\*\*|\n    #\[Route|\n\})",
            controller,
            flags=re.S,
        )
        self.assertGreaterEqual(len(methods), 5)
        for name, body in methods:
            with self.subTest(action=name):
                self.assertIn("$this->authorizedBranchScope(", body)
                if name.startswith("create") or name.startswith("receive"):
                    self.assertIn("$this->requireCsrf($request)", body)

        for contract in (
            "'tenant' => $tenant",
            "'legalEntity' => $source->legalEntity()",
            "'branch' => $branch",
            "'active' => true",
            "'id' => $materialId",
            "'id' => $id",
        ):
            self.assertIn(contract, controller)
        self.assertIn("is_array($submitted)", controller)
        self.assertIn("array_is_list($submitted)", controller)
        self.assertIn("get_object_vars($line)", controller)
        self.assertIn("campos no permitidos", controller)
        self.assertNotIn("$payload['tenant_id']", controller)
        self.assertNotIn("$payload['legal_entity_id']", controller)
        self.assertNotIn("$payload['source_id']", controller)
        self.assertIn("$this->purchaseOrders->receive(", controller)
        self.assertIn("$alreadyReceived = $order->isReceived()", controller)
        self.assertIn("$alreadyReceived ? Response::HTTP_OK : Response::HTTP_CREATED", controller)
        self.assertIn("if (!$alreadyReceived)", controller)
        self.assertIn("$this->audit(", controller)
        self.assertIn("wrapInTransaction", service)
        self.assertIn("LockMode::PESSIMISTIC_WRITE", service)
        self.assertIn("$order->assertSameReceipt($key, $source->id())", service)

        php = shutil.which("php")
        self.assertIsNotNone(php, "El PHP CLI es necesario para validar la API")
        syntax = subprocess.run(
            [php, "-l", str(CONTROLLER)], capture_output=True, text=True,
            check=False,
        )
        self.assertEqual(0, syntax.returncode, syntax.stdout + syntax.stderr)
        runner = ROOT / "vendor/bin/simple-phpunit"
        self.assertTrue(runner.exists(), "PHPUnit instalado necesario para comprobar idempotencia")
        for pattern in (
            "testPurchaseOrderRejectsCrossTenantSupplierAndMaterial",
            "testPurchaseOrderStateIsMonotonicAndIdempotent",
            "testReceiptReplayAfterDeactivationAndLegalEntityIsolation",
        ):
            with self.subTest(domain=pattern):
                result = subprocess.run(
                    [str(runner), "--filter", pattern, str(PHP_DOMAIN_TEST)],
                    cwd=ROOT, capture_output=True, text=True, check=False,
                )
                self.assertEqual(0, result.returncode, result.stdout + result.stderr)


if __name__ == "__main__":
    unittest.main()
