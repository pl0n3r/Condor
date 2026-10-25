#!/usr/bin/env python3
"""Executable PHP acceptance for the scoped Condor order CSV download."""
from __future__ import annotations

import csv
import io
import json
import shutil
import subprocess
import unittest
from pathlib import Path

ROOT = Path(__file__).resolve().parents[1]
CONTROLLER = ROOT / "src/Http/Controller/OrderExportController.php"
EXPORTER = ROOT / "src/Application/Orders/OrderCsvExporter.php"

PHP_SCENARIO = r'''
require 'src/Application/Orders/OrderCsvExporter.php';
$e = new App\Application\Orders\OrderCsvExporter();
$base = [
 'tenant_id'=>'tenant-A', 'source_id'=>'branch-A', 'id'=>'01TEST',
 'created_at'=>new DateTimeImmutable('2026-01-03 04:59:59', new DateTimeZone('UTC')),
 'customer_name'=>'=2+2', 'order_status'=>'open', 'payment_status'=>'pending',
 'fulfillment_status'=>'reserved', 'currency'=>'COP', 'total_amount_minor'=>12500,
];
$another = $base;
$another['id'] = '02TEST';
$another['customer_name'] = '  @HYPERLINK("url")';
$another['created_at'] = new DateTimeImmutable('2026-01-01 05:00:00', new DateTimeZone('UTC'));
$csv = $e->build('tenant-A', ['branch-A'], '2026-01-01', '2026-01-02', [$another,$base]);
$startEnd = $e->window('2026-01-01', '2026-01-02');
$cases = [];
$cases['tenant'] = $base + ['tenant_id'=>'tenant-B'];
$cases['tenant']['tenant_id'] = 'tenant-B';
$cases['source'] = $base; $cases['source']['source_id'] = 'branch-B';
$cases['out_of_range'] = $base;
$cases['out_of_range']['created_at'] = new DateTimeImmutable('2026-01-03 05:00:00', new DateTimeZone('UTC'));
$errors = [];
foreach ($cases as $key=>$row) {
 try { $e->build('tenant-A', ['branch-A'], '2026-01-01', '2026-01-02', [$row]); $errors[$key] = null; }
 catch (DomainException $err) { $errors[$key] = $err->getMessage(); }
}
foreach ([['2026-02-30','2026-03-01'],['2026-01-03','2026-01-01'],['2024-01-01','2026-01-01']] as $pair) {
 try { $e->window($pair[0], $pair[1]); $errors[implode('_', $pair)] = null; }
 catch (DomainException $err) { $errors[implode('_', $pair)] = $err->getMessage(); }
}
// The row limit is checked before rendering a full 10,001-row response.
try { $e->build('tenant-A', ['branch-A'], '2026-01-01', '2026-01-02', array_fill(0,10001,$base)); $errors['limit'] = null; }
catch (DomainException $err) { $errors['limit'] = $err->getMessage(); }
echo json_encode([
  'csv'=>$csv,
  'start'=>$startEnd[0]->format('Y-m-d H:i:sP'),
  'end'=>$startEnd[1]->format('Y-m-d H:i:sP'),
  'errors'=>$errors,
], JSON_THROW_ON_ERROR);
'''


class PedidosExportCsvTests(unittest.TestCase):
    def test_acceptance(self) -> None:
        php = shutil.which("php")
        self.assertIsNotNone(php, "PHP CLI es obligatorio")
        for path in (CONTROLLER, EXPORTER):
            self.assertTrue(path.is_file(), str(path))
            lint = subprocess.run([php, "-l", str(path)], cwd=ROOT,
                                  capture_output=True, text=True, check=False)
            self.assertEqual(0, lint.returncode, lint.stdout + lint.stderr)

        controller = CONTROLLER.read_text(encoding="utf-8")
        for key in (
            "'/api/orders/export.csv'", "methods: ['GET']", "'orders.view'",
            "$this->currentTenantForUser->resolve($user)",
            "'tenant' => $tenant", "o.tenant = :tenant",
            "o.inventorySource IN (:sources)", "o.createdAt >= :start AND o.createdAt < :end",
            "OrderCsvExporter::ROW_LIMIT + 1", "'Cache-Control' => 'private, no-store",
        ):
            with self.subTest(contract=key):
                self.assertIn(key, controller)

        run = subprocess.run([php, "-r", PHP_SCENARIO], cwd=ROOT,
                             capture_output=True, text=True, check=False)
        self.assertEqual(0, run.returncode, run.stdout + run.stderr)
        data = json.loads(run.stdout)
        rows = list(csv.reader(io.StringIO(data["csv"])))
        self.assertEqual(3, len(rows))
        self.assertEqual("Pedido", rows[0][0])
        self.assertEqual("01TEST", rows[2][0])
        self.assertEqual("2026-01-02 23:59:59", rows[2][1])
        self.assertEqual("'=2+2", rows[2][5])
        self.assertEqual("'  @HYPERLINK(\"url\")", rows[1][5])
        self.assertEqual("12500", rows[2][-1])
        self.assertEqual("2026-01-01 05:00:00+00:00", data["start"])
        self.assertEqual("2026-01-03 05:00:00+00:00", data["end"])
        self.assertEqual("ORDER_CSV_SCOPE_INVALID", data["errors"]["tenant"])
        self.assertEqual("ORDER_CSV_SCOPE_INVALID", data["errors"]["source"])
        self.assertEqual("ORDER_CSV_DATE_MISMATCH", data["errors"]["out_of_range"])
        self.assertEqual("ORDER_CSV_ROW_LIMIT", data["errors"]["limit"])
        for key in ("2026-02-30_2026-03-01", "2026-01-03_2026-01-01", "2024-01-01_2026-01-01"):
            self.assertIsNotNone(data["errors"][key], key)


if __name__ == "__main__":
    unittest.main()
