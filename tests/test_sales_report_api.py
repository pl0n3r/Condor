#!/usr/bin/env python3
"""Condor #849: offline acceptance of product/month sales, scoped per tenant/branch."""
import json
import shutil
import subprocess
import unittest
from pathlib import Path

ROOT = Path(__file__).resolve().parents[1]
READER = ROOT / "src/Application/Reports/SalesReportReader.php"
CONTROLLER = ROOT / "src/Http/Controller/SalesReportController.php"
BASE = dict(
    tenant_id="t-a", source_id="s-1", order_status="open",
    occurred_at="2026-10-02", currency="COP", quantity=2,
    revenue_minor=500, variant_id="v-1", product_id="p-1",
    sku="SKU-1", variant_name="Uno", product_name="Producto A",
)


class VentasReporteApiTests(unittest.TestCase):
    def php(self, action="aggregate", **arguments):
        php = shutil.which("php")
        self.assertIsNotNone(php, "PHP CLI required for AC-01")
        program = r"""
require $argv[1];
$p = json_decode(stream_get_contents(STDIN), true, 64, JSON_THROW_ON_ERROR);
try {
  if ($p['action'] === 'window') {
    [$a, $b] = \App\Application\Reports\SalesReportReader::window($p['from'], $p['to']);
    $data = [$a->format('Y-m-d'), $b->format('Y-m-d')];
  } else {
    $data = \App\Application\Reports\SalesReportReader::aggregate(
      $p['tenant'], $p['sources'], $p['rows'], $p['dimension']
    );
  }
  echo json_encode(['ok' => true, 'data' => $data], JSON_THROW_ON_ERROR);
} catch (\DomainException $e) {
  echo json_encode(['ok' => false, 'reason' => $e->getMessage()], JSON_THROW_ON_ERROR);
}
"""
        result = subprocess.run(
            [php, "-r", program, str(READER)],
            input=json.dumps(dict(action=action, **arguments)),
            cwd=ROOT, capture_output=True, text=True, check=False,
        )
        self.assertEqual(result.returncode, 0, result.stdout + result.stderr)
        return json.loads(result.stdout)

    def rows(self):
        return [
            BASE,
            dict(BASE, occurred_at="2026-10-08", quantity=1, revenue_minor=250),
            dict(BASE, occurred_at="2026-11-02", quantity=3, revenue_minor=600,
                 product_id="p-2", variant_id="v-2", sku="SKU-2"),
            dict(BASE, source_id="s-2", currency="USD", quantity=1, revenue_minor=100),
            dict(BASE, order_status="cancelled", revenue_minor=999, quantity=99),
        ]

    def test_acceptance(self):
        php = shutil.which("php")
        self.assertIsNotNone(php, "PHP CLI required for exact acceptance")
        self.assertTrue(READER.is_file())
        self.assertTrue(CONTROLLER.is_file())
        for path in (READER, CONTROLLER):
            lint = subprocess.run([php, "-l", str(path)], capture_output=True, text=True)
            self.assertEqual(lint.returncode, 0, lint.stdout + lint.stderr)

        controller = CONTROLLER.read_text(encoding="utf-8")
        reader = READER.read_text(encoding="utf-8")
        for url in ("/api/reports/sales-by-product", "/api/reports/sales-by-month"):
            self.assertIn(url, controller)
        for contract in ("CurrentTenantForUser", "orders.view", "allowedBranches",
                         "'tenant' => $tenant", "'active' => true", "no-store"):
            self.assertIn(contract, controller)
        for guard in ("l.tenant = :tenant", "o.tenant = :tenant",
                      "o.inventorySource IN (:sources)", "o.orderStatus = :open",
                      "setMaxResults(self::MAX_ROWS + 1)"):
            self.assertIn(guard, reader)

        args = dict(tenant="t-a", sources=["s-1", "s-2"], rows=self.rows())
        product = self.php(dimension="product", **args)
        self.assertTrue(product["ok"], product)
        values = {(x["currency"], x["variant_id"]): x for x in product["data"]["items"]}
        self.assertEqual(values[("COP", "v-1")]["units"], 3)
        self.assertEqual(values[("COP", "v-1")]["revenue_minor"], 750)
        self.assertEqual(values[("COP", "v-2")]["revenue_minor"], 600)
        self.assertEqual(values[("USD", "v-1")]["revenue_minor"], 100)
        self.assertEqual(values[("COP", "v-1")]["participation_bps"], 5556)
        self.assertEqual(values[("COP", "v-2")]["participation_bps"], 4444)
        self.assertEqual(product["data"]["rows_scanned"], 4)
        totals = {v["currency"]: v["revenue_minor"] for v in product["data"]["currency_totals"]}
        self.assertEqual(totals, {"COP": 1350, "USD": 100})

        monthly = self.php(dimension="month", **args)
        self.assertTrue(monthly["ok"], monthly)
        month = {(x["month"], x["currency"]): x["revenue_minor"] for x in monthly["data"]["items"]}
        self.assertEqual(month, {("2026-10", "COP"): 750, ("2026-10", "USD"): 100,
                                 ("2026-11", "COP"): 600})

        for altered in (dict(args, tenant="t-b"), dict(args, sources=["s-1"])):
            forbidden = self.php(dimension="product", **altered)
            self.assertFalse(forbidden["ok"])
            self.assertEqual(forbidden["reason"], "REPORT_SCOPE_INVALID")

    def test_range_validation_and_money_overflow(self):
        for start, end in (("2026-02-30", "2026-03-01"),
                           ("2026-10-05", "2026-10-04"),
                           ("2026-01-01", "2027-01-02"),
                           ("2026-01-01;", "2026-01-02")):
            invalid = self.php("window", **{"from": start, "to": end})
            self.assertFalse(invalid["ok"])
        correct = self.php("window", **{"from": "2026-01-01", "to": "2026-12-31"})
        self.assertEqual(correct, {"ok": True, "data": ["2026-01-01", "2027-01-01"]})
        overflow = self.php(dimension="product", tenant="t-a", sources=["s-1"],
                            rows=[dict(BASE, revenue_minor=9007199254740991),
                                  dict(BASE, revenue_minor=1)])
        self.assertFalse(overflow["ok"])
        self.assertEqual(overflow["reason"], "REPORT_AMOUNT_OVERFLOW")


if __name__ == "__main__":
    unittest.main()
