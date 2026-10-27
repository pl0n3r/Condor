#!/usr/bin/env python3
"""GEST-M04 S05-S08: real PHP acceptance, exclusively synthetic fixtures."""
from __future__ import annotations

import copy
import json
import subprocess
import unittest
from pathlib import Path

ROOT = Path(__file__).resolve().parents[1]
SOURCE = ROOT / "src/Application/Payroll/PayrollSurchargesBenefits.php"


def fixture(tenant: str = "tenant-a", company: str = "fixture:company/demo-a") -> dict:
    return {
        "tenant_ref": tenant,
        "company_ref": company,
        "as_of": "2026-04-20",
        "parameters": {
            "version_ref": "fixture:policy/payroll-v1",
            "effective_from": "2026-01-01",
            "effective_until": "2026-12-31",
            "rates": {"surcharge_bp": 1250, "deduction_bp": 400, "benefit_bp": 850},
        },
        "items": [{"ref": "fixture:payroll/entry-a", "base_cents": "100005"}],
    }


def project(data: dict) -> dict:
    script = r"""
require $argv[1];
use App\Application\Payroll\PayrollSurchargesBenefits;
$data = json_decode($argv[2], true, 32, JSON_THROW_ON_ERROR);
echo json_encode(PayrollSurchargesBenefits::project($data), JSON_THROW_ON_ERROR);
"""
    result = subprocess.run(
        ["php", "-n", "-d", "allow_url_fopen=0", "-r", script,
         str(SOURCE), json.dumps(data)],
        cwd=ROOT, capture_output=True, check=True, text=True, timeout=20,
    )
    return json.loads(result.stdout)


class PayrollSurchargesBenefitsTests(unittest.TestCase):
    def test_resultado_determinista(self) -> None:
        case = fixture()
        first = project(case)
        self.assertEqual(first, project(copy.deepcopy(case)))
        self.assertTrue(first["valid"])
        self.assertEqual("fixture:policy/payroll-v1", first["policy_ref"])
        slip = first["slips"][0]
        self.assertEqual("1000.05", slip["base"])
        self.assertEqual("125.01", slip["surcharges"])
        self.assertEqual("40.00", slip["deductions"])
        self.assertEqual("85.00", slip["benefits"])
        self.assertEqual("1085.06", slip["net_preview"])
        self.assertFalse(slip["official"])
        self.assertIn("NO OFICIAL", slip["watermark"])
        # No hidden legal rate: all rates are supplied by the versioned fixture.
        changed = copy.deepcopy(case)
        changed["parameters"]["rates"]["surcharge_bp"] = 2500
        self.assertEqual("250.01", project(changed)["slips"][0]["surcharges"])
        changed["items"].append({"ref": "fixture:payroll/entry-b", "base_cents": "0"})
        self.assertEqual("fixture:payroll/entry-a", project(changed)["slips"][0]["ref"])
        changed["items"].reverse()
        self.assertEqual(sorted(x["ref"] for x in changed["items"]), [x["ref"] for x in project(changed)["slips"]])

    def test_aislamiento_por_empresa(self) -> None:
        for tenant, company in (
            ("tenant-a", "fixture:company/demo-a"),
            ("tenant-b", "fixture:company/demo-b"),
        ):
            with self.subTest(tenant=tenant):
                result = project(fixture(tenant, company))
                self.assertTrue(result["valid"])
                self.assertEqual(tenant, result["tenant_ref"])
                self.assertEqual(company, result["company_ref"])
                self.assertNotIn("demo-b" if tenant == "tenant-a" else "demo-a", json.dumps(result))
        unscoped = fixture()
        unscoped["tenant_ref"] = "tenant-a@demo-b"
        self.assertFalse(project(unscoped)["valid"])
        self.assertIsNone(project(unscoped)["company_ref"])
        self.assertEqual([], project(unscoped)["slips"])

    def test_rechaza_datos_reales(self) -> None:
        for field, value in (
            ("company_ref", "empresa@example.com"),
            ("tenant_ref", "3115551234"),
            ("company_ref", "1020304050"),
            ("company_ref", "https://example.com/empresa"),
        ):
            data = fixture()
            data[field] = value
            with self.subTest(field=field, value=value):
                result = project(data)
                self.assertFalse(result["valid"])
                self.assertEqual([], result["slips"])
                self.assertNotIn(value, json.dumps(result))
        data = fixture()
        data["items"][0]["email"] = "person@example.com"
        self.assertFalse(project(data)["valid"])
        data = fixture()
        data["parameters"]["identification_number"] = "1020304050"
        self.assertFalse(project(data)["valid"])
        data = fixture()
        data["items"][0]["ref"] = "tel:3115551234"
        self.assertFalse(project(data)["valid"])
        self.assertFalse(project({**fixture(), "phone": "3115551234"})["valid"])

    def test_sin_cierre_automatico(self) -> None:
        good = project(fixture())
        self.assertEqual("REQUIERE_REVISION", good["state"])
        self.assertEqual("REQUIERE_REVISION", good["slips"][0]["state"])
        self.assertTrue(good["slips"][0]["requires_professional_review"])
        self.assertNotIn("APROBADO", json.dumps(good))
        self.assertNotIn("CERRADO", json.dumps(good))
        for case in (
            {"as_of": "2027-02-01"},
            {"as_of": "2026-02-30"},
            {"items": [fixture()["items"][0], fixture()["items"][0]]},
            {"items": [{"ref": "fixture:payroll/entry-a", "base_cents": "-5"}]},
            {"items": [{"ref": "fixture:payroll/entry-a", "base_cents": "100.50"}]},
            {"items": [{"ref": "fixture:payroll/entry-a", "base_cents": "9999999999999"}]},
            {"parameters": {**fixture()["parameters"], "rates": {"surcharge_bp": 20001, "deduction_bp": 0, "benefit_bp": 0}}},
            {"parameters": {**fixture()["parameters"], "rates": {"surcharge_bp": 0, "deduction_bp": 10001, "benefit_bp": 0}}},
            {"items": [{**fixture()["items"][0], "state": "APROBADO"}]},
        ):
            with self.subTest(case=case):
                result = project({**fixture(), **copy.deepcopy(case)})
                self.assertFalse(result["valid"])
                self.assertEqual("REQUIERE_REVISION", result["state"])
                self.assertEqual([], result["slips"])
                self.assertNotIn("APROBADO", json.dumps(result))
        source = SOURCE.read_text(encoding="utf-8").lower()
        for bad in ("pdo(", "doctrine", "curl_", "file_get_contents(", "httpclient", "entitymanager", "shell_exec("):
            self.assertNotIn(bad, source)


if __name__ == "__main__":
    unittest.main()
