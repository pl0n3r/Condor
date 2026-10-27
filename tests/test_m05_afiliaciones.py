"""M05 synthetic-only projections executed by real PHP (not mocked)."""
from __future__ import annotations

import copy
import json
import subprocess
import unittest
from pathlib import Path

ROOT = Path(__file__).resolve().parents[1]
SOURCE = ROOT / "src/Application/SocialSecurity/SocialSecurityContributionsProjection.php"


def fixture(tenant="fixture:tenant/a"):
    return {
        "tenant_ref": tenant,
        "policy": {"version": "fixture:policy/m05_v1", "health_bp": 400, "pension_bp": 1600},
        "affiliations": [{"tenant_ref": tenant, "employee_ref": "fixture:employee/ana",
                          "affiliation_ref": "fixture:affiliation/one", "base_units": 10000}],
        "novelties": [{"tenant_ref": tenant, "employee_ref": "fixture:employee/ana",
                       "novelty_ref": "fixture:novelty/one"}],
    }


def run_php(payload, valid=True):
    script = r"""require $argv[1];
use App\Application\SocialSecurity\SocialSecurityContributionsProjection;
$data = json_decode($argv[2], true, 32, JSON_THROW_ON_ERROR);
echo json_encode(SocialSecurityContributionsProjection::project($data), JSON_THROW_ON_ERROR);"""
    result = subprocess.run(
        ["php", "-n", "-d", "allow_url_fopen=0", "-r", script, str(SOURCE), json.dumps(payload)],
        cwd=ROOT, capture_output=True, text=True, timeout=20,
    )
    if not valid:
        assert result.returncode != 0, result.stdout
        return None
    if result.returncode:
        raise AssertionError(result.stderr)
    return json.loads(result.stdout)


class SocialSecurityContributionsProjectionTests(unittest.TestCase):
    def test_resultado_determinista(self):
        data = fixture()
        self.assertEqual(run_php(data), run_php(copy.deepcopy(data)))
        row = run_php(data)["rows"][0]
        self.assertEqual(400, row["health_units"])
        self.assertEqual(1600, row["pension_units"])
        self.assertEqual(["fixture:novelty/one"], row["novelties"])

    def test_aislamiento_por_empresa(self):
        data = fixture()
        foreign = fixture("fixture:tenant/b")
        data["affiliations"] += foreign["affiliations"]
        data["novelties"] += foreign["novelties"]
        result = run_php(data)
        self.assertEqual("fixture:tenant/a", result["tenant_ref"])
        self.assertEqual(1, len(result["rows"]))
        self.assertNotIn("tenant/b", json.dumps(result))
        self.assertEqual([], run_php({**foreign, "tenant_ref": "fixture:tenant/a"})["rows"])

    def test_rechaza_datos_reales(self):
        unsafe = ("example@domain.test", "+573001234567", "1234567890", "real-identity")
        for candidate in unsafe:
            with self.subTest(candidate=candidate):
                data = fixture()
                data["affiliations"][0]["employee_ref"] = candidate
                run_php(data, valid=False)
        data = fixture()
        data["affiliations"][0]["email"] = "person@example.test"
        run_php(data, valid=False)
        data = fixture()
        data["affiliations"].append(None)
        run_php(data, valid=False)

    def test_sin_cierre_automatico(self):
        result = run_php(fixture())
        self.assertEqual("REQUIERE_REVISION", result["state"])
        self.assertFalse(result["legal_value"])
        self.assertFalse(result["enabled_for_real_data"])
        self.assertEqual("EN_REVISION", result["rows"][0]["state"])
        text = SOURCE.read_text(encoding="utf-8").lower()
        for forbidden in ("pdo(", "curl_", "file_get_contents(", "httpclient", "entitymanager", "doctrine", "'aprobado'", "'cumple'"):
            self.assertNotIn(forbidden, text)
        data = fixture()
        data["affiliations"].append(copy.deepcopy(data["affiliations"][0]))
        run_php(data, valid=False)


if __name__ == "__main__":
    unittest.main()
