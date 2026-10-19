#!/usr/bin/env python3
"""#693: offline acceptance of tenant-scoped CSV inventory, sales, and purchases."""
import csv
import io
import json
import shutil
import subprocess
import unittest
from pathlib import Path

ROOT = Path(__file__).resolve().parents[1]
SERVICE = ROOT / "src/Application/Reporting/TenantCsvReportService.php"


class ReportesCsvBaseTests(unittest.TestCase):
    def run_php(self, kind, tenant, start, end, rows):
        executable = shutil.which("php")
        if not executable:
            self.fail("PHP CLI unavailable: CSV acceptance cannot be verified")
        program = r'''
require $argv[1];
$payload = json_decode(stream_get_contents(STDIN), true, 32, JSON_THROW_ON_ERROR);
try {
    $data = (new \App\Application\Reporting\TenantCsvReportService())->build(
        $payload['kind'], $payload['tenant'], $payload['from'], $payload['through'], $payload['rows']
    );
    echo json_encode(['ok' => true, 'data' => $data], JSON_THROW_ON_ERROR);
} catch (\DomainException $e) {
    echo json_encode(['ok' => false, 'reason' => $e->getMessage()], JSON_THROW_ON_ERROR);
}
'''
        result = subprocess.run(
            [executable, "-r", program, str(SERVICE)],
            input=json.dumps({"kind": kind, "tenant": tenant, "from": start,
                              "through": end, "rows": rows}),
            cwd=ROOT, capture_output=True, text=True, check=False,
        )
        self.assertEqual(result.returncode, 0, result.stdout + result.stderr)
        return json.loads(result.stdout)

    def test_acceptance(self):
        schemas = {
            "inventory": ("record_id", "occurred_at", "source_id", "variant_id", "quantity", "reserved_quantity"),
            "sales": ("order_id", "occurred_at", "currency", "total_amount_minor", "payment_status"),
            "purchases": ("purchase_order_id", "occurred_at", "supplier_id", "status", "total_cost_minor"),
        }
        for kind, fields in schemas.items():
            with self.subTest(kind=kind):
                def row(record_id, tenant, date):
                    result = dict(zip(fields, [record_id, date, "test,source", 'quote"and,comma', "12", "3"]))
                    for field in fields[6:]:
                        result[field] = "3"
                    result["tenant_id"] = tenant
                    result["occurred_at"] = date
                    return result

                rows = [row("old", "tenant-a", "2026-10-01"),
                        row("first", "tenant-a", "2026-10-02"),
                        row("other-tenant", "tenant-b", "2026-10-02"),
                        row("last", "tenant-a", "2026-10-04"),
                        row("future", "tenant-a", "2026-10-05")]
                result = self.run_php(kind, "tenant-a", "2026-10-02", "2026-10-04", rows)
                self.assertTrue(result["ok"], result)
                data = result["data"]
                self.assertEqual(data["content_type"], "text/csv; charset=utf-8")
                self.assertEqual(data["filename"], f"{kind}-2026-10-02-2026-10-04.csv")
                parsed = list(csv.reader(io.StringIO(data["body"])))
                self.assertEqual(tuple(parsed[0]), fields)
                self.assertEqual([row[0] for row in parsed[1:]], ["first", "last"])
                self.assertNotIn("other-tenant", data["body"])
                self.assertIn("test,source", data["body"])
                empty = self.run_php(kind, "tenant-a", "2026-09-01", "2026-09-30", rows)
                self.assertTrue(empty["ok"])
                self.assertEqual(list(csv.reader(io.StringIO(empty["data"]["body"]))), [list(fields)])

    def test_invalid_date_and_range_fail_closed(self):
        for start, end in (("2026-02-30", "2026-03-02"),
                           ("2026-10-05", "2026-10-01"),
                           ("2026-10-01; drop", "2026-10-05")):
            response = self.run_php("sales", "tenant-a", start, end, [])
            self.assertFalse(response["ok"])
            self.assertIn(response["reason"], ("INVALID_REPORT_RANGE", "INVALID_REPORT_DATE"))

    def test_formula_injection_and_quoted_content(self):
        rows = [{"tenant_id": "a", "order_id": "=HYPERLINK(\"evil\")",
                 "occurred_at": "2026-10-19", "currency": "COP",
                 "total_amount_minor": "123", "payment_status": "@SUM(1)"}]
        data = self.run_php("sales", "a", "2026-10-19", "2026-10-19", rows)
        self.assertTrue(data["ok"])
        parsed = list(csv.reader(io.StringIO(data["data"]["body"])))
        self.assertTrue(parsed[1][0].startswith("'="))
        self.assertTrue(parsed[1][-1].startswith("'@"))

    def test_invalid_records_or_kind_fail_closed(self):
        for kind, tenant, rows in (("unknown", "a", []), ("inventory", "", []),
                                   ("sales", "a", [{"tenant_id": "a", "occurred_at": "2026-10-19"}])):
            result = self.run_php(kind, tenant, "2026-10-19", "2026-10-19", rows)
            self.assertFalse(result["ok"])


if __name__ == "__main__":
    unittest.main()
