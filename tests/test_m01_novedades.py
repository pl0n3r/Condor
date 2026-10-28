"""M01 leave/absence acceptance, executing the real offline PHP projection."""
from __future__ import annotations

import copy
import json
import subprocess
import unittest
from pathlib import Path

ROOT = Path(__file__).resolve().parents[1]
CLASS = ROOT / "src/Application/HumanResources/LeaveAbsenceProjection.php"


def fixture(tenant="tenant-a", company="fixture:company/a") -> dict:
    return {
        "tenant_ref": tenant, "company_ref": company, "as_of": "2026-10-27",
        "balances": [{"tenant_ref": tenant, "company_ref": company,
                      "employee_ref": "fixture:employee/a", "days": 3}],
        "requests": [
            {"tenant_ref": tenant, "company_ref": company, "ref": "fixture:request/a",
             "employee_ref": "fixture:employee/a", "kind": "VACACIONES",
             "starts_on": "2026-11-01", "ends_on": "2026-11-05", "state": "PENDIENTE"},
            {"tenant_ref": tenant, "company_ref": company, "ref": "fixture:request/b",
             "employee_ref": "fixture:employee/a", "kind": "INCAPACIDAD",
             "starts_on": "2026-11-03", "ends_on": "2026-11-04", "state": "EN_REVISION"},
        ],
    }


def project(data: dict) -> dict:
    script = r"""
require $argv[1];
use App\Application\HumanResources\LeaveAbsenceProjection;
$data = json_decode($argv[2], true, 32, JSON_THROW_ON_ERROR);
echo json_encode(LeaveAbsenceProjection::project($data), JSON_THROW_ON_ERROR);
"""
    cmd = ["php", "-n", "-d", "allow_url_fopen=0", "-r", script,
           str(CLASS), json.dumps(data)]
    return json.loads(subprocess.run(cmd, cwd=ROOT, text=True, capture_output=True,
                                     check=True, timeout=20).stdout)


class LeaveAbsenceProjectionTests(unittest.TestCase):
    def test_resultado_determinista(self) -> None:
        payload = fixture()
        output = project(payload)
        self.assertTrue(output["valid"])
        self.assertEqual(output, project(copy.deepcopy(payload)))
        self.assertTrue(all(row["overlaps"] for row in output["requests"]))
        self.assertTrue(output["requests"][0]["insufficient_balance"])
        reordered = copy.deepcopy(payload)
        reordered["requests"].reverse()
        self.assertEqual(output, project(reordered))

    def test_aislamiento_por_empresa(self) -> None:
        own = project(fixture())
        foreign = fixture("tenant-b", "fixture:company/b")
        mixed = fixture()
        mixed["requests"].extend(copy.deepcopy(foreign["requests"]))
        mixed["balances"].extend(copy.deepcopy(foreign["balances"]))
        self.assertEqual(own, project(mixed))
        self.assertNotIn("tenant-b", json.dumps(project(mixed)))
        self.assertTrue(project(foreign)["valid"])
        foreign["requests"][0]["tenant_ref"] = "not-a-tenant"
        mixed["requests"].append(foreign["requests"][0])
        self.assertFalse(project(mixed)["valid"])

    def test_rechaza_datos_reales(self) -> None:
        for key, value in (("employee_ref", "person@example.com"),
                           ("employee_ref", "3001234567"),
                           ("employee_ref", "1234567890"),
                           ("ref", "https://person.example/path")):
            with self.subTest(key=key, value=value):
                payload = fixture()
                payload["requests"][0][key] = value
                result = project(payload)
                self.assertFalse(result["valid"])
                self.assertNotIn(value, json.dumps(result))
        payload = fixture()
        payload["requests"][0]["diagnosis"] = "personal data"
        self.assertFalse(project(payload)["valid"])
        payload = fixture()
        payload["balances"][0]["email"] = "person@example.com"
        self.assertFalse(project(payload)["valid"])

    def test_sin_cierre_automatico(self) -> None:
        result = project(fixture())
        self.assertTrue(result["valid"])
        self.assertFalse(result["automatically_approved"])
        self.assertFalse(result["automatically_closed"])
        self.assertTrue(all(not r["automatically_closed"]
                            and not r["automatically_approved"] for r in result["requests"]))
        for state in ("APROBADO", "CERRADO"):
            payload = fixture()
            payload["requests"][0]["state"] = state
            self.assertFalse(project(payload)["valid"])
        code = CLASS.read_text(encoding="utf-8").lower()
        for forbidden in ("curl_", "pdo(", "doctrine", "file_get_contents(",
                          "shell_exec(", "httpclient", "entitymanager"):
            self.assertNotIn(forbidden, code)


if __name__ == "__main__":
    unittest.main()
