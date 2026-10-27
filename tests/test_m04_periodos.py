#!/usr/bin/env python3
"""GEST-M04 S01–S04: exercise pure synthetic payroll in the real PHP runtime."""
from __future__ import annotations

import copy
import json
import subprocess
import unittest
from pathlib import Path

ROOT = Path(__file__).resolve().parents[1]
CLASS = ROOT / "src/Application/Payroll/PayrollPeriodsProjection.php"


def fixture(tenant: str = "fixture:tenant/a", entity: str = "fixture:entity/a") -> dict:
    scoped = {"tenant_ref": tenant, "legal_entity_ref": entity}
    return {
        **scoped,
        "periods": [{**scoped, "period_ref": "fixture:period/oct",
                     "start_on": "2026-10-01", "end_on": "2026-10-31"}],
        "concepts": [{**scoped, "concept_ref": "fixture:concept/base",
                      "version": "fixture:version/v1", "kind": "devengo",
                      "amount_decimal": "1234.50"}],
        "novelties": [{**scoped, "novelty_ref": "fixture:novelty/01",
                       "period_ref": "fixture:period/oct",
                       "concept_ref": "fixture:concept/base",
                       "quantity_decimal": "1.25"}],
        "hours": [{**scoped, "hours_ref": "fixture:hours/01",
                   "period_ref": "fixture:period/oct", "hours_decimal": "40.75"}],
    }


def project(data: dict) -> dict:
    script = r"""
require $argv[1];
$input = json_decode($argv[2], true, 32, JSON_THROW_ON_ERROR);
echo json_encode(\App\Application\Payroll\PayrollPeriodsProjection::project($input), JSON_THROW_ON_ERROR);
"""
    result = subprocess.run(
        ["php", "-n", "-d", "allow_url_fopen=0", "-r", script,
         str(CLASS), json.dumps(data)],
        cwd=ROOT, capture_output=True, text=True, timeout=20, check=True,
    )
    return json.loads(result.stdout)


class PayrollPeriodsProjectionTests(unittest.TestCase):
    def test_resultado_determinista(self) -> None:
        data = fixture()
        first = project(data)
        self.assertEqual(first, project(copy.deepcopy(data)))
        self.assertEqual([], first["errors"])
        self.assertEqual(123450, first["concepts"][0]["amount_hundredths"])
        self.assertEqual(125, first["novelties"][0]["quantity_hundredths"])
        self.assertEqual(4075, first["hours"][0]["hours_hundredths"])
        for invalid in ("1.234", "1.2", "1e4", 1.25, "-1.00", "01.00"):
            with self.subTest(amount=invalid):
                modified = fixture()
                modified["concepts"][0]["amount_decimal"] = invalid
                self.assertEqual("INVALID_CONCEPT", project(modified)["errors"][0])
        bad_date = fixture()
        bad_date["periods"][0]["end_on"] = "2026-02-30"
        self.assertEqual("INVALID_PERIOD", project(bad_date)["errors"][0])

    def test_aislamiento_por_empresa(self) -> None:
        a = project(fixture("fixture:tenant/a", "fixture:entity/a"))
        b = project(fixture("fixture:tenant/b", "fixture:entity/b"))
        self.assertNotEqual(a["tenant_ref"], b["tenant_ref"])
        self.assertNotEqual(a["legal_entity_ref"], b["legal_entity_ref"])
        self.assertEqual(a["hours"], b["hours"])
        for foreign in (fixture("fixture:tenant/b", "fixture:entity/a"),
                        fixture("fixture:tenant/a", "fixture:entity/b")):
            data = fixture()
            data["periods"].extend(foreign["periods"])
            with self.subTest(foreign=foreign["tenant_ref"] + foreign["legal_entity_ref"]):
                result = project(data)
                self.assertEqual("INVALID_SCOPE_OR_ROW", result["errors"][0])
                self.assertEqual([], result["periods"])
        duplicate = fixture()
        duplicate["periods"].append(copy.deepcopy(duplicate["periods"][0]))
        self.assertIn("INVALID_OR_DUPLICATE_REFERENCE", project(duplicate)["errors"])

    def test_rechaza_datos_reales(self) -> None:
        for key, value in (("email", "persona@example.com"),
                           ("cedula", "1234567890"), ("telefono", "3001234567"),
                           ("nombre", "Persona")):
            payload = fixture()
            payload["novelties"][0][key] = value
            with self.subTest(key=key):
                result = project(payload)
                self.assertEqual(["INVALID_SYNTHETIC_INPUT"], result["errors"])
                self.assertNotIn(value, json.dumps(result))
        for value in ("persona@example.com", "3001234567", "123456789"):
            payload = fixture()
            payload["concepts"][0]["version"] = value
            self.assertEqual(["INVALID_SYNTHETIC_INPUT"], project(payload)["errors"])
        payload = fixture()
        payload["tenant_ref"] = "tenant-real"
        self.assertEqual(["INVALID_SYNTHETIC_INPUT"], project(payload)["errors"])

    def test_sin_cierre_automatico(self) -> None:
        result = project(fixture())
        self.assertEqual("REQUIERE_REVISION", result["state"])
        self.assertFalse(result["legal_effect"])
        self.assertFalse(result["automatic_approval"])
        for collection in ("periods", "concepts", "novelties", "hours"):
            self.assertTrue(result[collection])
            self.assertTrue(all(item["state"] == "REQUIERE_REVISION"
                                for item in result[collection]))
        altered = fixture()
        altered["periods"][0]["state"] = "PAGADO"
        self.assertEqual(["INVALID_PERIOD"], project(altered)["errors"])
        self.assertNotIn("PAGADO", json.dumps(project(altered)))
        invalid_ref = fixture()
        invalid_ref["novelties"][0]["period_ref"] = "fixture:period/not-found"
        denied = project(invalid_ref)
        self.assertEqual(["UNKNOWN_PERIOD_OR_CONCEPT"], denied["errors"])
        self.assertEqual([], denied["concepts"])
        source = CLASS.read_text(encoding="utf-8").lower()
        for forbidden in ("pdo(", "doctrine", "curl_", "file_get_contents(",
                          "shell_exec(", "httpclient", "entitymanager", "mail("):
            self.assertNotIn(forbidden, source)


if __name__ == "__main__":
    unittest.main()
