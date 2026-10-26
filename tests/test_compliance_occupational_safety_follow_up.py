#!/usr/bin/env python3
"""F3c: real PHP execution with synthetic SG-SST follow-up and real F3b signal."""
from __future__ import annotations

import json
import subprocess
import unittest
from pathlib import Path

ROOT = Path(__file__).resolve().parents[1]
CLASS = ROOT / "src/Application/Compliance/OccupationalSafetyFollowUpProjection.php"
CHECKLIST_CLASS = ROOT / "src/Application/Compliance/OccupationalSafetyEvidenceChecklistProjection.php"
TENANT = "fixture:tenant/tenant-a"
ENTITY = "fixture:legal-entity/entity-a"
CHECKLIST = "fixture:sgsst-checklist/example-1"
PROFILE = "fixture:sgsst-profile/example-1"


def action(**changes: object) -> dict:
    item = {
        "action_ref": "fixture:sgsst-action/action-1",
        "tenant_ref": TENANT,
        "legal_entity_ref": ENTITY,
        "checklist_ref": CHECKLIST,
        "status": "PENDIENTE",
    }
    item.update(changes)
    return item


def followup(**changes: object) -> dict:
    item = {
        "fixture_ref": "fixture:sgsst-followup/example-1",
        "tenant_ref": TENANT,
        "legal_entity_ref": ENTITY,
        "checklist_ref": CHECKLIST,
        "actions": [action()],
    }
    item.update(changes)
    return item


def checklist(**changes: object) -> dict:
    item = {
        "fixture_ref": CHECKLIST,
        "tenant_ref": TENANT,
        "legal_entity_ref": ENTITY,
        "precheck_ref": PROFILE,
        "evidence": [{"evidence_ref": "fixture:sgsst-evidence/doc-1", "status": "PENDIENTE"}],
    }
    item.update(changes)
    return item


def project(
    task: dict | None = None,
    tenant: str = TENANT,
    entity: str = ENTITY,
    evidence: dict | None = None,
    summary_override: dict | None = None,
) -> dict:
    php = r"""
require $argv[1];
require $argv[2];
use App\Application\Compliance\OccupationalSafetyEvidenceChecklistProjection;
use App\Application\Compliance\OccupationalSafetyFollowUpProjection;
$data = json_decode($argv[3], true, 32, JSON_THROW_ON_ERROR);
$precheck = [
    'state' => 'EN_REVISION',
    'requires_professional_review' => true,
    'synthetic_only' => true,
    'structurally_reviewable' => true,
];
$summary = OccupationalSafetyEvidenceChecklistProjection::project(
    $data['checklist'], $precheck, $data['tenant'], $data['entity']
);
if ($data['summary_override'] !== null) {
    $summary = $data['summary_override'];
}
echo json_encode(OccupationalSafetyFollowUpProjection::project(
    $data['task'], $summary, $data['tenant'], $data['entity']
), JSON_THROW_ON_ERROR);
"""
    payload = {
        "task": followup() if task is None else task,
        "tenant": tenant,
        "entity": entity,
        "checklist": checklist() if evidence is None else evidence,
        "summary_override": summary_override,
    }
    done = subprocess.run(
        ["php", "-n", "-d", "allow_url_fopen=0", "-r", php,
         str(CHECKLIST_CLASS), str(CLASS), json.dumps(payload)],
        cwd=ROOT, text=True, capture_output=True, check=True, timeout=20,
    )
    return json.loads(done.stdout)


class OccupationalSafetyFollowUpTests(unittest.TestCase):
    def assert_safe(
        self, value: dict, state: str, count: int, reviewable: bool,
    ) -> None:
        self.assertEqual(state, value["state"])
        self.assertIs(value["synthetic_only"], True)
        self.assertIs(value["requires_professional_review"], True)
        self.assertIs(value["structurally_reviewable"], reviewable)
        self.assertEqual(count, value["action_count"])
        self.assertEqual(count, len(value["actions"]))
        self.assertEqual(
            {"state", "synthetic_only", "requires_professional_review",
             "structurally_reviewable", "action_count", "actions"},
            set(value),
        )
        text = json.dumps(value)
        for forbidden in ("CUMPLE", "NO_APLICA", "CERRADO_LEGALMENTE", "tenant-a", "entity-a"):
            self.assertNotIn(forbidden, text)

    def test_pending_or_unknown_evidence_cannot_close_action(self) -> None:
        self.assert_safe(project(), "PENDIENTE", 1, True)
        self.assert_safe(project(followup(actions=[])), "PENDIENTE", 0, False)
        review = project(evidence=checklist(evidence=[
            {"evidence_ref": "fixture:sgsst-evidence/doc-1", "status": "REQUIERE_REVISION"},
        ]))
        self.assert_safe(review, "REQUIERE_REVISION", 1, True)
        self.assertEqual("REQUIERE_REVISION", review["actions"][0]["state"])
        for status in ("DESCONOCIDO", "CUMPLE", "NO_APLICA", "CERRADO_LEGALMENTE", None):
            with self.subTest(status=status):
                item = followup(actions=[action(status=status)])
                self.assert_safe(project(item), "REQUIERE_REVISION", 0, False)
        for status in ("DESCONOCIDO", "CUMPLE", None):
            with self.subTest(evidence=status):
                bad = checklist(evidence=[
                    {"evidence_ref": "fixture:sgsst-evidence/doc-1", "status": status},
                ])
                self.assert_safe(project(evidence=bad), "REQUIERE_REVISION", 0, False)
        self.assert_safe(
            project(followup(actions=[action()] * 2)),
            "REQUIERE_REVISION", 0, False,
        )
        self.assert_safe(
            project(followup(actions=[
                action(action_ref=f"fixture:sgsst-action/action-{i}") for i in range(51)
            ])),
            "REQUIERE_REVISION", 0, False,
        )

    def test_cross_tenant_and_legal_entity_action_rejected(self) -> None:
        bad_cases = [
            followup(tenant_ref="fixture:tenant/tenant-b"),
            followup(legal_entity_ref="fixture:legal-entity/entity-b"),
            followup(actions=[action(tenant_ref="fixture:tenant/tenant-b")]),
            followup(actions=[action(legal_entity_ref="fixture:legal-entity/entity-b")]),
            followup(actions=[action(checklist_ref="fixture:sgsst-checklist/other")]),
            followup(checklist_ref="fixture:sgsst-checklist/other"),
        ]
        for item in bad_cases:
            with self.subTest(case=item):
                self.assert_safe(project(item), "REQUIERE_REVISION", 0, False)
        self.assert_safe(
            project(followup(), tenant="fixture:tenant/tenant-b"),
            "REQUIERE_REVISION", 0, False,
        )
        self.assert_safe(
            project(followup(), entity="fixture:legal-entity/entity-b"),
            "REQUIERE_REVISION", 0, False,
        )

    def test_only_opaque_fixtures_without_pii_or_external_effects(self) -> None:
        bad_cases = [
            followup(email="someone@example.test"),
            followup(fixture_ref="https://example.test/actions"),
            followup(actions=[action(person_name="private")]),
            followup(actions=[action(action_ref="https://example.test/1")]),
            followup(actions=[action(action_ref="fixture:sgsst-action/a@b")]),
            followup(actions=[action(action_ref="fixture:sgsst-action/" + "a" * 100)]),
            followup(actions=[action(status=True)]),
            followup(actions=[action(status="PENDIENTE", medical_record={"x": "secret"})]),
            followup(actions={"0": action()}),
        ]
        for bad in bad_cases:
            with self.subTest(case=bad):
                value = project(bad)
                self.assert_safe(value, "REQUIERE_REVISION", 0, False)
                for private in ("someone", "example.test", "private", "secret"):
                    self.assertNotIn(private, json.dumps(value))
        source = CLASS.read_text(encoding="utf-8").lower()
        for dangerous in ("file_get_contents(", "curl_", "httpclient", "shell_exec(",
                          "mysqli", "doctrine", "pdo(", "http://", "https://",
                          "minimum_workers", "colombian_regulation", "law_"):
            self.assertNotIn(dangerous, source)

    def test_deterministic_professional_review_and_conservative_summary(self) -> None:
        expected = project()
        self.assert_safe(expected, "PENDIENTE", 1, True)
        self.assertEqual(expected, project())
        self.assertEqual(
            [{"action_ref": "fixture:sgsst-action/action-1", "state": "PENDIENTE"}],
            expected["actions"],
        )
        mixed = followup(actions=[
            action(),
            action(action_ref="fixture:sgsst-action/action-2", status="REQUIERE_REVISION"),
        ])
        self.assert_safe(project(mixed), "REQUIERE_REVISION", 2, True)
        # F3b invalid summary cannot attest a single action.
        invalid_summaries = [
            {"state": "CUMPLE", "requires_professional_review": True,
             "synthetic_only": True, "structurally_reviewable": True,
             "evidence_count": 1},
            {"state": "PENDIENTE", "requires_professional_review": False,
             "synthetic_only": True, "structurally_reviewable": True,
             "evidence_count": 1},
            {"state": "PENDIENTE", "requires_professional_review": True,
             "synthetic_only": True, "structurally_reviewable": False,
             "evidence_count": 1},
            {"state": "PENDIENTE", "requires_professional_review": True,
             "synthetic_only": True, "structurally_reviewable": True,
             "evidence_count": 1, "person": "private"},
        ]
        for invalid in invalid_summaries:
            with self.subTest(summary=invalid):
                self.assert_safe(
                    project(summary_override=invalid), "REQUIERE_REVISION", 0, False,
                )


if __name__ == "__main__":
    unittest.main()
