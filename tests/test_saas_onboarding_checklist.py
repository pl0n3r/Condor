#!/usr/bin/env python3
"""Onboarding SaaS #1072 — real PHP, synthetic-only, no DB/network."""
from __future__ import annotations

import copy
import json
import subprocess
import unittest
from pathlib import Path

ROOT = Path(__file__).resolve().parents[1]
MODULE = ROOT / "src/Application/Commercial/OnboardingChecklist.php"


def fixture(tenant: str = "a", company: str = "a") -> dict:
    tenant_ref = f"fixture:tenant/{tenant}"
    company_ref = f"fixture:company/{company}"
    capability = "fixture:capability/manage"
    common = {"tenant_ref": tenant_ref, "company_ref": company_ref}
    return {
        **common,
        "actor_ref": "fixture:actor/admin",
        "actor_role": "tenant_admin",
        "catalog": {**common, "plan_key": "fixture:plan/basic", "version": 1,
                    "capabilities": [capability]},
        "options": {**common, "plan_key": "fixture:plan/basic", "version": 1,
                    "capabilities": [capability]},
        "entitlements": {**common, "plan_key": "fixture:plan/basic", "version": 1,
                         "capabilities": {capability: True}},
        "subscription": {**common, "state": "not_configured"},
        "events": [],
    }


def evaluate(payload: dict) -> dict:
    script = r"""
require $argv[1];
use App\Application\Commercial\OnboardingChecklist;
$data = json_decode($argv[2], true, 32, JSON_THROW_ON_ERROR);
echo json_encode(OnboardingChecklist::evaluate($data), JSON_THROW_ON_ERROR);
"""
    execution = subprocess.run(
        ["php", "-n", "-d", "allow_url_fopen=0", "-r", script,
         str(MODULE), json.dumps(payload)], cwd=ROOT, text=True,
        capture_output=True, timeout=20, check=True,
    )
    return json.loads(execution.stdout)


def event(payload: dict, step="company", request="one", actor=None) -> dict:
    return {
        "request_ref": f"fixture:request/{request}",
        "tenant_ref": payload["tenant_ref"],
        "company_ref": payload["company_ref"],
        "actor_ref": actor or payload["actor_ref"],
        "step": step, "action": "confirm", "on": "2026-10-27",
    }


class SaasTests(unittest.TestCase):
    def test_aislamiento_por_empresa(self) -> None:
        a = fixture("alpha", "one")
        b = fixture("beta", "two")
        self.assertTrue(evaluate(a)["valid"])
        self.assertTrue(evaluate(b)["valid"])
        self.assertNotEqual(evaluate(a)["tenant_ref"], evaluate(b)["tenant_ref"])
        for key in ("catalog", "options", "entitlements", "subscription"):
            bad = copy.deepcopy(a)
            bad[key] = b[key]
            with self.subTest(key=key):
                self.assertFalse(evaluate(bad)["valid"])
                self.assertNotIn("fixture:tenant/beta", json.dumps(evaluate(bad)))
        bad = copy.deepcopy(a)
        bad["events"] = [event(b)]
        self.assertFalse(evaluate(bad)["valid"])

    def test_auditoria_y_permisos(self) -> None:
        data = fixture()
        data["events"] = [event(data)]
        out = evaluate(data)
        self.assertTrue(out["valid"])
        self.assertEqual("recorded_for_manual_review", out["audit"][0]["outcome"])
        self.assertEqual(data["actor_ref"], out["audit"][0]["actor_ref"])
        self.assertEqual("CONFIRMADO_PARA_REVISION", out["steps"][0]["status"])
        self.assertFalse(out["can_activate"])
        self.assertFalse(out["can_charge"])
        for invalid_role in ("anonymous", "viewer", "root", "", None):
            other = copy.deepcopy(data)
            other["actor_role"] = invalid_role
            self.assertFalse(evaluate(other)["valid"])
            self.assertEqual([], evaluate(other)["audit"])
        other = copy.deepcopy(data)
        other["events"][0]["actor_ref"] = "fixture:actor/other"
        self.assertFalse(evaluate(other)["valid"])

    def test_sin_datos_reales(self) -> None:
        for key, value in (("tenant_ref", "1234567890"),
                           ("company_ref", "nit-900123456"),
                           ("actor_ref", "person@example.com"),
                           ("actor_ref", "3001234567")):
            data = fixture()
            data[key] = value
            with self.subTest(key=key, value=value):
                out = evaluate(data)
                self.assertFalse(out["valid"])
                self.assertNotIn(str(value), json.dumps(out))
        data = fixture()
        data["events"] = [event(data)]
        data["events"][0]["personal_name"] = "Persona Real"
        self.assertFalse(evaluate(data)["valid"])
        data = fixture()
        data["contact_email"] = "person@example.com"
        self.assertFalse(evaluate(data)["valid"])

    def test_fail_closed(self) -> None:
        baseline = fixture()
        observed = evaluate(baseline)
        self.assertTrue(observed["valid"])
        self.assertEqual(observed, evaluate(copy.deepcopy(baseline)))
        self.assertEqual("REQUIERE_REVISION", observed["state"])
        self.assertEqual(0, len(observed["audit"]))
        self.assertTrue(all(row["status"] == "PENDIENTE_REVISION"
                            for row in observed["steps"]))
        self.assertFalse(observed["can_activate"])
        for scope in ("catalog", "options", "entitlements", "subscription", "actor_ref"):
            other = copy.deepcopy(baseline)
            del other[scope]
            self.assertFalse(evaluate(other)["valid"])
        other = copy.deepcopy(baseline)
        other["entitlements"]["capabilities"] = {}
        self.assertFalse(evaluate(other)["valid"])
        other = copy.deepcopy(baseline)
        other["events"] = [event(other), event(other)]
        self.assertFalse(evaluate(other)["valid"])
        other = copy.deepcopy(baseline)
        other["catalog"]["capabilities"] = ["fixture:capability/manage", "fixture:capability/manage"]
        self.assertFalse(evaluate(other)["valid"])
        other = copy.deepcopy(baseline)
        other["events"] = [event(other, step="billing")]
        self.assertFalse(evaluate(other)["valid"])
        self.assertNotIn("http", MODULE.read_text(encoding="utf-8").lower())


if __name__ == "__main__":
    unittest.main()