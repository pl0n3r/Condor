#!/usr/bin/env python3
"""Condor #850: executable offline acceptance of physical-count CSV contract."""
import json
import shutil
import subprocess
import unittest
from pathlib import Path

ROOT = Path(__file__).resolve().parents[1]
SERVICE = ROOT / "src/Application/Inventory/StockCsvAdjuster.php"
CONTROLLER = ROOT / "src/Http/Controller/InventoryCsvAdjustController.php"
B1 = "01ARZ3NDEKTSV4RRFFQ69G5FAV"
B2 = "01ARZ3NDEKTSV4RRFFQ69G5FAW"


class InventarioAjusteCsvApiTests(unittest.TestCase):
    def php(self, csv, tenant="t-a"):
        php = shutil.which("php")
        self.assertIsNotNone(php, "PHP CLI requerido para la aceptación AC-01")
        script = r"""
require $argv[1];
$input = json_decode(stream_get_contents(STDIN), true, 16, JSON_THROW_ON_ERROR);
try {
    $p = \App\Application\Inventory\StockCsvAdjuster::parseCsv($input['csv']);
    $keys = array_map(
        static fn($r) => \App\Application\Inventory\StockCsvAdjuster::rowKey(
            $input['tenant'], $p['sha256'], $r['line']
        ),
        $p['rows']
    );
    echo json_encode(['ok'=>true,'parsed'=>$p,'keys'=>$keys], JSON_THROW_ON_ERROR);
} catch (\DomainException $e) {
    echo json_encode(['ok'=>false,'error'=>$e->getMessage()], JSON_THROW_ON_ERROR);
}
"""
        x = subprocess.run(
            [php, "-r", script, str(SERVICE)],
            input=json.dumps({"csv": csv, "tenant": tenant}),
            cwd=ROOT, text=True, capture_output=True, check=False,
        )
        self.assertEqual(x.returncode, 0, x.stdout + x.stderr)
        return json.loads(x.stdout)

    def test_acceptance(self):
        php = shutil.which("php")
        self.assertIsNotNone(php, "PHP CLI requerido")
        for file in (SERVICE, CONTROLLER):
            result = subprocess.run([php, "-l", str(file)], text=True, capture_output=True)
            self.assertEqual(result.returncode, 0, result.stderr + result.stdout)
        controller = CONTROLLER.read_text(encoding="utf-8")
        service = SERVICE.read_text(encoding="utf-8")
        for route in ("/api/inventory/adjust-csv/preview", "/api/inventory/adjust-csv/commit"):
            self.assertIn(route, controller)
        for guard in ("requireCsrf", "CurrentTenantForUser", "inventory.update",
                      "hasAnyPermission", "Cache-Control"):
            self.assertIn(guard, controller)
        for guard in ("InventoryService", "wrapInTransaction", "PESSIMISTIC_WRITE",
                      "InventoryMovement", "idempotencyKey", "inventory.csv_adjusted",
                      "BranchAuthorization", "'tenant' => $tenant"):
            self.assertIn(guard, service)

        csv = f"sku,branch_id,quantity\nSKU-1,{B1},10\nSKU-2,{B2},0\n"
        result = self.php(csv)
        self.assertTrue(result["ok"], result)
        rows = result["parsed"]["rows"]
        self.assertEqual([(r["sku"], r["quantity"]) for r in rows], [("SKU-1", 10), ("SKU-2", 0)])
        self.assertEqual(rows[0]["branch_id"], B1)
        self.assertEqual(len(result["keys"]), 2)
        replay = self.php(csv)
        self.assertEqual(replay["keys"], result["keys"])
        self.assertNotEqual(self.php(csv, tenant="t-b")["keys"], result["keys"])
        changed = self.php(csv.replace(",10", ",11"))
        self.assertNotEqual(changed["keys"], result["keys"])
        self.assertLessEqual(max(map(len, result["keys"])), 120)

        invalid = f"sku,branch_id,quantity\nSKU-1,{B1},3\nSKU-1,{B1},4\nSKU-2,{B2},-1\nSKU-3,{B2},8\n"
        mixed = self.php(invalid)
        self.assertTrue(mixed["ok"], mixed)
        self.assertEqual([r["sku"] for r in mixed["parsed"]["rows"]], ["SKU-1", "SKU-3"])
        self.assertEqual([r["error"] for r in mixed["parsed"]["errors"]],
                         ["CSV_DUPLICATE_SKU_BRANCH", "CSV_ROW_INVALID"])

        for csv in ("", "sku,branch_id,quantity\n", "sku,quantity,branch_id\nSKU,foo,1\n",
                    "sku,branch_id,quantity\n" + f"A,{B1},1\n" * 101):
            bad = self.php(csv)
            self.assertFalse(bad["ok"], bad)
        self.assertFalse(self.php("sku,branch_id,quantity\n\x00")["ok"])

    def test_isolation_and_guards(self):
        service = SERVICE.read_text(encoding="utf-8")
        self.assertIn("['id' => $row['branch_id'], 'tenant' => $tenant]", service.replace("\n", " ").replace("    ", " "))
        self.assertIn("'tenant' => $tenant, 'sku' => $row['sku']", service)
        self.assertIn("'inventory.update'", service)
        self.assertIn("'source' => $source", service)
        self.assertIn("reservedQuantity()", service)
        self.assertIn("source()->id()", service)
        self.assertIn("variant()->id()", service)
        self.assertIn("reason' => 'conteo CSV'", service)


if __name__ == "__main__":
    unittest.main()
