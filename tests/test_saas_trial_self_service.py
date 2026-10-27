#!/usr/bin/env python3
"""#1067 ACs: execute actual PHP with synthetic fixtures only, no mocks."""
from __future__ import annotations

import copy
import json
import subprocess
import unittest
from pathlib import Path

ROOT = Path(__file__).resolve().parents[1]
MODULE = ROOT / "src/Application/Commercial/TrialAndPlanChangeService.php"
CONTROLLER = ROOT / "src/Http/Controller/PublicTrialController.php"


def fixture(tenant="a", company="a", action="trial"):
    tid, cid = f"fixture:tenant/{tenant}", f"fixture:company/{company}"
    quantities = {"users": 3, "locations": 1, "companies": 1}
    plan = "fixture:plan/business" if action == "trial" else "fixture:plan/pro"
    quote = {"tenant_ref": tid, "company_ref": cid, "plan_ref": plan,
             "vertical_ref": "fixture:vertical/commerce", "cycle": "monthly",
             "quantities": quantities, "addons": [], "amount_minor": 20000,
             "quote_required": False}
    subscription = {"tenant_ref": tid, "company_ref": cid,
                    "state": "not_configured" if action == "trial" else "active",
                    "plan_ref": None if action == "trial" else "fixture:plan/basic",
                    "current_amount_minor": None if action == "trial" else 10000,
                    "renews_on": None if action == "trial" else "2026-11-27",
                    "usage_compatible": True}
    request = {"tenant_ref": tid, "company_ref": cid,
               "request_ref": "fixture:request/one", "action": action,
               "plan_ref": plan, "vertical_ref": quote["vertical_ref"],
               "cycle": "monthly", "quantities": quantities, "addons": []}
    return {"tenant_ref": tid, "company_ref": cid,
            "actor_ref": "fixture:actor/admin", "actor_role": "tenant_admin",
            "permission": "commercial.manage", "catalog_quote": quote,
            "subscription": subscription, "request": request}


def evaluate(data):
    script = r"""
require $argv[1];
use App\Application\Commercial\TrialAndPlanChangeService;
$payload = json_decode($argv[2], true, 32, JSON_THROW_ON_ERROR);
echo json_encode(TrialAndPlanChangeService::evaluate($payload), JSON_THROW_ON_ERROR);
"""
    output = subprocess.run(
        ["php", "-n", "-d", "allow_url_fopen=0", "-r", script,
         str(MODULE), json.dumps(data)], cwd=ROOT, capture_output=True,
        text=True, check=True, timeout=20,
    )
    return json.loads(output.stdout)


class SaasTests(unittest.TestCase):
    def test_aislamiento_por_empresa(self):
        a, b = fixture("alpha", "one"), fixture("beta", "two")
        self.assertTrue(evaluate(a)["valid"])
        self.assertTrue(evaluate(b)["valid"])
        self.assertNotEqual(evaluate(a)["company_ref"], evaluate(b)["company_ref"])
        for key in ("catalog_quote", "subscription", "request"):
            mixed = copy.deepcopy(a)
            mixed[key] = b[key]
            with self.subTest(key=key):
                result = evaluate(mixed)
                self.assertFalse(result["valid"])
                self.assertNotIn("fixture:tenant/beta", json.dumps(result))

    def test_auditoria_y_permisos(self):
        data = fixture()
        result = evaluate(data)
        self.assertTrue(result["valid"])
        self.assertEqual("pending_owner_review", result["proposal"]["status"])
        self.assertEqual("recorded_for_manual_review", result["audit"][0]["outcome"])
        self.assertEqual(data["actor_ref"], result["audit"][0]["actor_ref"])
        self.assertFalse(result["can_charge"])
        self.assertFalse(result["can_activate"])
        for field, value in (("actor_role", "anonymous"),
                             ("permission", "commercial.view"),
                             ("actor_ref", "person@example.com")):
            denied = copy.deepcopy(data)
            denied[field] = value
            self.assertFalse(evaluate(denied)["valid"])
            self.assertEqual([], evaluate(denied)["audit"])
        upgrade = fixture(action="upgrade")
        self.assertTrue(evaluate(upgrade)["valid"])
        self.assertEqual("after_owner_review", evaluate(upgrade)["proposal"]["effective"])

    def test_sin_datos_reales(self):
        for field, value in (("tenant_ref", "900123456"),
                             ("company_ref", "NIT-123456"),
                             ("actor_ref", "person@example.com"),
                             ("actor_ref", "3001234567")):
            data = fixture()
            data[field] = value
            with self.subTest(field=field):
                self.assertFalse(evaluate(data)["valid"])
                self.assertNotIn(value, json.dumps(evaluate(data)))
        for slot in ("catalog_quote", "subscription", "request"):
            data = fixture()
            data[slot]["email"] = "real@example.com"
            self.assertFalse(evaluate(data)["valid"])

    def test_fail_closed(self):
        data = fixture()
        self.assertEqual(evaluate(data), evaluate(copy.deepcopy(data)))
        self.assertEqual("REQUIERE_REVISION", evaluate(data)["state"])
        for key in ("catalog_quote", "subscription", "request", "permission"):
            denied = copy.deepcopy(data)
            del denied[key]
            with self.subTest(key=key):
                self.assertFalse(evaluate(denied)["valid"])
        for field, value in (("quote_required", True), ("amount_minor", None)):
            denied = copy.deepcopy(data)
            denied["catalog_quote"][field] = value
            self.assertFalse(evaluate(denied)["valid"])
        downgrade = fixture(action="downgrade")
        downgrade["catalog_quote"]["amount_minor"] = 5000
        self.assertTrue(evaluate(downgrade)["valid"])
        self.assertEqual("2026-11-27", evaluate(downgrade)["proposal"]["effective"])
        downgrade["subscription"]["usage_compatible"] = False
        self.assertEqual("pending_resolution", evaluate(downgrade)["proposal"]["status"])
        downgrade["subscription"]["renews_on"] = "2026-13-40"
        self.assertFalse(evaluate(downgrade)["valid"])
        self.assertNotIn("#[Route(", CONTROLLER.read_text(encoding="utf-8"))
        self.assertIn("PlanQuoteService::preview", CONTROLLER.read_text(encoding="utf-8"))


if __name__ == "__main__":
    unittest.main()
