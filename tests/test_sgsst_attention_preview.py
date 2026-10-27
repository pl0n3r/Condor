#!/usr/bin/env python3
"""F3d: execute real PHP to verify a synthetic, read-only SG-SST HTML preview."""
from __future__ import annotations

import json
import subprocess
import unittest
from pathlib import Path

ROOT = Path(__file__).resolve().parents[1]
COMPLIANCE = ROOT / "src/Application/Compliance"
TENANT = "fixture:tenant/demo-a"
ENTITY = "fixture:legal-entity/demo-a"
CHECKLIST = "fixture:sgsst-checklist/check-1"
PROFILE = "fixture:sgsst-profile/profile-1"


def evidence(**changes: object) -> dict:
    row = {"fixture_ref": CHECKLIST, "tenant_ref": TENANT, "legal_entity_ref": ENTITY,
           "precheck_ref": PROFILE,
           "evidence": [{"evidence_ref": "fixture:sgsst-evidence/doc-1", "status": "PENDIENTE"}]}
    row.update(changes)
    return row


def action(**changes: object) -> dict:
    row = {"action_ref": "fixture:sgsst-action/action-1", "tenant_ref": TENANT,
           "legal_entity_ref": ENTITY, "checklist_ref": CHECKLIST, "status": "PENDIENTE"}
    row.update(changes)
    return row


def followup(**changes: object) -> dict:
    row = {"fixture_ref": "fixture:sgsst-followup/plan-1", "tenant_ref": TENANT,
           "legal_entity_ref": ENTITY, "checklist_ref": CHECKLIST, "actions": [action()]}
    row.update(changes)
    return row


def precheck(**changes: object) -> dict:
    row = {"state": "EN_REVISION", "synthetic_only": True,
           "requires_professional_review": True, "structurally_reviewable": True}
    row.update(changes)
    return row


def render(
    checklist: dict | None = None,
    plan: dict | None = None,
    intake: dict | None = None,
    tenant: str = TENANT,
    entity: str = ENTITY,
) -> str:
    php = r"""
require $argv[1];
require $argv[2];
require $argv[3];
use App\Application\Compliance\OccupationalSafetyAttentionPreview;
$data = json_decode($argv[4], true, 32, JSON_THROW_ON_ERROR);
echo OccupationalSafetyAttentionPreview::render(
    $data['checklist'], $data['plan'], $data['intake'],
    $data['tenant'], $data['entity']
);
"""
    payload = {"checklist": evidence() if checklist is None else checklist,
               "plan": followup() if plan is None else plan,
               "intake": precheck() if intake is None else intake,
               "tenant": tenant, "entity": entity}
    run = subprocess.run(
        ["php", "-n", "-d", "allow_url_fopen=0", "-r", php,
         str(COMPLIANCE / "OccupationalSafetyEvidenceChecklistProjection.php"),
         str(COMPLIANCE / "OccupationalSafetyFollowUpProjection.php"),
         str(COMPLIANCE / "OccupationalSafetyAttentionPreview.php"),
         json.dumps(payload)],
        cwd=ROOT, text=True, capture_output=True, check=True, timeout=20,
    )
    return run.stdout


class SgsstAttentionPreviewTests(unittest.TestCase):
    def assert_review_only(self, html: str) -> None:
        self.assertIn("REQUIERE_REVISION", html)
        self.assertNotIn("PENDIENTE</strong>", html)
        self.assertEqual(1, html.count("<li "))
        self.assertNotIn("fixture:", html)

    def test_readonly_fixture_pending_and_review_states(self) -> None:
        pending = render()
        self.assertIn("<section", pending)
        self.assertIn("<h2>Pendientes SG-SST (ejemplo)</h2>", pending)
        self.assertIn("aria-label=", pending)
        self.assertIn("grid-template-columns:repeat(auto-fit", pending)
        self.assertEqual(3, pending.count("<li "))
        self.assertEqual(3, pending.count("PENDIENTE</strong>"))
        self.assertNotIn("fixture:", pending)
        self.assertEqual(pending, render())

        review = render(checklist=evidence(evidence=[
            {"evidence_ref": "fixture:sgsst-evidence/doc-1", "status": "REQUIERE_REVISION"}
        ]))
        self.assertEqual(3, review.count("REQUIERE_REVISION</strong>"))
        mixed = render(plan=followup(actions=[
            action(),
            action(action_ref="fixture:sgsst-action/action-2", status="REQUIERE_REVISION"),
        ]))
        self.assertEqual(4, mixed.count("<li "))
        self.assertIn("REQUIERE_REVISION</strong>", mixed)
        self.assertNotIn("action-2", mixed)

    def test_cross_tenant_and_entity_fail_closed(self) -> None:
        cases = [
            {"tenant": "fixture:tenant/demo-b"},
            {"entity": "fixture:legal-entity/demo-b"},
            {"tenant": "not-a-fixture"},
            {"checklist": evidence(tenant_ref="fixture:tenant/demo-b")},
            {"checklist": evidence(legal_entity_ref="fixture:legal-entity/demo-b")},
            {"plan": followup(tenant_ref="fixture:tenant/demo-b")},
            {"plan": followup(legal_entity_ref="fixture:legal-entity/demo-b")},
            {"plan": followup(actions=[action(tenant_ref="fixture:tenant/demo-b")])},
            {"plan": followup(actions=[action(legal_entity_ref="fixture:legal-entity/demo-b")])},
            {"plan": followup(actions=[action(checklist_ref="fixture:sgsst-checklist/other")])},
            {"plan": followup(checklist_ref="fixture:sgsst-checklist/other")},
            # Internally consistent but linked to a DIFFERENT valid checklist:
            # both F3b and F3c independently accept these without the F3d link guard.
            {"plan": followup(
                checklist_ref="fixture:sgsst-checklist/other",
                actions=[action(checklist_ref="fixture:sgsst-checklist/other")],
            )},
            {"checklist": evidence(fixture_ref="fixture:sgsst-checklist/other")},
            {"checklist": evidence(evidence=[])},
            {"plan": followup(actions=[])},
            {"checklist": evidence(evidence=[
                {"evidence_ref": "fixture:sgsst-evidence/doc-1", "status": "PENDIENTE"}] * 2)},
            {"plan": followup(actions=[action(), action()])},
            {"intake": precheck(structurally_reviewable=False)},
        ]
        for kwargs in cases:
            with self.subTest(case=kwargs):
                self.assert_review_only(render(**kwargs))

    def test_no_legal_approval_or_real_data(self) -> None:
        private = "persona-privada-no-imprimir"
        cases = [
            {"checklist": evidence(person=private)},
            {"plan": followup(person=private)},
            {"plan": followup(actions=[action(name=private)])},
            {"checklist": evidence(evidence=[{"evidence_ref": private, "status": "PENDIENTE"}])},
            {"plan": followup(actions=[action(status="CUMPLE")])},
            {"plan": followup(actions=[action(status="NO_APLICA")])},
            {"intake": precheck(state="CUMPLE")},
            {"intake": {}},
            {"checklist": {}},
            {"plan": {}},
        ]
        for kwargs in cases:
            with self.subTest(case=kwargs):
                html = render(**kwargs)
                self.assert_review_only(html)
                self.assertNotIn(private, html)
        normal = render()
        for forbidden in (TENANT, ENTITY, "doc-1", "action-1", "name=",
                          "type=\"submit\"", "<form", "<script",
                          "CUMPLE", "NO_APLICA", "CERTIFICADO"):
            with self.subTest(forbidden=forbidden):
                self.assertNotIn(forbidden, normal)
        source = (COMPLIANCE / "OccupationalSafetyAttentionPreview.php").read_text(
            encoding="utf-8").lower()
        for side_effect in ("file_get_contents(", "curl_", "httpclient", "mysqli",
                            "doctrine", "pdo(", "file_put_contents(", "shell_exec(",
                            "https://", "http://", "getenv(", "$_server", "echo $tenant"):
            self.assertNotIn(side_effect, source)


if __name__ == "__main__":
    unittest.main()
