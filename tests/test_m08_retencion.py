"""M08 approval, retention and traceability acceptance using real PHP."""
from __future__ import annotations

import copy
import json
import subprocess
import unittest
from pathlib import Path

ROOT = Path(__file__).resolve().parents[1]
CLASS = ROOT / "src/Application/Documents/DocumentApprovalRetention.php"


def fixture(tenant="tenant-a", company="fixture:company/a"):
    return {
        "tenant_ref": tenant, "company_ref": company, "as_of": "2026-10-27",
        "records": [{
            "tenant_ref": tenant, "company_ref": company,
            "ref": "fixture:dossier/alpha", "classification": "INTERNA",
            "author_ref": "fixture:actor/author", "events": [],
        }],
    }


def event(action, actor, role, date="2026-10-26"):
    return {"action": action, "actor_ref": "fixture:actor/" + actor,
            "role": role, "at": date}


def project(payload):
    program = r"""
require $argv[1];
use App\Application\Documents\DocumentApprovalRetention;
$data = json_decode($argv[2], true, 32, JSON_THROW_ON_ERROR);
echo json_encode(DocumentApprovalRetention::project($data), JSON_THROW_ON_ERROR);
"""
    proc = subprocess.run(
        ["php", "-n", "-d", "allow_url_fopen=0", "-r", program,
         str(CLASS), json.dumps(payload)], cwd=ROOT,
        capture_output=True, text=True, check=True, timeout=20,
    )
    return json.loads(proc.stdout)


class DocumentApprovalRetentionTests(unittest.TestCase):
    def test_resultado_determinista(self):
        payload = fixture()
        before = project(payload)
        self.assertTrue(before["valid"])
        self.assertEqual(before, project(copy.deepcopy(payload)))
        self.assertEqual("BORRADOR", before["records"][0]["state"])
        self.assertEqual(730, before["records"][0]["retention_proposal_days"])
        self.assertFalse(before["records"][0]["retention_is_legal_policy"])
        reordered = copy.deepcopy(payload)
        other = copy.deepcopy(reordered["records"][0])
        other["ref"] = "fixture:dossier/beta"
        reordered["records"].append(other)
        reverse = copy.deepcopy(reordered)
        reverse["records"].reverse()
        self.assertEqual(project(reordered), project(reverse))

    def test_aislamiento_por_empresa(self):
        own = fixture()
        foreign = fixture("tenant-b", "fixture:company/b")
        self.assertTrue(project(foreign)["valid"])
        mixed = copy.deepcopy(own)
        mixed["records"].extend(copy.deepcopy(foreign["records"]))
        self.assertEqual(project(own), project(mixed))
        self.assertNotIn("tenant-b", json.dumps(project(mixed)))
        second_company = fixture("tenant-a", "fixture:company/b")
        mixed["records"].extend(second_company["records"])
        self.assertEqual(project(own), project(mixed))
        malicious = copy.deepcopy(mixed)
        malicious["records"][-1]["author_ref"] = "real.email@example.com"
        self.assertFalse(project(malicious)["valid"])

    def test_rechaza_datos_reales(self):
        for field, value in (
            ("author_ref", "3001234567"),
            ("ref", "1012345678"),
            ("classification", "email@example.com"),
        ):
            candidate = fixture()
            candidate["records"][0][field] = value
            with self.subTest(field=field):
                result = project(candidate)
                self.assertFalse(result["valid"])
                self.assertNotIn(value, json.dumps(result))
        candidate = fixture()
        candidate["records"][0]["phone"] = "3001234567"
        self.assertFalse(project(candidate)["valid"])
        candidate = fixture()
        candidate["records"][0]["events"].append(event("ACCESO", "person@example.com", "lector"))
        self.assertFalse(project(candidate)["valid"])

    def test_sin_cierre_automatico(self):
        draft = project(fixture())
        self.assertTrue(draft["valid"])
        self.assertEqual("BORRADOR", draft["records"][0]["state"])
        self.assertFalse(draft["automatic_approval"])
        self.assertFalse(draft["can_close"])
        self.assertFalse(draft["automatic_deletion"])
        self.assertFalse(draft["records"][0]["delete_authorized"])
        invalid = fixture()
        invalid["records"][0]["events"] = [event("APROBAR", "approver", "aprobador")]
        self.assertFalse(project(invalid)["valid"])
        invalid = fixture()
        invalid["records"][0]["events"] = [event("REVISAR", "author", "revisor")]
        self.assertFalse(project(invalid)["valid"])
        invalid = fixture()
        invalid["records"][0]["events"] = [
            event("REVISAR", "reviewer", "revisor"),
            event("APROBAR", "reviewer", "aprobador"),
        ]
        self.assertFalse(project(invalid)["valid"])
        sample = fixture()
        sample["records"][0]["events"] = [
            event("ACCESO", "reader", "lector", "2026-10-24"),
            event("REVISAR", "reviewer", "revisor", "2026-10-25"),
            event("APROBAR", "approver", "aprobador", "2026-10-26"),
        ]
        explicit = project(sample)
        self.assertTrue(explicit["valid"])
        row = explicit["records"][0]
        self.assertEqual("APROBADO_EXPLICITO", row["state"])
        self.assertEqual(3, len(row["history"]))
        self.assertFalse(row["automatic_approval"])
        self.assertFalse(row["automatic_deletion"])
        self.assertFalse(row["can_close"])
        source = CLASS.read_text(encoding="utf-8").lower()
        for forbidden in ("curl_", "pdo(", "doctrine", "file_get_contents(", "shell_exec(", "unlink("):
            self.assertNotIn(forbidden, source)


if __name__ == "__main__":
    unittest.main()
