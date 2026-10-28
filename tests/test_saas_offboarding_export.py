#!/usr/bin/env python3
"""#1073: synthetic-only offboarding and verifiable export, PHP executed offline."""
from __future__ import annotations

import copy
import json
import subprocess
import unittest
from pathlib import Path

ROOT = Path(__file__).resolve().parents[1]
MODULE = ROOT / "src/Application/Commercial/TenantOffboardingExport.php"
ONBOARDING = ROOT / "src/Application/Commercial/OnboardingChecklist.php"
DATASETS = ("audit", "catalog", "inventory", "operations")


def fixture(tenant: str = "alpha", company: str = "one") -> dict:
    ref = lambda kind, name: f"fixture:{kind}/{name}"
    common = {"tenant_ref": ref("tenant", tenant), "company_ref": ref("company", company)}
    capability = ref("capability", "manage")
    commercial = {
        **common, "actor_ref": ref("actor", "admin"),
        "actor_role": "tenant_admin",
        "catalog": {**common, "plan_key": ref("plan", "basic"), "version": 1,
                    "capabilities": [capability]},
        "options": {**common, "plan_key": ref("plan", "basic"), "version": 1,
                    "capabilities": [capability]},
        "entitlements": {**common, "plan_key": ref("plan", "basic"),
                         "version": 1, "capabilities": {capability: True}},
        "subscription": {**common, "state": "active"},
        "events": [],
    }
    return {
        **common, "actor_ref": ref("actor", "admin"), "actor_role": "tenant_admin",
        "request_ref": ref("request", "one"),
        "requested_on": "2026-10-27", "retention_until": "2026-12-27",
        "action": "export_preview", "commercial": commercial,
        "datasets": [{**common, "key": key,
                      "records": [{**common, "record_ref": ref("record", key)}]}
                     for key in DATASETS],
    }


def evaluate(data: dict, digest: str | None = None) -> dict | bool:
    script = r"""
require $argv[1];
require $argv[2];
use App\Application\Commercial\TenantOffboardingExport;
$input = json_decode($argv[3], true, 32, JSON_THROW_ON_ERROR);
if ($argc === 5) {
    echo json_encode(TenantOffboardingExport::verify($input, $argv[4]), JSON_THROW_ON_ERROR);
} else {
    echo json_encode(TenantOffboardingExport::evaluate($input), JSON_THROW_ON_ERROR);
}
"""
    cmd = ["php", "-n", "-d", "allow_url_fopen=0", "-r", script,
           str(ONBOARDING), str(MODULE), json.dumps(data)]
    if digest is not None:
        cmd.append(digest)
    result = subprocess.run(cmd, cwd=ROOT, capture_output=True, text=True, timeout=20)
    if result.returncode:
        raise AssertionError(result.stdout + result.stderr)
    return json.loads(result.stdout)


class SaasTests(unittest.TestCase):
    def test_aislamiento_por_empresa(self) -> None:
        a, b = fixture("alpha", "one"), fixture("beta", "two")
        left, right = evaluate(a), evaluate(b)
        self.assertTrue(left["valid"])
        self.assertTrue(right["valid"])
        self.assertNotEqual(left["digest"], right["digest"])
        self.assertEqual(set(left["manifest"]), set(DATASETS))
        self.assertEqual(set(left["export"]), set(DATASETS))
        self.assertEqual(left["export"]["inventory"]["records"], ["fixture:record/inventory"])
        self.assertEqual(left["export"]["inventory"]["tenant_ref"], a["tenant_ref"])
        self.assertEqual(left["export"]["inventory"]["company_ref"], a["company_ref"])
        for path in ("commercial", "datasets"):
            mixed = copy.deepcopy(a)
            mixed[path] = copy.deepcopy(b[path])
            with self.subTest(mixed=path):
                bad = evaluate(mixed)
                self.assertFalse(bad["valid"])
                self.assertNotIn("fixture:tenant/beta", json.dumps(bad))
        mixed = copy.deepcopy(a)
        mixed["datasets"][0]["records"][0]["tenant_ref"] = b["tenant_ref"]
        self.assertFalse(evaluate(mixed)["valid"])
        tampered = copy.deepcopy(a)
        tampered["datasets"][0]["records"][0]["record_ref"] = "fixture:record/changed"
        self.assertFalse(evaluate(tampered, left["digest"]))
        self.assertTrue(evaluate(copy.deepcopy(a), left["digest"]))

    def test_auditoria_y_permisos(self) -> None:
        data = fixture()
        result = evaluate(data)
        self.assertTrue(result["valid"])
        self.assertEqual("EXPORTACION_PREPARADA", result["audit"][0]["action"])
        self.assertEqual(data["actor_ref"], result["audit"][0]["actor_ref"])
        self.assertEqual(data["tenant_ref"], result["audit"][0]["tenant_ref"])
        self.assertFalse(result["can_charge"])
        self.assertFalse(result["can_delete"])
        for role in ("viewer", "anonymous", "root", "", None):
            bad = copy.deepcopy(data)
            bad["actor_role"] = role
            bad["commercial"]["actor_role"] = role
            self.assertFalse(evaluate(bad)["valid"])
            self.assertEqual([], evaluate(bad)["audit"])
        proposed = copy.deepcopy(data)
        proposed["action"] = "offboarding_review"
        self.assertFalse(evaluate(proposed)["valid"], "Only a platform admin can propose a deletion")
        proposed["actor_role"] = "platform_admin"
        proposed["commercial"]["actor_role"] = "platform_admin"
        observed = evaluate(proposed)
        self.assertTrue(observed["valid"])
        self.assertEqual("BAJA_PENDIENTE_REVISION", observed["state"])
        self.assertEqual("BAJA_SOLICITADA", observed["audit"][0]["action"])
        self.assertEqual("2026-12-27", observed["deletion_not_before"])
        self.assertFalse(observed["deletion_scheduled"])
        self.assertFalse(observed["can_delete"])

    def test_sin_datos_reales(self) -> None:
        for key, value in (("tenant_ref", "900123456"), ("company_ref", "NIT-900123456"),
                           ("actor_ref", "person@example.com"), ("request_ref", "+573001234567")):
            bad = fixture()
            bad[key] = value
            with self.subTest(field=key):
                result = evaluate(bad)
                self.assertFalse(result["valid"])
                self.assertNotIn(str(value), json.dumps(result))
        for candidate in ("person@example.com", "1234567890", "3001234567", "Persona Real"):
            bad = fixture()
            bad["datasets"][0]["records"][0]["record_ref"] = candidate
            self.assertFalse(evaluate(bad)["valid"])
        bad = fixture()
        bad["contact_email"] = "someone@example.com"
        self.assertFalse(evaluate(bad)["valid"])
        bad = fixture()
        bad["datasets"][0]["records"][0]["name"] = "Persona Real"
        self.assertFalse(evaluate(bad)["valid"])

    def test_fail_closed(self) -> None:
        data = fixture()
        out = evaluate(data)
        self.assertTrue(out["valid"])
        self.assertEqual(out, evaluate(copy.deepcopy(data)))
        self.assertTrue(out["manual_review_required"])
        for key in DATASETS:
            self.assertEqual(out["manifest"][key]["count"], len(out["export"][key]["records"]))
        self.assertFalse(out["deletion_scheduled"])
        self.assertFalse(out["can_delete"])
        self.assertFalse(out["can_charge"])
        self.assertTrue(all(len(v["sha256"]) == 64 for v in out["manifest"].values()))
        for missing in ("tenant_ref", "commercial", "datasets", "actor_role",
                        "retention_until", "request_ref", "requested_on"):
            bad = copy.deepcopy(data)
            del bad[missing]
            with self.subTest(missing=missing):
                self.assertFalse(evaluate(bad)["valid"])
        for mutation in ("duplicate", "incomplete", "ambiguous", "future", "date"):
            bad = copy.deepcopy(data)
            if mutation == "duplicate":
                bad["datasets"][1] = copy.deepcopy(bad["datasets"][0])
            if mutation == "incomplete":
                bad["datasets"].pop()
            if mutation == "ambiguous":
                bad["datasets"][0]["records"].append(copy.deepcopy(bad["datasets"][0]["records"][0]))
            if mutation == "future":
                bad["retention_until"] = bad["requested_on"]
            if mutation == "date":
                bad["requested_on"] = "2026-02-30"
            with self.subTest(mutation=mutation):
                self.assertFalse(evaluate(bad)["valid"])
        self.assertFalse(evaluate(data, "0" * 64))
        source = MODULE.read_text().lower()
        for forbidden in ("curl_", "delete from ", "drop table", "httpclient", "file_put_contents"):
            self.assertNotIn(forbidden, source)
        for path in (MODULE, ONBOARDING):
            result = subprocess.run(["php", "-n", "-l", str(path)], cwd=ROOT,
                                    capture_output=True, text=True, timeout=20)
            self.assertEqual(0, result.returncode, result.stdout + result.stderr)


if __name__ == "__main__":
    unittest.main()
