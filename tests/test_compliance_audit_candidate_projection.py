#!/usr/bin/env python3
"""F2b: real-PHP acceptance, discarded fixtures and no production data."""
from __future__ import annotations

import copy
import json
import subprocess
import unittest
from pathlib import Path

ROOT = Path(__file__).resolve().parents[1]
CLASS = ROOT / "src/Application/Compliance/ComplianceAuditCandidateProjection.php"


def upstream(state: str = "EN_REVISION") -> dict:
    return {
        "tenant_ref": "tenant-a", "municipio": "pereira",
        "rows": [{
            "state": state, "deadline_ref": "fixture:deadline/notice-1",
            "due_on": "2026-10-21", "attention_candidate": True,
        }],
    }


def audit(**changes: object) -> dict:
    row = {
        "tenant_ref": "tenant-a", "municipio": "pereira", "row_index": 0,
        "audit_ref": "fixture:audit/audit-1",
        "action_ref": "fixture:action/remediation-1",
        "deadline_ref": "fixture:deadline/notice-1", "due_on": "2026-10-21",
        "opened_on": "2026-10-22", "reviewed_on": "2026-10-23",
    }
    row.update(changes)
    return row


def project(f2a: dict | None = None, audits: list | None = None, date: str = "2026-10-24") -> dict:
    payload = {
        "f2a": upstream() if f2a is None else f2a,
        "audits": [audit()] if audits is None else audits,
        "date": date,
    }
    php = r"""
require $argv[1];
use App\Application\Compliance\ComplianceAuditCandidateProjection;
$data = json_decode($argv[2], true, 32, JSON_THROW_ON_ERROR);
echo json_encode(ComplianceAuditCandidateProjection::project(
  $data['f2a'], $data['audits'], $data['date']
), JSON_THROW_ON_ERROR);
"""
    done = subprocess.run(
        ["php", "-n", "-d", "allow_url_fopen=0", "-r", php, str(CLASS), json.dumps(payload)],
        cwd=ROOT, text=True, capture_output=True, check=True, timeout=20,
    )
    return json.loads(done.stdout)


class ComplianceAuditCandidateProjectionTests(unittest.TestCase):
    def test_unknown_upstream_state_never_closes_audit(self) -> None:
        good = project()["rows"][0]
        self.assertEqual("EN_REVISION", good["state"])
        self.assertTrue(good["corrective_action_candidate"])
        self.assertNotIn("CUMPLE", json.dumps(project()))
        for bad in ("PENDIENTE", "CUMPLE", "NO_APLICA", "REQUIERE_REVISION", "DESCONOCIDO"):
            with self.subTest(state=bad):
                row = project(f2a=upstream(bad))["rows"][0]
                self.assertEqual("REQUIERE_REVISION", row["state"])
                self.assertFalse(row["corrective_action_candidate"])
                self.assertIsNone(row["audit_ref"])
        x = upstream()
        x["rows"][0]["attention_candidate"] = False
        self.assertEqual("REQUIERE_REVISION", project(f2a=x)["rows"][0]["state"])
        self.assertEqual("REQUIERE_REVISION", project(f2a={})["rows"][0]["state"])

    def test_cross_tenant_municipality_candidate_is_rejected(self) -> None:
        for bad in (audit(tenant_ref="tenant-b"), audit(municipio="bogota"),
                    audit(tenant_ref="unknown"), audit(municipio="unknown"),
                    {"municipio": "pereira"}):
            with self.subTest(audit=bad):
                result = project(audits=[bad])
                self.assertEqual("REQUIERE_REVISION", result["rows"][0]["state"])
                self.assertNotIn("tenant-b", json.dumps(result))
                self.assertNotIn("bogota", json.dumps(result))
        for bad in ([audit(), audit()], [audit(row_index=99)],
                    [audit(), audit(municipio="bogota")]):
            with self.subTest(audits=bad):
                self.assertEqual("REQUIERE_REVISION", project(audits=bad)["rows"][0]["state"])

    def test_invalid_review_timeline_never_certifies_compliance(self) -> None:
        for bad in (audit(opened_on="2026-10-20"), audit(reviewed_on="2026-10-21"),
                    audit(reviewed_on="2026-12-01"), audit(reviewed_on="2026-02-30"),
                    audit(action_ref="https://other.invalid/a"),
                    audit(audit_ref="fixture:other/audit-1"),
                    audit(deadline_ref="fixture:deadline/other"),
                    audit(due_on="2026-10-22"), audit(reviewed_on="never")):
            with self.subTest(bad=bad):
                row = project(audits=[bad])["rows"][0]
                self.assertEqual("REQUIERE_REVISION", row["state"])
                self.assertFalse(row["corrective_action_candidate"])
        self.assertEqual("REQUIERE_REVISION", project(date="2026-02-30")["rows"][0]["state"])
        self.assertEqual("REQUIERE_REVISION", project(date="2026-10-20")["rows"][0]["state"])
        x = upstream()
        x["rows"][0]["due_on"] = "not-a-date"
        self.assertEqual("REQUIERE_REVISION", project(f2a=x)["rows"][0]["state"])

    def test_pure_offline_no_pii_or_network_effects(self) -> None:
        code = CLASS.read_text(encoding="utf-8").lower()
        for prohibited in ("curl_", "file_get_contents(", "pdo(", "doctrine", "httpclient",
                           "shell_exec(", "exec(", "entitymanager", "'cumple'", '"cumple"'):
            self.assertNotIn(prohibited, code)
        first = project()
        self.assertEqual(first, project())
        self.assertEqual("tenant-a", first["tenant_ref"])
        self.assertEqual("pereira", first["municipio"])
        self.assertEqual("fixture:audit/audit-1", first["rows"][0]["audit_ref"])
        self.assertEqual("fixture:action/remediation-1", first["rows"][0]["action_ref"])
        self.assertNotIn("CUMPLE", json.dumps(first))
        self.assertEqual([], project(f2a={"tenant_ref":"tenant-a", "municipio":"pereira", "rows": []}, audits=[])["rows"])
        # Unknown scope cannot silently yield a clean audit.
        self.assertEqual("REQUIERE_REVISION", project(f2a={"tenant_ref":"unknown", "municipio":"pereira", "rows":[]}, audits=[])["rows"][0]["state"])


if __name__ == "__main__":
    unittest.main()
