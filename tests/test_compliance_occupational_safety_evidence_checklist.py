#!/usr/bin/env python3
"""F3b synthetic SG-SST checklist: executes actual F3a and F3b PHP without I/O."""
from __future__ import annotations

import json
import subprocess
import unittest
from pathlib import Path

ROOT = Path(__file__).resolve().parents[1]
CLASS = ROOT / "src/Application/Compliance/OccupationalSafetyEvidenceChecklistProjection.php"
PRECHECK = ROOT / "src/Application/Compliance/OccupationalSafetyPrecheckProjection.php"
TENANT = "fixture:tenant/tenant-a"
ENTITY = "fixture:legal-entity/entity-a"
PROFILE = "fixture:sgsst-profile/example-1"


def checklist(**changes: object) -> dict:
    base = {
        "fixture_ref": "fixture:sgsst-checklist/example-1",
        "tenant_ref": TENANT,
        "legal_entity_ref": ENTITY,
        "precheck_ref": PROFILE,
        "evidence": [
            {"evidence_ref": "fixture:sgsst-evidence/doc-1", "status": "PENDIENTE"},
        ],
    }
    base.update(changes)
    return base


def project(
    item: dict | None = None,
    tenant: str = TENANT,
    entity: str = ENTITY,
    precheck_profile: dict | None = None,
    precheck_override: dict | None = None,
) -> dict:
    php = r"""
require $argv[1];
require $argv[2];
use App\Application\Compliance\OccupationalSafetyPrecheckProjection;
use App\Application\Compliance\OccupationalSafetyEvidenceChecklistProjection;
$data = json_decode($argv[3], true, 32, JSON_THROW_ON_ERROR);
$precheck = $data['precheck_override'] ?? OccupationalSafetyPrecheckProjection::project(
    $data['profile'], $data['tenant'], $data['entity'], '2026-10-24'
);
echo json_encode(OccupationalSafetyEvidenceChecklistProjection::project(
    $data['checklist'], $precheck, $data['tenant'], $data['entity']
), JSON_THROW_ON_ERROR);
"""
    profile = {
        "fixture_ref": PROFILE,
        "tenant_ref": tenant,
        "legal_entity_ref": entity,
        "workers_min": 10,
        "workers_max": 20,
        "risk_category": "III",
        "reference_date": "2026-10-24",
    }
    profile.update(precheck_profile or {})
    payload = {
        "profile": profile,
        "tenant": tenant,
        "entity": entity,
        "checklist": checklist() if item is None else item,
        "precheck_override": precheck_override,
    }
    done = subprocess.run(
        ["php", "-n", "-d", "allow_url_fopen=0", "-r", php,
         str(PRECHECK), str(CLASS), json.dumps(payload)],
        cwd=ROOT, text=True, capture_output=True, check=True, timeout=20,
    )
    return json.loads(done.stdout)


class OccupationalSafetyEvidenceChecklistTests(unittest.TestCase):
    def assert_safe(self, result: dict, state: str, count: int, reviewable: bool) -> None:
        self.assertEqual(state, result["state"])
        self.assertIs(result["requires_professional_review"], True)
        self.assertIs(result["synthetic_only"], True)
        self.assertIs(result["structurally_reviewable"], reviewable)
        self.assertEqual(count, result["evidence_count"])
        self.assertNotIn("CUMPLE", json.dumps(result))
        self.assertNotIn("NO_APLICA", json.dumps(result))

    def test_unknown_or_missing_evidence_never_implies_compliance(self) -> None:
        self.assert_safe(project(checklist(evidence=[])), "PENDIENTE", 0, False)
        self.assert_safe(project(), "PENDIENTE", 1, True)
        bad_items = [
            {"evidence_ref": "fixture:sgsst-evidence/doc-1", "status": "DESCONOCIDO"},
            {"evidence_ref": "fixture:sgsst-evidence/doc-1", "status": "CUMPLE"},
            {"evidence_ref": "fixture:sgsst-evidence/doc-1"},
            {"evidence_ref": "fixture:sgsst-evidence/doc-1", "status": None},
            {"evidence_ref": "fixture:sgsst-evidence/doc-1", "status": "NO_APLICA"},
        ]
        for bad in bad_items:
            with self.subTest(bad=bad):
                self.assert_safe(project(checklist(evidence=[bad])), "REQUIERE_REVISION", 0, False)
        duplicate = [{"evidence_ref": "fixture:sgsst-evidence/doc-1", "status": "PENDIENTE"}] * 2
        self.assert_safe(project(checklist(evidence=duplicate)), "REQUIERE_REVISION", 0, False)
        self.assert_safe(project(checklist(evidence=[{"evidence_ref": "fixture:sgsst-evidence/d", "status": "PENDIENTE"}] * 51)), "REQUIERE_REVISION", 0, False)

    def test_cross_tenant_or_entity_rejected(self) -> None:
        cases = [
            (checklist(tenant_ref="fixture:tenant/tenant-b"), TENANT, ENTITY),
            (checklist(legal_entity_ref="fixture:legal-entity/entity-b"), TENANT, ENTITY),
            (checklist(), "fixture:tenant/tenant-b", ENTITY),
            (checklist(), TENANT, "fixture:legal-entity/entity-b"),
            (checklist(tenant_ref="tenant-a"), TENANT, ENTITY),
            (checklist(legal_entity_ref=""), TENANT, ENTITY),
        ]
        for item, tenant, entity in cases:
            with self.subTest(item=item, tenant=tenant, entity=entity):
                result = project(item, tenant, entity)
                self.assert_safe(result, "REQUIERE_REVISION", 0, False)
                self.assertNotIn("tenant-b", json.dumps(result))
                self.assertNotIn("entity-b", json.dumps(result))

    def test_fictional_inputs_only_and_no_pii_payload(self) -> None:
        cases = [
            checklist(contact_email="name@example.test"),
            checklist(evidence=[{"evidence_ref": "fixture:sgsst-evidence/doc-1", "status": "PENDIENTE", "person_name": "Alice"}]),
            checklist(evidence=[{"evidence_ref": "https://example.test/doc", "status": "PENDIENTE"}]),
            checklist(evidence=[{"evidence_ref": "fixture:sgsst-evidence/doc-1", "status": "PENDIENTE", "medical_notes": "private"}]),
            checklist(precheck_ref="http://example.test"),
            checklist(precheck_ref="fixture:sgsst-profile/a@b"),
            checklist(evidence={"1": {"evidence_ref": "fixture:sgsst-evidence/doc-1", "status": "PENDIENTE"}}),
        ]
        for invalid in cases:
            with self.subTest(invalid=invalid):
                self.assert_safe(project(invalid), "REQUIERE_REVISION", 0, False)
        for text in ("Alice", "example.test", "private"):
            self.assertNotIn(text, json.dumps(project()))
        self.assert_safe(project(precheck_profile={"risk_category": "unknown"}), "REQUIERE_REVISION", 0, False)
        self.assert_safe(project(precheck_override={"state": "CUMPLE"}), "REQUIERE_REVISION", 0, False)

    def test_stable_review_required_without_legal_thresholds(self) -> None:
        first = project()
        self.assert_safe(first, "PENDIENTE", 1, True)
        self.assertEqual(first, project())
        self.assertEqual({"state", "synthetic_only", "requires_professional_review",
                          "structurally_reviewable", "evidence_count"}, set(first))
        pending_and_review = checklist(evidence=[
            {"evidence_ref": "fixture:sgsst-evidence/doc-1", "status": "PENDIENTE"},
            {"evidence_ref": "fixture:sgsst-evidence/doc-2", "status": "REQUIERE_REVISION"},
        ])
        self.assert_safe(project(pending_and_review), "REQUIERE_REVISION", 2, True)
        content = CLASS.read_text(encoding="utf-8").lower()
        for dangerous in ("curl_", "file_get_contents(", "pdo(", "doctrine", "httpclient",
                          "shell_exec(", "exec(", "http://", "https://", "law_", "min_wage",
                          "minimum_workers", "colombian_regulation"):
            self.assertNotIn(dangerous, content)
        self.assertNotIn(TENANT, json.dumps(first))
        self.assertNotIn(ENTITY, json.dumps(first))


if __name__ == "__main__":
    unittest.main()
