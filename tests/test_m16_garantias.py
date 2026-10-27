#!/usr/bin/env python3
"""Condor #1011 M16: acceptance using the real PHP class, synthetic data only."""
from __future__ import annotations

import copy
import json
import subprocess
import unittest
from pathlib import Path

ROOT = Path(__file__).resolve().parents[1]
CLASS = ROOT / "src/Application/CustomerService/CustomerWarrantySatisfaction.php"


def payload(tenant: str = "tenant-a") -> dict:
    return {
        "tenant_ref": tenant,
        "warranties": [{
            "tenant_ref": tenant,
            "order_ref": "fixture:order/example-1",
            "warranty_ref": "fixture:warranty/example-1",
            "starts_on": "2026-01-01",
            "ends_on": "2026-12-31",
        }],
        "communications": [{
            "tenant_ref": tenant,
            "order_ref": "fixture:order/example-1",
            "template_ref": "fixture:template/notice-1",
        }],
        "surveys": [
            {"tenant_ref": tenant, "survey_ref": "fixture:survey/a",
             "period": "2026-09", "score": 4},
            {"tenant_ref": tenant, "survey_ref": "fixture:survey/b",
             "period": "2026-09", "score": 5},
            {"tenant_ref": tenant, "survey_ref": "fixture:survey/c",
             "period": "2026-10", "score": 3},
        ],
    }


def project(data: dict, on_date: str = "2026-10-27") -> dict:
    php = r"""
require $argv[1];
use App\Application\CustomerService\CustomerWarrantySatisfaction;
$data = json_decode($argv[2], true, 32, JSON_THROW_ON_ERROR);
echo json_encode(CustomerWarrantySatisfaction::project($data, $argv[3]), JSON_THROW_ON_ERROR);
"""
    result = subprocess.run(
        ["php", "-n", "-d", "allow_url_fopen=0", "-r", php, str(CLASS),
         json.dumps(data, ensure_ascii=False), on_date],
        cwd=ROOT, text=True, capture_output=True, check=True, timeout=15,
    )
    return json.loads(result.stdout)


class CustomerWarrantySatisfactionTests(unittest.TestCase):
    def test_resultado_determinista(self) -> None:
        actual = project(payload())
        self.assertEqual(actual, project(payload()))
        self.assertEqual("VIGENTE", actual["warranties"][0]["state"])
        self.assertEqual("NO_ENVIADO", actual["communications"][0]["delivery_state"])
        self.assertEqual([
            {"period": "2026-09", "responses": 2, "average": 4.5,
             "state": "SINTETICO_NO_VALIDADO"},
            {"period": "2026-10", "responses": 1, "average": 3,
             "state": "SINTETICO_NO_VALIDADO"},
        ], actual["satisfaction"])
        past = payload()
        past["surveys"] = []  # A future survey period is rejected when projecting a past date.
        self.assertEqual("NO_INICIADA", project(past, "2025-12-31")["warranties"][0]["state"])
        self.assertEqual("VENCIDA", project(payload(), "2027-01-01")["warranties"][0]["state"])
        self.assertEqual([], project(payload(), "invalid")["warranties"])

    def test_aislamiento_por_empresa(self) -> None:
        a = payload("tenant-a")
        b = payload("tenant-b")
        self.assertEqual("tenant-a", project(a)["tenant_ref"])
        self.assertEqual("tenant-b", project(b)["tenant_ref"])
        together = copy.deepcopy(a)
        together["warranties"] += b["warranties"]
        together["communications"] += b["communications"]
        together["surveys"] += b["surveys"]
        result = project(together)
        self.assertEqual(project(a), result)
        self.assertNotIn("tenant-b", json.dumps(result))
        self.assertNotIn("fixture:survey/c-b", json.dumps(result))
        # Missing or ambiguous tenant is NOT silently treated as foreign.
        missing = copy.deepcopy(a)
        missing["surveys"].append({"survey_ref": "fixture:survey/d", "period": "2026-10", "score": 2})
        self.assertEqual([], project(missing)["satisfaction"])

    def test_rechaza_datos_reales(self) -> None:
        corruptions = [
            ("communications", "recipient", "persona@empresa.com"),
            ("warranties", "identity_number", "1098765432"),
            ("communications", "phone", "+573001112233"),
            ("surveys", "customer_name", "Nombre Real"),
            ("warranties", "order_ref", "https://example.org/order"),
            ("surveys", "survey_ref", "fixture:survey/1234567890"),
        ]
        for collection, field, value in corruptions:
            with self.subTest(field=field):
                bad = payload()
                bad[collection][0][field] = value
                result = project(bad)
                self.assertEqual("REQUIERE_REVISION", result["state"])
                self.assertEqual([], result["warranties"])
                self.assertEqual([], result["communications"])
                self.assertEqual([], result["satisfaction"])
                self.assertNotIn(str(value), json.dumps(result))
        for bad_score in (0, 6, 3.5, "4"):
            bad = payload()
            bad["surveys"][0]["score"] = bad_score
            self.assertEqual([], project(bad)["satisfaction"])

    def test_sin_cierre_automatico(self) -> None:
        result = project(payload())
        self.assertEqual("REQUIERE_REVISION", result["state"])
        self.assertFalse(result["approved"])
        self.assertFalse(result["closed"])
        self.assertEqual([], result["actions_taken"])
        self.assertTrue(all(not row["approved"] and not row["closed"] for row in result["warranties"]))
        self.assertTrue(all(not row["approved"] and row["delivery_state"] == "NO_ENVIADO"
                            for row in result["communications"]))
        code = CLASS.read_text(encoding="utf-8").lower()
        for forbidden in ("pdo(", "curl_", "file_get_contents(", "shell_exec(",
                          "httpclient", "entitymanager", "mailer", "->send("):
            self.assertNotIn(forbidden, code)
        self.assertEqual([], project({})["warranties"])
        bad = payload()
        bad["warranties"][0]["ends_on"] = "2026-02-30"
        self.assertEqual([], project(bad)["warranties"])


if __name__ == "__main__":
    unittest.main()
