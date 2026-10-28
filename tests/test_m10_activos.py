"""M10 assets: deterministic real-PHP synthetic lifecycle acceptance."""
from __future__ import annotations

import copy
import json
import subprocess
import unittest
from pathlib import Path

ROOT = Path(__file__).resolve().parents[1]
CLASS = ROOT / "src/Application/Assets/AssetRegisterLifecycle.php"


def fixture(tenant="tenant-a", company="fixture:company/a"):
    return {
        "tenant_ref": tenant,
        "company_ref": company,
        "as_of": "2026-10-27",
        "assets": [{
            "tenant_ref": tenant,
            "company_ref": company,
            "asset_ref": "fixture:asset/laptop-a",
            "responsible_ref": "fixture:responsible/agent-a",
            "warranty_until": "2026-10-26",
            "maintenance_on": "2026-10-27",
            "action": "ASIGNAR",
            "evidence_ref": "fixture:evidence/assignment-a",
        }],
    }


def project(data):
    script = r"""
require $argv[1];
use App\Application\Assets\AssetRegisterLifecycle;
$input = json_decode($argv[2], true, 32, JSON_THROW_ON_ERROR);
echo json_encode(AssetRegisterLifecycle::project($input), JSON_THROW_ON_ERROR);
"""
    raw = subprocess.check_output(
        ["php", "-n", "-d", "allow_url_fopen=0", "-r", script,
         str(CLASS), json.dumps(data, separators=(",", ":"))],
        cwd=ROOT, stderr=subprocess.PIPE, text=True, timeout=20,
    )
    return json.loads(raw)


class AssetRegisterLifecycleTests(unittest.TestCase):
    def test_resultado_determinista(self):
        source = fixture()
        first = project(source)
        self.assertTrue(first["valid"])
        self.assertEqual(first, project(copy.deepcopy(source)))
        self.assertEqual("ASIGNADO", first["assets"][0]["state"])
        self.assertTrue(first["assets"][0]["warranty_expired"])
        self.assertTrue(first["assets"][0]["maintenance_due"])
        other = copy.deepcopy(source)
        other["assets"].append({
            **other["assets"][0], "asset_ref": "fixture:asset/second",
            "action": "MANTENIMIENTO",
        })
        before = project(other)
        other["assets"].reverse()
        self.assertEqual(before, project(other))
        self.assertEqual(2, len(before["assets"]))

    def test_aislamiento_por_empresa(self):
        baseline = project(fixture())
        other = fixture("tenant-b", "fixture:company/b")
        self.assertTrue(project(other)["valid"])
        mixed = fixture()
        mixed["assets"].extend(copy.deepcopy(other["assets"]))
        self.assertEqual(baseline, project(mixed))
        self.assertNotIn("tenant-b", json.dumps(project(mixed)))
        self.assertNotIn("fixture:company/b", json.dumps(project(mixed)))
        within_tenant = fixture("tenant-a", "fixture:company/b")
        mixed["assets"].extend(within_tenant["assets"])
        self.assertEqual(baseline, project(mixed))
        duplicate = fixture()
        duplicate["assets"].append(copy.deepcopy(duplicate["assets"][0]))
        self.assertFalse(project(duplicate)["valid"])
        self.assertEqual([], project(duplicate)["assets"])

    def test_rechaza_datos_reales(self):
        for key, unsafe in (
            ("asset_ref", "1099887766"),
            ("responsible_ref", "persona@example.com"),
            ("evidence_ref", "https://example.com/receipt"),
            ("tenant_ref", "customer-actual"),
            ("warranty_until", "2026-02-30"),
        ):
            sample = fixture()
            sample["assets"][0][key] = unsafe
            with self.subTest(key=key):
                actual = project(sample)
                self.assertFalse(actual["valid"])
                self.assertNotIn(unsafe, json.dumps(actual))
        sample = fixture()
        sample["assets"][0]["email"] = "real@example.com"
        self.assertFalse(project(sample)["valid"])
        sample = fixture()
        sample["assets"][0]["evidence_ref"] = None
        sample["assets"][0]["action"] = "SOLICITAR_BAJA"
        self.assertFalse(project(sample)["valid"])

    def test_sin_cierre_automatico(self):
        base = fixture()
        for action, expected in (
            ("ALTA", "INVENTARIADO"),
            ("ASIGNAR", "ASIGNADO"),
            ("MANTENIMIENTO", "MANTENIMIENTO_PENDIENTE"),
            ("SOLICITAR_BAJA", "BAJA_REQUIERE_REVISION"),
        ):
            case = copy.deepcopy(base)
            case["assets"][0]["action"] = action
            with self.subTest(action=action):
                actual = project(case)
                self.assertTrue(actual["valid"])
                self.assertEqual(expected, actual["assets"][0]["state"])
                self.assertFalse(actual["can_approve"])
                self.assertFalse(actual["can_close"])
                self.assertFalse(actual["assets"][0]["can_close"])
        for action in ("BAJA", "CERRADO", "APROBADO", "CONFIRMADO"):
            sample = fixture()
            sample["assets"][0]["action"] = action
            self.assertFalse(project(sample)["valid"])
        code = CLASS.read_text(encoding="utf-8").lower()
        for forbidden in ("pdo(", "doctrine", "curl_", "file_get_contents(",
                          "shell_exec(", "httpclient", "entitymanager"):
            self.assertNotIn(forbidden, code)


if __name__ == "__main__":
    unittest.main()
