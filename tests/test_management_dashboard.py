#!/usr/bin/env python3
"""#695 management dashboard: real-source, read-only and tenant-bound contracts."""
from pathlib import Path
import shutil
import subprocess
import unittest

ROOT = Path(__file__).resolve().parents[1]
CONTROLLER_PATH = ROOT / "src/Http/Controller/ManagementDashboardController.php"
PANEL_PATH = ROOT / "frontend/admin/ManagementDashboard.tsx"
CONTROLLER = CONTROLLER_PATH.read_text(encoding="utf-8")
PANEL = PANEL_PATH.read_text(encoding="utf-8")


class PanelGerencialV1Tests(unittest.TestCase):
    def test_acceptance(self):
        """Cada cifra usa fuente por ID y no incluye cifras de otro tenant."""
        self.assertIn("class ManagementDashboardController", CONTROLLER)
        self.assertIn("use BranchApiSupport;", CONTROLLER)
        self.assertIn("authorizedBranchScope($branchId, 'orders.view')", CONTROLLER)
        self.assertIn("require($user, $tenant, $branch, 'inventory.view')", CONTROLLER)
        self.assertIn("getRepository(InventorySource::class)->findOneBy([", CONTROLLER)
        self.assertIn("'tenant' => $tenant,", CONTROLLER)
        self.assertIn("'branch' => $branch,", CONTROLLER)
        for entity, scope in (
            ("Order", "'inventorySource' => $source"),
            ("InventoryBalance", "'source' => $source"),
            ("ProductionOrder", "'source' => $source"),
        ):
            with self.subTest(entity=entity):
                self.assertIn("getRepository(" + entity + "::class)->findBy([", CONTROLLER)
                self.assertIn(scope, CONTROLLER)
                self.assertIn("getRepository(" + entity + "::class)->findOneBy([", CONTROLLER)

        for kind in ("sale", "inventory", "production"):
            with self.subTest(kind=kind):
                self.assertIn("sourceUrl($branchId, '" + kind + "',", CONTROLLER)
                self.assertIn("$kind === '" + kind + "'", CONTROLLER)
        self.assertIn("name: 'api_management_source'", CONTROLLER)
        self.assertIn("return $this->generateUrl('api_management_source'", CONTROLLER)
        self.assertIn("new NotFoundHttpException(", CONTROLLER)
        self.assertIn("SourceLink branchId={branchId} row={", PANEL)
        self.assertIn("authorizedSourceHref", PANEL)
        self.assertIn("credentials: 'same-origin'", PANEL)
        self.assertIn("data.branch_id !== branchId", PANEL)
        self.assertIn("role=\"alert\"", PANEL)
        self.assertIn("state.status === 'loading'", PANEL)
        self.assertIn("state.status === 'denied'", PANEL)
        self.assertIn("state.status === 'error'", PANEL)
        self.assertIn("Sin saldos de inventario", PANEL)
        self.assertIn("Sin órdenes terminadas", PANEL)
        self.assertIn("app_management_dashboard", CONTROLLER)

    def test_security_and_financial_integrity(self):
        self.assertIn("methods: ['GET']", CONTROLLER)
        self.assertIn("->paymentStatus() !== Order::PAYMENT_PAID", CONTROLLER)
        self.assertIn("->fulfillmentStatus() !== Order::FULFILLMENT_CONSUMED", CONTROLLER)
        self.assertIn("->orderStatus() === Order::STATUS_CANCELLED", CONTROLLER)
        self.assertIn("ksort($byCurrency, SORT_STRING)", CONTROLLER)
        self.assertIn("'by_currency_minor' => $byCurrency", CONTROLLER)
        self.assertIn("'valuation_minor' => null", CONTROLLER)
        self.assertIn("'valuation_state' => 'UNKNOWN'", CONTROLLER)
        self.assertIn("->costSnapshot()", CONTROLLER)
        self.assertIn("self::isSavedCost($saved, $tenant->id(), $order->id())", CONTROLLER)
        self.assertIn("'ESTIMATED_COST'", CONTROLLER)
        self.assertIn("'currency' => null", CONTROLLER)
        self.assertIn("El período debe estar ordenado", CONTROLLER)
        self.assertIn("htmlspecialchars(", CONTROLLER)
        self.assertNotIn("profit_minor", CONTROLLER)
        self.assertNotIn("tenantId: string", PANEL)
        self.assertNotIn("POST", CONTROLLER)
        self.assertNotIn("DELETE", CONTROLLER)
        self.assertNotIn("hardcodedPrice", PANEL)

    def test_php_controller_syntax(self):
        php = shutil.which("php")
        if not php:
            self.skipTest("PHP CLI not present; PHP syntax is checked by Backend PHP CI")
        check = subprocess.run(
            [php, "-l", str(CONTROLLER_PATH)],
            cwd=ROOT,
            capture_output=True,
            text=True,
            timeout=30,
            check=False,
        )
        self.assertEqual(check.returncode, 0, check.stdout + check.stderr)


if __name__ == "__main__":
    unittest.main()
