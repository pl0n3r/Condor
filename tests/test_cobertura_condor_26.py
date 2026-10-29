#!/usr/bin/env python3
"""Condor #1345: real, offline operational journey subcontracts.

Only real PHP paths with synthetic input count as exercised. Journey headings,
future integrations, production readiness and matrix reconciliation are not
inferred from these tests. Non-executable rows are classified in the PR/issue.
"""
from __future__ import annotations

import csv
import io
import json
from pathlib import Path
import shutil
import subprocess
import sys
import tempfile
import unittest
from unittest import mock

ROOT = Path(__file__).resolve().parents[1]
TESTS = ROOT / "tests"
if str(TESTS) not in sys.path:
    sys.path.insert(0, str(TESTS))
import test_cobertura_condor_25 as operational
import test_cobertura_condor_21 as purchasing


def quote(*, lines=None, currency="COP", mode="preview_only"):
    data = operational.quotation()
    data["currency"] = currency
    data["mode"] = mode
    if lines is not None:
        data["lines"] = lines
    return purchasing.php_run(
        purchasing.QUOTE_PHP,
        ROOT / "src/Application/Purchasing/QuotationPreview.php",
        data=data,
    )


def stock(tenant="fixture:tenant/alpha", csv_body=None):
    return operational.php_run(operational.STOCK_PHP, [operational.STOCK], {
        "tenant": tenant,
        "csv": operational.physical_count() if csv_body is None else csv_body,
    })


def purchasing_order():
    return purchasing.php_run(
        purchasing.DOMAIN_PHP,
        ROOT / "src/Domain/Purchasing/Entity/Supplier.php",
        str(ROOT / "src/Domain/Purchasing/Entity/PurchaseOrderLine.php"),
        str(ROOT / "src/Domain/Purchasing/Entity/PurchaseOrder.php"),
    )


def checkout_response():
    """Execute the original checkout controller through its isolated PHP fixture."""
    fixture = operational.checkout.FIXTURE.replace(
        "__ITEMS__", json.dumps(str(operational.checkout.ITEM_SUPPORT))
    ).replace(
        "__CONTROLLER__", json.dumps(str(operational.checkout.CONTROLLER))
    )
    with tempfile.TemporaryDirectory() as directory:
        path = Path(directory) / "checkout_fixture.php"
        path.write_text(fixture, encoding="utf-8")
        process = subprocess.run(
            [shutil.which("php") or "php", "-d", "allow_url_fopen=0", str(path)],
            cwd=ROOT, text=True, capture_output=True, timeout=40, check=False,
        )
    if process.returncode:
        raise AssertionError("Real checkout fixture failed: " + process.stderr[-1100:])
    return json.loads(process.stdout)


def tenant_report(records):
    return operational.php_run(operational.REPORTS_PHP, [operational.REPORTS], {
        "kind": "inventory", "tenant": "tenant-a", "from": "2026-10-20",
        "through": "2026-10-21", "rows": records,
    })


class CoberturaCondor26Tests(unittest.TestCase):
    def test_contrato_principal(self):
        """AC-01: eight executable partial subcontracts, not twelve live claims."""
        # OPS-REC-A-P3: the real checkout controller rejects a client total,
        # recomputes a COP total server-side, and denies a foreign variant.
        with self.subTest(row="OPS-REC-A-P3"):
            checkout = checkout_response()
            self.assertEqual(201, checkout["valid_status"])
            self.assertEqual(5600, checkout["tenant_a_server_total"])
            self.assertEqual(422, checkout["client_total"])
            self.assertEqual(404, checkout["foreign_variant"])
            preview = quote()
            self.assertEqual(("COP", 2461, False),
                             (preview["currency"], preview["total_cop"], preview["can_pay"]))

        # OPS-REC-A-P4: real Customer/category entities enforce same tenant.
        # The optional workflow remains a product choice, not an E2E assertion.
        with self.subTest(row="OPS-REC-A-P4"):
            clients = operational.php_run(operational.CUSTOMERS_PHP, operational.CATEGORY_FILES)
            self.assertEqual("minoristas", clients["category"])
            self.assertEqual("fixture-tenant-a", clients["tenant"])
            self.assertTrue(clients["still_same_category"])
            self.assertEqual([True] * 5, clients["denied"])

        # OPS-REC-B-P1: real branch-scoped stock CSV and COP quote contract.
        with self.subTest(row="OPS-REC-B-P1"):
            counted = stock()
            self.assertTrue(counted["ok"])
            self.assertEqual([("SKU-TEST", operational.BRANCH, 12)], [
                (r["sku"], r["branch_id"], r["quantity"])
                for r in counted["parsed"]["rows"]
            ])
            self.assertEqual(2461, quote()["total_cop"])

        # OPS-REC-B-P2: ProductionOrder is the real BOM/order domain class.
        with self.subTest(row="OPS-REC-B-P2"):
            produced = operational.php_run(operational.PRODUCTION_PHP, [operational.PRODUCTION])
            self.assertEqual(("draft", "completed", 3),
                             (produced["before"], produced["after"], produced["completed"]))
            self.assertEqual("fixture-completion", produced["completion_key"])
            self.assertEqual([True] * 7, produced["denied"])

        # OPS-REC-B-P3: real purchase order receipt is scoped and idempotent.
        with self.subTest(row="OPS-REC-B-P3"):
            purchase = purchasing_order()
            self.assertEqual(("draft", "received", True),
                             (purchase["before"], purchase["after"], purchase["received"]))
            self.assertEqual(1, purchase["line_count"])
            self.assertEqual([True] * 8, purchase["denials"])

        # OPS-REC-C-P1: tenant identity affects keys; a foreign site is denied
        # by the real ProductionOrder invariant (not claimed as UI permissions).
        with self.subTest(row="OPS-REC-C-P1"):
            a = stock("fixture:tenant/alpha")
            b = stock("fixture:tenant/beta")
            self.assertTrue(a["ok"] and b["ok"])
            self.assertNotEqual(a["keys"], b["keys"])
            self.assertTrue(operational.php_run(
                operational.PRODUCTION_PHP, [operational.PRODUCTION]
            )["denied"][0])

        # OPS-REC-C-P2: real checkout + catalog pricing + customer entity.
        with self.subTest(row="OPS-REC-C-P2"):
            self.assertEqual("SYNTHETIC_PREVIEW", quote()["status"])
            self.assertEqual(422, checkout_response()["client_total"])
            self.assertEqual("minoristas", operational.php_run(
                operational.CUSTOMERS_PHP, operational.CATEGORY_FILES
            )["category"])

        # OPS-REC-C-P3: purchasing receipt + real tenant-filtered CSV renderer.
        with self.subTest(row="OPS-REC-C-P3"):
            self.assertTrue(purchasing_order()["received"])
            response = tenant_report([
                operational.report_record("tenant-a", "local", "2026-10-20"),
                operational.report_record("tenant-b", "foreign", "2026-10-20"),
                operational.report_record("tenant-a", "late", "2026-10-22"),
            ])
            self.assertTrue(response["ok"])
            rows = list(csv.reader(io.StringIO(response["data"]["body"])))
            self.assertEqual(["local"], [row[0] for row in rows[1:]])
            self.assertNotIn("foreign", response["data"]["body"])

    def test_falla_cerrado(self):
        """AC-02: bad scope, permissions, currency, stock and tenant fail closed."""
        for malformed in (
            {"currency": "USD"}, {"mode": "create_order"},
            {"lines": [{**operational.quotation()["lines"][0], "quantity": "0"}]},
            {"lines": [{**operational.quotation()["lines"][0],
                        "tenant_ref": "fixture:tenant/beta"}]},
        ):
            with self.subTest(quote=malformed):
                payload = operational.quotation()
                payload.update(malformed)
                rejected = purchasing.php_run(
                    purchasing.QUOTE_PHP,
                    ROOT / "src/Application/Purchasing/QuotationPreview.php",
                    data=payload,
                )
                self.assertIn("error", rejected)
                self.assertNotIn("total_cop", rejected)
        branch = stock(csv_body="sku,branch_id,quantity\nSKU-TEST,bad-site,5\n")
        self.assertTrue(branch["ok"])
        self.assertEqual([], branch["parsed"]["rows"])
        self.assertEqual("CSV_ROW_INVALID", branch["parsed"]["errors"][0]["error"])
        duplicate = stock(csv_body=operational.physical_count() +
                          f"SKU-TEST,{operational.BRANCH},8\n")
        self.assertEqual("CSV_DUPLICATE_SKU_BRANCH",
                         duplicate["parsed"]["errors"][0]["error"])
        denied = tenant_report([
            {"tenant_id": "tenant-a", "occurred_at": "2026-10-20"}
        ])
        self.assertFalse(denied["ok"])
        self.assertEqual("INVALID_REPORT_COLUMN", denied["reason"])
        self.assertEqual([True] * 7, operational.php_run(
            operational.PRODUCTION_PHP, [operational.PRODUCTION]
        )["denied"])

    def test_sin_datos_reales(self):
        """AC-03: only synthetic tenant IDs, no providers, network or live."""
        data = json.loads((ROOT / "datos.yml").read_text(encoding="utf-8"))
        self.assertEqual("construccion", data["phase"])
        self.assertTrue(data["d063_attestation"]["nothing_live"])
        self.assertTrue(data["d063_attestation"]["no_real_customer_data"])
        self.assertTrue(all(line["item_ref"].startswith("fixture:")
                            for line in operational.quotation()["lines"]))
        with mock.patch("socket.create_connection",
                        side_effect=AssertionError("network forbidden")):
            result = quote()
            self.assertFalse(result["persisted"])
            self.assertFalse(result["can_order"])
            self.assertFalse(result["can_pay"])
            self.assertTrue(stock()["ok"])


if __name__ == "__main__":
    unittest.main()
