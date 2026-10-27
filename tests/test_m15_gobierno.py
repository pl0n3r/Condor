#!/usr/bin/env python3
"""GEST-M15: run real PHP governance projection on synthetic fixtures only."""
from __future__ import annotations

import copy
import json
import subprocess
import unittest
from pathlib import Path

ROOT = Path(__file__).resolve().parents[1]
SOURCE = ROOT / "src/Application/Governance/GovernanceBodiesMinutes.php"


def fixture(tenant: str = "tenant-a", company: str = "fixture:company/example-a") -> dict:
    return {
        "tenant_ref": tenant,
        "company_ref": company,
        "as_of": "2026-04-25",
        "bodies": [{
            "ref": "fixture:body/board-a", "kind": "board",
            "representative_ref": "fixture:representative/rep-a",
        }],
        "minutes": [{
            "ref": "fixture:minute/min-a", "body_ref": "fixture:body/board-a",
            "recorded_on": "2026-04-20", "members_present": 2, "members_total": 3,
        }],
        "powers": [{
            "ref": "fixture:power/pow-a", "representative_ref": "fixture:representative/rep-a",
            "valid_from": "2026-01-01", "valid_until": "2026-06-30",
        }],
    }


def project(payload: dict) -> dict:
    script = r"""
require $argv[1];
use App\Application\Governance\GovernanceBodiesMinutes;
$data = json_decode($argv[2], true, 32, JSON_THROW_ON_ERROR);
echo json_encode(GovernanceBodiesMinutes::project($data), JSON_THROW_ON_ERROR);
"""
    proc = subprocess.run(
        ["php", "-n", "-d", "allow_url_fopen=0", "-r", script,
         str(SOURCE), json.dumps(payload)],
        cwd=ROOT, text=True, capture_output=True, check=True, timeout=20,
    )
    return json.loads(proc.stdout)


class GovernanceBodiesMinutesTests(unittest.TestCase):
    def test_resultado_determinista(self) -> None:
        sample = fixture()
        expected = project(sample)
        actual = project(copy.deepcopy(sample))
        self.assertEqual(expected, actual)
        result = project(fixture())
        self.assertTrue(result["valid"])
        self.assertEqual("fixture:minute/min-a", result["minutes"][0]["ref"])
        self.assertTrue(result["powers"][0]["within_date_window"])
        self.assertTrue(result["minutes"][0]["quorum_requires_review"])
        self.assertNotIn("APROBADO", json.dumps(result))
        self.assertNotIn("CERRADO", json.dumps(result))
        for key in ("bodies", "minutes", "powers"):
            self.assertEqual("REQUIERE_REVISION", result[key][0]["state"])

    def test_aislamiento_por_empresa(self) -> None:
        for tenant, company in (
            ("tenant-a", "fixture:company/example-a"),
            ("tenant-b", "fixture:company/example-b"),
        ):
            with self.subTest(tenant=tenant):
                result = project(fixture(tenant, company))
                self.assertEqual(tenant, result["tenant_ref"])
                self.assertEqual(company, result["company_ref"])
                self.assertNotIn("example-b" if tenant == "tenant-a" else "example-a", json.dumps(result))
        bad = fixture()
        bad["minutes"][0]["body_ref"] = "fixture:body/other-company"
        self.assertFalse(project(bad)["valid"])
        self.assertIsNone(project(bad)["tenant_ref"])
        self.assertEqual([], project(bad)["minutes"])

    def test_rechaza_datos_reales(self) -> None:
        bad_values = [
            ("bodies", "representative_ref", "user@example.com"),
            ("bodies", "representative_ref", "3115551234"),
            ("bodies", "representative_ref", "1020304050"),
            ("powers", "ref", "https://example.com/real"),
            ("minutes", "ref", "person@example.com"),
        ]
        for section, key, value in bad_values:
            data = fixture()
            data[section][0][key] = value
            with self.subTest(value=value):
                result = project(data)
                self.assertFalse(result["valid"])
                self.assertEqual([], result[section])
                self.assertNotIn(value, json.dumps(result))
        data = fixture()
        data["bodies"][0]["email"] = "person@example.com"
        self.assertFalse(project(data)["valid"])
        self.assertFalse(project({**fixture(), "tax_document": "123456789"})["valid"])

    def test_sin_cierre_automatico(self) -> None:
        for variation in (
            {"minutes": [{**fixture()["minutes"][0], "members_present": 3}]},
            {"powers": [{**fixture()["powers"][0], "valid_until": "2026-12-31"}]},
            {"minutes": [{**fixture()["minutes"][0], "state": "APROBADO"}]},
            {"powers": [{**fixture()["powers"][0], "state": "CERRADO"}]},
            {"minutes": [{**fixture()["minutes"][0], "recorded_on": "2026-02-30"}]},
            {"minutes": [fixture()["minutes"][0], fixture()["minutes"][0]]},
        ):
            data = {**fixture(), **copy.deepcopy(variation)}
            with self.subTest(data=data):
                result = project(data)
                self.assertEqual("REQUIERE_REVISION", result["state"])
                self.assertNotIn("APROBADO", json.dumps(result))
                self.assertNotIn("CERRADO", json.dumps(result))
        self.assertEqual([], project({**fixture(), "powers": []})["powers"])
        code = SOURCE.read_text(encoding="utf-8").lower()
        for forbidden in ("pdo(", "doctrine", "curl_", "file_get_contents(", "httpclient", "entitymanager"):
            self.assertNotIn(forbidden, code)


if __name__ == "__main__":
    unittest.main()
