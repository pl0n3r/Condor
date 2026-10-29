#!/usr/bin/env python3
"""Real PHP, synthetic-only M09/S02 quotation acceptance."""
from __future__ import annotations
from copy import deepcopy
import json
from pathlib import Path
import subprocess
import unittest

ROOT = Path(__file__).resolve().parents[1]
SOURCE = ROOT / "src/Application/Purchasing/QuotationPreview.php"
TENANT = "fixture:tenant/demo-a"
COMPANY = "fixture:legal-entity/demo-a"
SITE = "fixture:site/demo-a"
PHP = r"""
require $argv[1];
try {
    $data = json_decode($argv[2], true, 32, JSON_THROW_ON_ERROR);
    if (!is_array($data)) throw new InvalidArgumentException('INVALID_INPUT');
    echo json_encode(['ok'=>\App\Application\Purchasing\QuotationPreview::preview($data)], JSON_THROW_ON_ERROR);
} catch (\Throwable $e) {
    echo json_encode(['error'=>$e instanceof \InvalidArgumentException ? $e->getMessage() : 'UNKNOWN'], JSON_THROW_ON_ERROR);
}
"""

def line(**changes: object) -> dict:
    row = {"item_ref":"fixture:item/item-1","tenant_ref":TENANT,
           "legal_entity_ref":COMPANY,"site_ref":SITE,
           "quantity":"1.250","unit_price_cop":"1000.25"}
    row.update(changes)
    return row

def quote(**changes: object) -> dict:
    data = {"tenant_ref":TENANT,"legal_entity_ref":COMPANY,
            "site_ref":SITE,"currency":"COP","mode":"preview_only",
            "lines":[line()]}
    data.update(changes)
    return data

def run(data: object) -> dict:
    result = subprocess.run(
        ["php","-n","-d","allow_url_fopen=0","-r",PHP,str(SOURCE),
         json.dumps(data)], cwd=ROOT, capture_output=True,
        text=True, timeout=20, check=True)
    return json.loads(result.stdout)

class QuotationPreviewTests(unittest.TestCase):
    def deny(self, data: object) -> None:
        result = run(data)
        self.assertIn("error", result)
        self.assertNotIn("ok", result)

    def test_isolation_by_tenant_company_and_branch(self) -> None:
        self.assertEqual("SYNTHETIC_PREVIEW", run(quote())["ok"]["status"])
        for field, wrong in (("tenant_ref","fixture:tenant/demo-b"),
                             ("legal_entity_ref","fixture:legal-entity/demo-b"),
                             ("site_ref","fixture:site/demo-b")):
            with self.subTest(field=field):
                row = line(**{field:wrong})
                self.assertEqual("SCOPE_MISMATCH",run(quote(lines=[row]))["error"])
                changed = quote(**{field:wrong})
                self.deny(changed)
                changed = quote()
                del changed[field]
                self.deny(changed)
        self.deny(quote(lines=[line(),line()]))

    def test_cop_totals_rounding_and_invalid_amounts_fail_closed(self) -> None:
        rows = [line(),line(item_ref="fixture:item/item-2",
                            quantity="0.001",unit_price_cop="500.00")]
        result = run(quote(lines=rows))["ok"]
        self.assertEqual("COP",result["currency"])
        self.assertEqual([1250,1],[r["line_total_cop"] for r in result["lines"]])
        self.assertEqual(1251,result["total_cop"])
        self.assertEqual(result,run(quote(lines=rows))["ok"])
        for field,bad in (("quantity","0"),("quantity","-1"),
                          ("quantity","1e5"),("quantity","1.2345"),
                          ("quantity","1,5"),("quantity","1001"),
                          ("unit_price_cop","0"),("unit_price_cop","NaN"),
                          ("unit_price_cop","9.999"),("unit_price_cop","1000001"),
                          ("unit_price_cop",10),("quantity",3)):
            with self.subTest(field=field,bad=bad):
                self.deny(quote(lines=[line(**{field:bad})]))
        self.deny(quote(lines=[]))
        self.deny(quote(lines=[line(item_ref=f"fixture:item/item-{i}")
                                for i in range(21)]))
        self.deny(quote(currency="USD"))

    def test_only_fixture_refs_no_network_storage_or_personal_data(self) -> None:
        for bad in ("https://example.invalid/person","demo@example.invalid",
                    "real-tenant","fixture:item/has spaces",
                    "fixture:site/demo-b?token=abc","fixture:tenant/x@example"):
            for field in ("tenant_ref","legal_entity_ref","site_ref"):
                with self.subTest(field=field,bad=bad):
                    self.deny(quote(**{field:bad}))
            self.deny(quote(lines=[line(item_ref=bad)]))
        for extra in ("email","password","actor_user_id","recipient",
                      "notes","database_url","customer_name"):
            payload = quote()
            payload[extra] = "private"
            self.deny(payload)
            self.deny(quote(lines=[line(**{extra:"private"})]))
        source = SOURCE.read_text(encoding="utf-8").lower()
        for forbidden in ("curl_","httpclient","file_get_contents(","pdo(",
                          "doctrine","mysqli","file_put_contents(","getenv(",
                          "http://","https://","shell_exec("):
            self.assertNotIn(forbidden,source)

    def test_no_order_payment_approval_or_external_dispatch(self) -> None:
        result = run(quote())["ok"]
        self.assertEqual("preview_only",result["mode"])
        self.assertEqual(1250,result["total_cop"])
        for field in ("can_order","can_pay","can_approve","can_dispatch","persisted"):
            self.assertIs(result[field],False)
        for field,value in (("mode","approve"),("order_id","fixture:order/1"),
                            ("status","APPROVED"),("dispatch",True),
                            ("payment_ref","fixture:payment/1")):
            self.deny(quote(**{field:value}))
        self.assertNotIn("customer_name",json.dumps(result))

if __name__ == "__main__":
    unittest.main()
