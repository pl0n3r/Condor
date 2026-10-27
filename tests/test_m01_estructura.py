#!/usr/bin/env python3
"""M01 organisation: acceptance with real PHP, offline synthetic fixtures."""
from __future__ import annotations

import copy
import json
import subprocess
import unittest
from pathlib import Path

ROOT = Path(__file__).resolve().parents[1]
CLASS = ROOT / "src/Application/HumanResources/OrganizationStructureProjection.php"


def fixture(tenant="tenant-a", company="fixture:company/a") -> dict:
    t, c = tenant, company
    return {
        "tenant_ref": t, "company_ref": c, "as_of": "2026-10-27",
        "areas": [
            {"tenant_ref": t, "company_ref": c, "ref": "fixture:area/head", "parent_ref": None},
            {"tenant_ref": t, "company_ref": c, "ref": "fixture:area/branch",
             "parent_ref": "fixture:area/head"},
        ],
        "sites": [{"tenant_ref": t, "company_ref": c, "ref": "fixture:site/main"}],
        "positions": [
            {"tenant_ref": t, "company_ref": c, "ref": "fixture:position/manager",
             "area_ref": "fixture:area/head", "site_ref": "fixture:site/main",
             "parent_ref": None, "employee_ref": "fixture:employee/a"},
            {"tenant_ref": t, "company_ref": c, "ref": "fixture:position/assistant",
             "area_ref": "fixture:area/branch", "site_ref": "fixture:site/main",
             "parent_ref": "fixture:position/manager", "employee_ref": None},
        ],
    }


def project(data: dict) -> dict:
    script = r"""
require $argv[1];
use App\Application\HumanResources\OrganizationStructureProjection;
$data = json_decode($argv[2], true, 32, JSON_THROW_ON_ERROR);
echo json_encode(OrganizationStructureProjection::project($data), JSON_THROW_ON_ERROR);
"""
    result = subprocess.run(
        ["php", "-n", "-d", "allow_url_fopen=0", "-r", script,
         str(CLASS), json.dumps(data)], cwd=ROOT, text=True,
        capture_output=True, check=True, timeout=20,
    )
    return json.loads(result.stdout)


class OrganizationStructureProjectionTests(unittest.TestCase):
    def test_resultado_determinista(self) -> None:
        payload = fixture()
        initial = project(payload)
        final = project(copy.deepcopy(payload))
        self.assertEqual(initial, final)
        self.assertTrue(initial["valid"])
        self.assertEqual("REQUIERE_REVISION", initial["state"])
        self.assertFalse(initial["has_cycles"])
        self.assertTrue(initial["positions"][0]["without_responsible"])
        reordered = copy.deepcopy(payload)
        for kind in ("areas", "sites", "positions"):
            reordered[kind].reverse()
        self.assertEqual(initial, project(reordered))
        cyc = fixture()
        cyc["areas"][0]["parent_ref"] = "fixture:area/branch"
        flagged = project(cyc)
        self.assertTrue(flagged["valid"])
        self.assertTrue(flagged["has_cycles"])
        self.assertTrue(all(row["cycle_detected"] for row in flagged["areas"]))
        self.assertFalse(flagged["can_approve"])

    def test_aislamiento_por_empresa(self) -> None:
        own = project(fixture("tenant-a", "fixture:company/a"))
        foreign = fixture("tenant-b", "fixture:company/b")
        self.assertTrue(project(foreign)["valid"])
        mixed = fixture()
        for kind in ("areas", "sites", "positions"):
            mixed[kind].extend(copy.deepcopy(foreign[kind]))
        self.assertEqual(own, project(mixed))
        output = json.dumps(project(mixed))
        self.assertNotIn("tenant-b", output)
        self.assertNotIn("fixture:company/b", output)
        for kind in ("areas", "sites", "positions"):
            invalid = fixture()
            invalid[kind].append({"ref": "fixture:area/unknown"})
            self.assertFalse(project(invalid)["valid"])

    def test_rechaza_datos_reales(self) -> None:
        for group, key, value in (
            ("positions", "employee_ref", "person@example.com"),
            ("positions", "employee_ref", "3001234567"),
            ("positions", "employee_ref", "1012345678"),
            ("areas", "ref", "https://example.com/customer"),
            ("sites", "ref", "CC-12345678"),
        ):
            payload = fixture()
            payload[group][0][key] = value
            with self.subTest(group=group, key=key):
                result = project(payload)
                self.assertFalse(result["valid"])
                self.assertNotIn(value, json.dumps(result))
        payload = fixture()
        payload["positions"][0]["email"] = "synthetic@example.com"
        self.assertFalse(project(payload)["valid"])
        payload = fixture()
        payload["positions"].append(payload["positions"][0])
        self.assertFalse(project(payload)["valid"])

    def test_sin_cierre_automatico(self) -> None:
        for sample in (fixture(), fixture("tenant-c", "fixture:company/c")):
            value = project(sample)
            self.assertTrue(value["valid"])
            self.assertFalse(value["can_close"])
            self.assertFalse(value["can_approve"])
            for group in ("areas", "sites", "positions"):
                self.assertTrue(all(row["state"] == "REQUIERE_REVISION" for row in value[group]))
        sample = fixture()
        sample["positions"][0]["state"] = "APROBADO"
        self.assertFalse(project(sample)["valid"])
        sample = fixture()
        sample["positions"][0]["parent_ref"] = "fixture:position/assistant"
        value = project(sample)
        self.assertTrue(value["has_cycles"])
        self.assertFalse(value["can_close"])
        self.assertFalse(value["can_approve"])
        code = CLASS.read_text(encoding="utf-8").lower()
        for disallowed in ("curl_", "pdo(", "doctrine", "file_get_contents(",
                           "shell_exec(", "httpclient", "entitymanager"):
            self.assertNotIn(disallowed, code)


if __name__ == "__main__":
    unittest.main()
