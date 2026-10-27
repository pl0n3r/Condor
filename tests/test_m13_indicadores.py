#!/usr/bin/env python3
"""Condor M13 S01/S04/S07: genuine, isolated PHP projection on synthetic data."""
from __future__ import annotations

import copy
import json
from pathlib import Path
import subprocess
import unittest

ROOT = Path(__file__).resolve().parents[1]
CLASS = ROOT / "src/Application/Quality/QualityIndicatorsEfficacy.php"
TENANT_A = "fixture:tenant/company-a"
TENANT_B = "fixture:tenant/company-b"
ACTION = "fixture:quality-action/action-1"


def indicator(tenant: str = TENANT_A, **updates: object) -> dict:
    result = {
        "tenant_ref": tenant,
        "indicator_ref": "fixture:quality-indicator/quality-score",
        "goal": 90,
        "previous": 70,
        "current": 85,
        "state": "PENDIENTE",
    }
    result.update(updates)
    return result


def audit(tenant: str = TENANT_A, **updates: object) -> dict:
    result = {
        "tenant_ref": tenant,
        "audit_ref": "fixture:quality-audit/audit-1",
        "auditor_ref": "fixture:quality-auditor/auditor-1",
        "scheduled_on": "2026-10-01",
        "state": "PENDIENTE",
    }
    result.update(updates)
    return result


def action(tenant: str = TENANT_A, **updates: object) -> dict:
    result = {
        "tenant_ref": tenant,
        "action_ref": ACTION,
        "source_closed": True,
        "closure_evidence_ref": "fixture:quality-evidence/closure-1",
    }
    result.update(updates)
    return result


def verification(tenant: str = TENANT_A, **updates: object) -> dict:
    result = {
        "tenant_ref": tenant,
        "verification_ref": "fixture:quality-verification/verification-1",
        "action_ref": ACTION,
        "evidence_ref": "fixture:quality-evidence/verification-1",
        "reviewer_ref": "fixture:quality-reviewer/reviewer-1",
        "state": "PENDIENTE",
    }
    result.update(updates)
    return result


def payload(tenant: str = TENANT_A, **updates: object) -> dict:
    result = {
        "tenant_ref": tenant,
        "indicators": [indicator(tenant)],
        "audits": [audit(tenant)],
        "actions": [action(tenant)],
        "verifications": [verification(tenant)],
    }
    result.update(updates)
    return result


def project(data: dict | None = None, tenant: str = TENANT_A, today: str = "2026-10-27") -> dict:
    source = r"""
require $argv[1];
use App\Application\Quality\QualityIndicatorsEfficacy;
$data = json_decode($argv[2], true, 32, JSON_THROW_ON_ERROR);
echo json_encode(QualityIndicatorsEfficacy::project(
    $data['payload'], $data['tenant'], $data['reference_date']
), JSON_THROW_ON_ERROR);
"""
    result = subprocess.run(
        ["php", "-n", "-d", "allow_url_fopen=0", "-r", source,
         str(CLASS), json.dumps({
             "payload": payload() if data is None else data,
             "tenant": tenant, "reference_date": today,
         })],
        cwd=ROOT, text=True, capture_output=True, check=True, timeout=20,
    )
    return json.loads(result.stdout)


class QualityIndicatorsEfficacyTests(unittest.TestCase):
    def assert_rejected(self, value: dict) -> None:
        self.assertEqual("REQUIERE_REVISION", value["state"])
        self.assertFalse(value["structurally_reviewable"])
        self.assertTrue(value["synthetic_only"])
        self.assertTrue(value["requires_professional_review"])
        for name in ("indicators", "audits", "verifications"):
            self.assertEqual([], value[name])
        for name in ("indicator_count", "audit_count", "verification_count"):
            self.assertEqual(0, value[name])

    def test_resultado_determinista(self) -> None:
        first = project()
        self.assertEqual(first, project(copy.deepcopy(payload())))
        self.assertEqual(first, project())
        self.assertTrue(first["structurally_reviewable"])
        self.assertEqual("PENDIENTE", first["state"])
        self.assertEqual([1, 1, 1], [
            first["indicator_count"], first["audit_count"], first["verification_count"],
        ])
        score = first["indicators"][0]
        self.assertEqual(90, score["goal"])
        self.assertEqual("SUBE", score["trend"])
        self.assertEqual("BAJO_META_NUMERICA", score["target_observation"])
        self.assertTrue(first["audits"][0]["attention_overdue"])
        self.assertFalse(project(today="2026-09-30")["audits"][0]["attention_overdue"])
        self.assertFalse(project(today="2026-10-01")["audits"][0]["attention_overdue"])
        self.assertEqual("ESTABLE", project(payload(
            indicators=[indicator(current=70)]) )["indicators"][0]["trend"])
        self.assertEqual("BAJA", project(payload(
            indicators=[indicator(current=60)]) )["indicators"][0]["trend"])
        self.assertEqual("EN_META_NUMERICA", project(payload(
            indicators=[indicator(current=90)]) )["indicators"][0]["target_observation"])
        self.assert_rejected(project(today="2026-02-30"))
        self.assert_rejected(project(payload(audits=[audit(scheduled_on="2026-02-30")])))
        self.assertEqual(0, project(payload(
            indicators=[], audits=[], actions=[], verifications=[]))["verification_count"])

    def test_aislamiento_por_empresa(self) -> None:
        first = project()
        other = project(payload(TENANT_B), tenant=TENANT_B)
        self.assertEqual(first, other, "Same synthetic numeric data must not change behavior")
        for mixed in (
            project(payload(), tenant=TENANT_B),
            project(payload(indicators=[indicator(TENANT_B)])),
            project(payload(audits=[audit(TENANT_B)])),
            project(payload(actions=[action(TENANT_B)])),
            project(payload(verifications=[verification(TENANT_B)])),
            project(payload(TENANT_B, actions=[action(TENANT_A)]), tenant=TENANT_B),
        ):
            self.assert_rejected(mixed)
            self.assertNotIn("company-", json.dumps(mixed))
        self.assert_rejected(project(payload(verifications=[
            verification(action_ref="fixture:quality-action/foreign")])))
        self.assert_rejected(project(payload(indicators=[indicator(), indicator()])))
        self.assert_rejected(project(payload(audits=[audit(), audit()])))
        self.assert_rejected(project(payload(actions=[action(), action()])))
        self.assert_rejected(project(payload(verifications=[verification(), verification()])))
        self.assert_rejected(project(payload(indicators=[indicator()] * 51)))
        self.assert_rejected(project(payload(audits={"foreign": audit()})))

    def test_rechaza_datos_reales(self) -> None:
        cases = (
            payload(email="person@example.test"),
            payload(indicators=[indicator(document_number="1234567890")]),
            payload(indicators=[indicator(indicator_ref="person@example.test")]),
            payload(indicators=[indicator(indicator_ref="fixture:quality-indicator/3001234567")]),
            payload(indicators=[indicator(goal="90")]),
            payload(indicators=[indicator(goal=True)]),
            payload(indicators=[indicator(goal=0)]),
            payload(indicators=[indicator(previous=-1)]),
            payload(indicators=[indicator(current=1000000001)]),
            payload(indicators=[indicator(state="APROBADO")]),
            payload(audits=[audit(auditor_ref="person@example.test")]),
            payload(audits=[audit(phone="3001234567")]),
            payload(audits=[audit(state="CERRADA")]),
            payload(actions=[action(closure_evidence_ref="https://example.test/real")]),
            payload(actions=[action(source_closed=False)]),
            payload(actions=[action(source_closed="yes")]),
            payload(actions=[action(identification="1234567890")]),
            payload(verifications=[verification(evidence_ref="3001234567")]),
            payload(verifications=[verification(reviewer_name="Real Person")]),
            payload(verifications=[verification(state="EFICAZ")]),
            payload(verifications=[verification(approved=True)]),
        )
        for bad in cases:
            with self.subTest(payload=bad):
                rejected = project(bad)
                self.assert_rejected(rejected)
                serial = json.dumps(rejected)
                for private in ("@example", "300123", "123456789", "Real Person"):
                    self.assertNotIn(private, serial)
        source = CLASS.read_text(encoding="utf-8").lower()
        for forbidden in (
            "pdo(", "doctrine", "mysqli", "curl_", "httpclient",
            "file_get_contents(", "shell_exec(", "exec(", "fetch(", "http://", "https://",
        ):
            self.assertNotIn(forbidden, source)

    def test_sin_cierre_automatico(self) -> None:
        result = project()
        self.assertEqual("PENDIENTE", result["indicators"][0]["state"])
        self.assertEqual("PENDIENTE", result["audits"][0]["state"])
        self.assertEqual("PENDIENTE", result["verifications"][0]["state"])
        self.assertEqual("REQUIERE_REVISION", result["verifications"][0]["efficacy_state"])
        self.assertTrue(result["requires_professional_review"])
        self.assertTrue(result["verifications"][0]["requires_professional_review"])
        for completed in ("CERRADO", "APROBADO", "EFICAZ", "CUMPLE", "NO_APLICA", None, False):
            with self.subTest(completed=completed):
                self.assert_rejected(project(payload(indicators=[indicator(state=completed)])))
                self.assert_rejected(project(payload(audits=[audit(state=completed)])))
                self.assert_rejected(project(payload(
                    verifications=[verification(state=completed)])))
        review = project(payload(verifications=[
            verification(state="REQUIERE_REVISION")]))
        self.assertEqual("REQUIERE_REVISION", review["state"])
        self.assertEqual("REQUIERE_REVISION", review["verifications"][0]["state"])
        self.assertEqual("REQUIERE_REVISION", review["verifications"][0]["efficacy_state"])
        self.assert_rejected(project(payload(actions=[])))
        self.assert_rejected(project(payload(actions=[
            action(closure_evidence_ref=None)])))
        self.assert_rejected(project(payload(
            verifications=[verification(reviewer_ref=None)])))
        for state in ("CERRADO", "APROBADO", "EFICAZ", "CUMPLE"):
            self.assertNotIn(state, json.dumps(result))


if __name__ == "__main__":
    unittest.main()
