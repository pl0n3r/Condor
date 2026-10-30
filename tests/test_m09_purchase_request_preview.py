#!/usr/bin/env python3
"""M09/S03: execute real PHP CLI against strictly synthetic purchase-request data."""
from __future__ import annotations

import json
from pathlib import Path
import subprocess
import unittest

ROOT = Path(__file__).resolve().parents[1]
SOURCE = ROOT / "src/Application/Purchasing/PurchaseRequestPreview.php"
TENANT = "fixture:tenant/demo-a"
ENTITY = "fixture:legal-entity/demo-a"
SITE = "fixture:site/demo-a"
SCOPE = {"tenant_ref": TENANT, "legal_entity_ref": ENTITY, "site_ref": SITE}
PHP = r"""
require $argv[1];
try {
    $value = json_decode($argv[2], true, 32, JSON_THROW_ON_ERROR);
    if (!is_array($value) || array_is_list($value)) {
        throw new InvalidArgumentException('INVALID_INPUT');
    }
    echo json_encode(['ok'=>\App\Application\Purchasing\PurchaseRequestPreview::preview($value)], JSON_THROW_ON_ERROR);
} catch (\Throwable $exception) {
    echo json_encode(['error'=>$exception instanceof \InvalidArgumentException ? $exception->getMessage() : 'UNKNOWN'], JSON_THROW_ON_ERROR);
}
"""


def line(**changes: object) -> dict:
    data = {"item_ref": "fixture:item/widget-1", **SCOPE, "quantity": "1.250"}
    data.update(changes)
    return data


def request(**changes: object) -> dict:
    data = {**SCOPE, "currency": "COP", "mode": "preview_only", "lines": [line()]}
    data.update(changes)
    return data


def quotation(**changes: object) -> dict:
    data = {"quotation_ref": "fixture:quotation/quote-1", **SCOPE}
    data.update(changes)
    return data


def run(data: object) -> dict:
    completed = subprocess.run(
        ["php", "-n", "-d", "allow_url_fopen=0", "-r", PHP, str(SOURCE), json.dumps(data)],
        cwd=ROOT, capture_output=True, text=True, check=True, timeout=20,
    )
    return json.loads(completed.stdout)


class PurchaseRequestPreviewTests(unittest.TestCase):
    def deny(self, data: object) -> None:
        result = run(data)
        self.assertIn("error", result)
        self.assertNotIn("ok", result)

    def test_scope_rejects_cross_tenant_company_site(self) -> None:
        self.assertEqual("pending", run(request())["ok"]["status"])
        for field, wrong in (
            ("tenant_ref", "fixture:tenant/demo-b"),
            ("legal_entity_ref", "fixture:legal-entity/demo-b"),
            ("site_ref", "fixture:site/demo-b"),
        ):
            with self.subTest(field=field):
                self.assertEqual("SCOPE_MISMATCH", run(request(lines=[line(**{field: wrong})]))["error"])
                self.deny(request(**{field: wrong}))
                self.deny(request(lines=[line(**{field: wrong})], **{field: wrong}, quotation=quotation()))
                missing = request()
                del missing[field]
                self.deny(missing)
        self.assertEqual("DUPLICATE_ITEM", run(request(lines=[line(), line()]))["error"])

    def test_only_synthetic_refs_and_no_personal_data(self) -> None:
        for bad in ("real-id", "someone@example.invalid", "https://example.invalid", "fixture:item/has spaces",
                    "fixture:tenant/a?token=secret", "fixture:tenant/x@y"):
            for field in SCOPE:
                with self.subTest(field=field, bad=bad):
                    self.deny(request(**{field: bad}))
                self.deny(request(lines=[line(**{field: bad})]))
            self.deny(request(lines=[line(item_ref=bad)]))
            self.deny(request(quotation=quotation(quotation_ref=bad)))
        for forbidden_field in ("email", "customer_name", "password", "token", "supplier_contact",
                                "address", "user_id", "notes", "database_url"):
            with self.subTest(extra=forbidden_field):
                self.deny(request(**{forbidden_field: "private"}))
                self.deny(request(lines=[line(**{forbidden_field: "private"})]))
                self.deny(request(quotation=quotation(**{forbidden_field: "private"})))
        # Cross-type fixture references must not masquerade as a tenant, site or item.
        wrong_kinds = {
            "tenant_ref": "fixture:site/demo-a",
            "legal_entity_ref": "fixture:tenant/demo-a",
            "site_ref": "fixture:legal-entity/demo-a",
        }
        for field, wrong in wrong_kinds.items():
            with self.subTest(field=field, wrong=wrong):
                self.deny(request(**{field: wrong}))
                self.deny(request(lines=[line(**{field: wrong})]))
                self.deny(request(quotation=quotation(**{field: wrong})))
        self.deny(request(lines=[line(item_ref="fixture:quotation/quote-1")]))
        self.deny(request(quotation=quotation(quotation_ref="fixture:item/widget-1")))
        code = SOURCE.read_text(encoding="utf-8").lower()
        for forbidden in ("curl_", "httpclient", "file_get_contents(", "file_put_contents(", "pdo(",
                          "mysqli", "doctrine", "getenv(", "http://", "https://", "shell_exec("):
            self.assertNotIn(forbidden, code)

    def test_pending_state_cannot_approve_order_pay_or_dispatch(self) -> None:
        # Every optional/limit branch must remain a non-operational preview.
        twenty = [line(item_ref=f"fixture:item/widget-{i}") for i in range(20)]
        cases = (
            ("base", request()),
            ("quoted", request(quotation=quotation())),
            ("two_lines", request(lines=[line(), line(item_ref="fixture:item/widget-2")])),
            ("quoted_two", request(lines=[line(), line(item_ref="fixture:item/widget-2")], quotation=quotation())),
            ("limit_20", request(lines=twenty)),
            ("limit_quantity", request(lines=[line(quantity="1000.000")])),
            ("quoted_limit_20_qty", request(
                lines=[*twenty[:-1], line(item_ref="fixture:item/widget-19", quantity="1000.000")],
                quotation=quotation(),
            )),
        )
        allowed = {"tenant_ref", "legal_entity_ref", "site_ref", "currency", "quotation_ref",
                   "status", "mode", "line_count", "lines", "can_order", "can_approve",
                   "can_pay", "can_dispatch", "persisted", "notified"}
        flags = ("can_order", "can_approve", "can_pay", "can_dispatch", "persisted", "notified")
        for name, data in cases:
            with self.subTest(case=name):
                output = run(data)["ok"]
                self.assertEqual(allowed, set(output))
                self.assertEqual("pending", output["status"])
                self.assertEqual("preview_only", output["mode"])
                self.assertEqual("COP", output["currency"])
                self.assertEqual(len(data["lines"]), output["line_count"])
                self.assertEqual(data.get("quotation", {}).get("quotation_ref"), output["quotation_ref"])
                self.assertEqual(SCOPE, {key: output[key] for key in SCOPE})
                for flag in flags:
                    self.assertIs(output[flag], False, flag)
                self.assertEqual(len(data["lines"]), len(output["lines"]))
                for original, projected in zip(data["lines"], output["lines"]):
                    self.assertEqual({"item_ref", "quantity"}, set(projected))
                    self.assertEqual(original["item_ref"], projected["item_ref"])
                    # The preview must not silently change a requested quantity,
                    # including multi-line and quotation boundary branches.
                    self.assertEqual(original["quantity"], projected["quantity"])
                self.assertEqual(output, run(data)["ok"])
        for action in ("approve", "pay", "dispatch", "order", "persist"):
            self.deny(request(mode=action))
            self.deny(request(**{action: True}))
        self.deny(request(currency="USD"))

    def test_quotation_reference_and_invalid_inputs_fail_closed(self) -> None:
        output = run(request(quotation=quotation()))["ok"]
        self.assertEqual("fixture:quotation/quote-1", output["quotation_ref"])
        self.assertIsNone(run(request())["ok"]["quotation_ref"])
        for field, bad in (("tenant_ref", "fixture:tenant/demo-b"),
                           ("legal_entity_ref", "fixture:legal-entity/demo-b"),
                           ("site_ref", "fixture:site/demo-b")):
            self.assertEqual("SCOPE_MISMATCH", run(request(quotation=quotation(**{field: bad})))["error"])
        for invalid in (None, False, 4, [], {}, "fixture:quotation/quote-1"):
            self.deny(request(quotation=invalid))
        for invalid_qty in (None, False, True, 1, "", "0", "0.000", "-1", "1e5", "1,2", "1.2345",
                            "1000.001", "1001", "123456", "NaN"):
            self.deny(request(lines=[line(quantity=invalid_qty)]))
        self.deny(request(lines=[]))
        self.deny(request(lines=[line(item_ref=f"fixture:item/widget-{i}") for i in range(21)]))
        border = [line(quantity="0.001"), line(item_ref="fixture:item/widget-2", quantity="1000.000")]
        self.assertEqual(["0.001", "1000.000"], [x["quantity"] for x in run(request(lines=border))["ok"]["lines"]])
        self.assertEqual(20, run(request(lines=[line(item_ref=f"fixture:item/widget-{i}") for i in range(20)]))["ok"]["line_count"])
        self.assertEqual("1.000", run(request(lines=[line(quantity="1")]))["ok"]["lines"][0]["quantity"])
        self.assertEqual("1.200", run(request(lines=[line(quantity="1.2")]))["ok"]["lines"][0]["quantity"])


if __name__ == "__main__":
    unittest.main()
