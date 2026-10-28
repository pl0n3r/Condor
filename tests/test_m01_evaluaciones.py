"""M01 performance/portal acceptance running real PHP offline."""
from __future__ import annotations

import copy
import json
import subprocess
import unittest
from pathlib import Path

ROOT = Path(__file__).resolve().parents[1]
CLASS = ROOT / "src/Application/HumanResources/PerformancePortalProjection.php"


def fixture(tenant="tenant-a", company="fixture:company/a", viewer="fixture:employee/a") -> dict:
    return {
        "tenant_ref": tenant, "company_ref": company,
        "viewer_employee_ref": viewer, "as_of": "2026-10-27",
        "evaluations": [
            {"tenant_ref": tenant, "company_ref": company,
             "ref": "fixture:evaluation/a", "cycle_ref": "fixture:cycle/a",
             "employee_ref": viewer, "evaluator_ref": viewer,
             "kind": "AUTOEVALUACION", "state": "BORRADOR", "score": 60},
            {"tenant_ref": tenant, "company_ref": company,
             "ref": "fixture:evaluation/b", "cycle_ref": "fixture:cycle/a",
             "employee_ref": viewer, "evaluator_ref": "fixture:employee/manager",
             "kind": "JEFE", "state": "EN_REVISION", "score": None},
        ],
    }


def project(data: dict) -> dict:
    script = r"""
require $argv[1];
use App\Application\HumanResources\PerformancePortalProjection;
$data = json_decode($argv[2], true, 32, JSON_THROW_ON_ERROR);
echo json_encode(PerformancePortalProjection::project($data), JSON_THROW_ON_ERROR);
"""
    return json.loads(subprocess.run(
        ["php", "-n", "-d", "allow_url_fopen=0", "-r", script,
         str(CLASS), json.dumps(data)], cwd=ROOT, capture_output=True,
        text=True, check=True, timeout=20,
    ).stdout)


class PerformancePortalProjectionTests(unittest.TestCase):
    def test_resultado_determinista(self) -> None:
        payload = fixture()
        result = project(payload)
        self.assertTrue(result["valid"])
        self.assertEqual(result, project(copy.deepcopy(payload)))
        reversed_data = copy.deepcopy(payload)
        reversed_data["evaluations"].reverse()
        self.assertEqual(result, project(reversed_data))
        self.assertEqual(2, len(result["evaluations"]))
        self.assertTrue(all(v["needs_human_review"] for v in result["evaluations"]))

    def test_aislamiento_por_empresa(self) -> None:
        own = project(fixture())
        foreign = fixture("tenant-b", "fixture:company/b", "fixture:employee/b")
        third = fixture("tenant-a", "fixture:company/a", "fixture:employee/other")
        combined = fixture()
        combined["evaluations"].extend(copy.deepcopy(foreign["evaluations"]))
        combined["evaluations"].extend(copy.deepcopy(third["evaluations"]))
        self.assertEqual(own, project(combined))
        output = json.dumps(project(combined))
        self.assertNotIn("tenant-b", output)
        self.assertNotIn("fixture:employee/other", output)
        self.assertNotIn("fixture:company/b", output)
        self.assertTrue(project(foreign)["valid"])

    def test_rechaza_datos_reales(self) -> None:
        for key, value in (("employee_ref", "person@example.com"),
                           ("evaluator_ref", "3001234567"),
                           ("ref", "1012345678"),
                           ("cycle_ref", "https://example.com")):
            sample = fixture()
            sample["evaluations"][0][key] = value
            result = project(sample)
            self.assertFalse(result["valid"])
            self.assertNotIn(value, json.dumps(result))
        sample = fixture()
        sample["evaluations"][0]["comments"] = "diagnosis"
        self.assertFalse(project(sample)["valid"])

    def test_sin_cierre_automatico(self) -> None:
        result = project(fixture())
        self.assertFalse(result["can_close"])
        self.assertFalse(result["can_approve"])
        self.assertTrue(all(not row["automatically_closed"]
                            and not row["automatically_approved"] for row in result["evaluations"]))
        for state in ("APROBADO", "CERRADO"):
            sample = fixture()
            sample["evaluations"][0]["state"] = state
            self.assertFalse(project(sample)["valid"])
        sample = fixture()
        sample["evaluations"][0]["score"] = 101
        self.assertFalse(project(sample)["valid"])
        code = CLASS.read_text(encoding="utf-8").lower()
        for forbidden in ("curl_", "pdo(", "doctrine", "file_get_contents(",
                          "shell_exec(", "httpclient", "entitymanager"):
            self.assertNotIn(forbidden, code)


if __name__ == "__main__":
    unittest.main()
