"""Synthetic M05 PILA preparation; all acceptance tests execute real PHP."""
from __future__ import annotations

import copy
import json
import subprocess
import unittest
from pathlib import Path

ROOT = Path(__file__).resolve().parents[1]
SOURCE = ROOT / "src/Application/SocialSecurity/SocialSecurityPilaPreparation.php"


def fixture(tenant="fixture:tenant/a"):
    return {
        "tenant_ref": tenant, "reference_date": "2026-10-27",
        "supports": [{"tenant_ref": tenant, "employee_ref": "fixture:employee/ana",
                      "support_ref": "fixture:support/one"}],
        "alerts": [{"tenant_ref": tenant, "employee_ref": "fixture:employee/ana",
                    "alert_ref": "fixture:alert/one", "due_on": "2026-10-20"}],
    }


def run_php(payload, valid=True):
    script = r"""require $argv[1];
use App\Application\SocialSecurity\SocialSecurityPilaPreparation;
$data = json_decode($argv[2], true, 32, JSON_THROW_ON_ERROR);
echo json_encode(SocialSecurityPilaPreparation::project($data), JSON_THROW_ON_ERROR);"""
    proc = subprocess.run(
        ["php", "-n", "-d", "allow_url_fopen=0", "-r", script, str(SOURCE), json.dumps(payload)],
        cwd=ROOT, capture_output=True, text=True, timeout=20,
    )
    if not valid:
        assert proc.returncode, proc.stdout
        return None
    if proc.returncode:
        raise AssertionError(proc.stderr)
    return json.loads(proc.stdout)


class SocialSecurityPilaPreparationTests(unittest.TestCase):
    def test_resultado_determinista(self):
        data = fixture()
        result = run_php(data)
        self.assertEqual(result, run_php(copy.deepcopy(data)))
        self.assertTrue(result["alerts"][0]["attention_candidate"])
        self.assertEqual(1, result["pila_example"]["support_count"])
        self.assertEqual("BORRADOR_NO_ENVIABLE", result["pila_example"]["state"])

    def test_aislamiento_por_empresa(self):
        data = fixture()
        foreign = fixture("fixture:tenant/b")
        data["supports"].extend(foreign["supports"])
        data["alerts"].extend(foreign["alerts"])
        result = run_php(data)
        self.assertEqual(1, len(result["supports"]))
        self.assertEqual(1, len(result["alerts"]))
        self.assertNotIn("tenant/b", json.dumps(result))
        self.assertEqual([], run_php({**foreign, "tenant_ref": "fixture:tenant/a"})["supports"])

    def test_rechaza_datos_reales(self):
        for candidate in ("person@example.test", "+573001234567", "1234567890"):
            with self.subTest(value=candidate):
                data = fixture()
                data["supports"][0]["employee_ref"] = candidate
                run_php(data, valid=False)
        data = fixture()
        data["supports"][0]["email"] = "someone@example.test"
        run_php(data, valid=False)
        data = fixture()
        data["alerts"][0]["due_on"] = "2026-02-30"
        run_php(data, valid=False)

    def test_sin_cierre_automatico(self):
        result = run_php(fixture())
        self.assertFalse(result["legal_value"])
        self.assertFalse(result["can_send"])
        self.assertEqual("REQUIERE_REVISION", result["state"])
        self.assertEqual("EN_REVISION", result["alerts"][0]["state"])
        text = SOURCE.read_text(encoding="utf-8").lower()
        for forbidden in ("pdo(", "curl_", "file_get_contents(", "httpclient", "entitymanager", "doctrine", "'aprobado'", "'cumple'"):
            self.assertNotIn(forbidden, text)
        data = fixture()
        data["supports"].append(copy.deepcopy(data["supports"][0]))
        run_php(data, valid=False)


if __name__ == "__main__":
    unittest.main()
