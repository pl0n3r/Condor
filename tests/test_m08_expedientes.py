"""M08 dossier histories: real PHP tests using synthetic, offline fixtures."""
from __future__ import annotations

import copy
import json
import subprocess
import unittest
from pathlib import Path

ROOT = Path(__file__).resolve().parents[1]
CLASS = ROOT / "src/Application/Documents/DocumentDossierVersions.php"


def fixture(tenant="tenant-a", company="fixture:company/a"):
    return {
        "tenant_ref": tenant,
        "company_ref": company,
        "as_of": "2026-10-27",
        "dossiers": [{
            "tenant_ref": tenant,
            "company_ref": company,
            "ref": "fixture:dossier/alpha",
            "entity_ref": "fixture:entity/alpha",
            "classification": "INTERNA",
            "versions": [
                {"ref": "fixture:version/first", "number": 1,
                 "hash": "fixture:sha256/" + "a" * 64, "previous_ref": None},
                {"ref": "fixture:version/second", "number": 2,
                 "hash": "fixture:sha256/" + "b" * 64,
                 "previous_ref": "fixture:version/first"},
            ],
        }],
    }


def project(data):
    code = r"""
require $argv[1];
use App\Application\Documents\DocumentDossierVersions;
$data = json_decode($argv[2], true, 32, JSON_THROW_ON_ERROR);
echo json_encode(DocumentDossierVersions::project($data), JSON_THROW_ON_ERROR);
"""
    proc = subprocess.run(
        ["php", "-n", "-d", "allow_url_fopen=0", "-r", code,
         str(CLASS), json.dumps(data)], cwd=ROOT,
        capture_output=True, text=True, check=True, timeout=20,
    )
    return json.loads(proc.stdout)


class DocumentDossierVersionsTests(unittest.TestCase):
    def test_resultado_determinista(self):
        source = fixture()
        before = project(source)
        self.assertTrue(before["valid"])
        self.assertEqual(before, project(copy.deepcopy(source)))
        reversed_versions = copy.deepcopy(source)
        reversed_versions["dossiers"][0]["versions"].reverse()
        self.assertEqual(before, project(reversed_versions))
        self.assertEqual([1, 2], [v["number"] for v in before["dossiers"][0]["versions"]])
        self.assertEqual("REQUIERE_REVISION", before["state"])

    def test_aislamiento_por_empresa(self):
        one = fixture()
        other = fixture("tenant-b", "fixture:company/b")
        baseline = project(one)
        self.assertTrue(project(other)["valid"])
        mixed = copy.deepcopy(one)
        mixed["dossiers"].extend(copy.deepcopy(other["dossiers"]))
        self.assertEqual(baseline, project(mixed))
        self.assertNotIn("tenant-b", json.dumps(project(mixed)))
        self.assertNotIn("fixture:company/b", json.dumps(project(mixed)))
        two = fixture("tenant-a", "fixture:company/b")
        mixed["dossiers"].extend(two["dossiers"])
        self.assertEqual(baseline, project(mixed))
        malformed = copy.deepcopy(mixed)
        malformed["dossiers"][-1]["versions"][0]["hash"] = "not-synthetic"
        self.assertFalse(project(malformed)["valid"])

    def test_rechaza_datos_reales(self):
        for key, invalid in (
            ("ref", "1012345678"),
            ("entity_ref", "test.person@example.com"),
            ("classification", "3001234567"),
        ):
            candidate = fixture()
            candidate["dossiers"][0][key] = invalid
            with self.subTest(key=key):
                result = project(candidate)
                self.assertFalse(result["valid"])
                self.assertNotIn(invalid, json.dumps(result))
        candidate = fixture()
        candidate["dossiers"][0]["email"] = "user@example.com"
        self.assertFalse(project(candidate)["valid"])
        candidate = fixture()
        candidate["dossiers"][0]["versions"][0]["hash"] = "b" * 64
        self.assertFalse(project(candidate)["valid"])
        candidate = fixture()
        candidate["dossiers"][0]["versions"][1]["previous_ref"] = "fixture:version/missing"
        self.assertFalse(project(candidate)["valid"])

    def test_sin_cierre_automatico(self):
        for sample in (fixture(), fixture("tenant-b", "fixture:company/b")):
            actual = project(sample)
            self.assertTrue(actual["valid"])
            self.assertFalse(actual["can_approve"])
            self.assertFalse(actual["can_close"])
            self.assertTrue(all(not d["can_close"] and not d["can_approve"]
                                for d in actual["dossiers"]))
            self.assertTrue(all(d["state"] == "REQUIERE_REVISION" for d in actual["dossiers"]))
        candidate = fixture()
        candidate["dossiers"][0]["state"] = "APPROVED"
        self.assertFalse(project(candidate)["valid"])
        source = CLASS.read_text(encoding="utf-8").lower()
        for prohibited in ("curl_", "pdo(", "doctrine", "file_get_contents(", "shell_exec(", "unlink("):
            self.assertNotIn(prohibited, source)


if __name__ == "__main__":
    unittest.main()
