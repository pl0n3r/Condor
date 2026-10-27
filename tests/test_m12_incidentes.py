#!/usr/bin/env python3
"""M12 S06-S08: genuine PHP execution, synthetic-only risk projection."""
from __future__ import annotations

import copy
import json
import subprocess
import unittest
from pathlib import Path

ROOT = Path(__file__).resolve().parents[1]
CLASS = ROOT / "src/Application/Risk/RiskIncidentTreatment.php"
TENANT_A = "fixture:tenant/company-a"
TENANT_B = "fixture:tenant/company-b"
INCIDENT = "fixture:risk-incident/example-1"


def incident(tenant: str = TENANT_A, **changes: object) -> dict:
    item = {
        "tenant_ref": tenant,
        "incident_ref": INCIDENT,
        "risk_ref": "fixture:risk/operational-1",
        "state": "PENDIENTE",
    }
    item.update(changes)
    return item


def plan(tenant: str = TENANT_A, **changes: object) -> dict:
    item = {
        "tenant_ref": tenant,
        "plan_ref": "fixture:risk-plan/plan-1",
        "incident_ref": INCIDENT,
        "owner_ref": "fixture:responsible/role-a",
        "due_on": "2026-10-01",
        "state": "PENDIENTE",
    }
    item.update(changes)
    return item


def payload(tenant: str = TENANT_A, **changes: object) -> dict:
    item = {"tenant_ref": tenant, "incidents": [incident(tenant)], "plans": [plan(tenant)]}
    item.update(changes)
    return item


def project(
    data: dict | None = None, tenant: str = TENANT_A, today: str = "2026-10-27"
) -> dict:
    php = r"""
require $argv[1];
use App\Application\Risk\RiskIncidentTreatment;
$data = json_decode($argv[2], true, 32, JSON_THROW_ON_ERROR);
echo json_encode(RiskIncidentTreatment::project(
    $data['payload'], $data['tenant'], $data['today']
), JSON_THROW_ON_ERROR);
"""
    result = subprocess.run(
        ["php", "-n", "-d", "allow_url_fopen=0", "-r", php,
         str(CLASS), json.dumps({"payload": payload() if data is None else data,
                                  "tenant": tenant, "today": today})],
        cwd=ROOT, text=True, capture_output=True, check=True, timeout=20,
    )
    return json.loads(result.stdout)


class RiskIncidentTreatmentTests(unittest.TestCase):
    def assert_rejected(self, result: dict) -> None:
        self.assertEqual("REQUIERE_REVISION", result["state"])
        self.assertFalse(result["structurally_reviewable"])
        self.assertEqual([], result["incidents"])
        self.assertEqual([], result["plans"])
        self.assertEqual(0, result["incident_count"])
        self.assertEqual(0, result["plan_count"])
        self.assertTrue(result["synthetic_only"])
        self.assertTrue(result["requires_professional_review"])

    def test_resultado_determinista(self) -> None:
        first = project()
        self.assertEqual(first, project())
        self.assertEqual("PENDIENTE", first["state"])
        self.assertTrue(first["structurally_reviewable"])
        self.assertEqual(1, first["incident_count"])
        self.assertEqual(1, first["plan_count"])
        self.assertTrue(first["plans"][0]["follow_up_overdue"])
        self.assertFalse(project(today="2026-09-30")["plans"][0]["follow_up_overdue"])
        self.assertFalse(project(today="2026-10-01")["plans"][0]["follow_up_overdue"])
        self.assert_rejected(project(today="2026-02-30"))
        self.assert_rejected(project(payload(plans=[plan(due_on="2026-02-30")])))
        self.assertEqual(0, project(payload(incidents=[], plans=[]))["plan_count"])

    def test_aislamiento_por_empresa(self) -> None:
        company_b = project(payload(TENANT_B), tenant=TENANT_B)
        self.assertTrue(company_b["structurally_reviewable"])
        self.assertEqual(1, company_b["plan_count"])
        for foreign in (
            project(payload(), tenant=TENANT_B),
            project(payload(incidents=[incident(TENANT_B)])),
            project(payload(plans=[plan(TENANT_B)])),
            project(payload(incidents=[incident(tenant_ref=TENANT_B)])),
            project(payload(plans=[plan(tenant_ref=TENANT_B)])),
        ):
            self.assert_rejected(foreign)
            self.assertNotIn("company-", json.dumps(foreign))
        self.assert_rejected(project(payload(plans=[plan(incident_ref="fixture:risk-incident/other")])))
        self.assert_rejected(project(payload(incidents=[incident(), incident()])))
        self.assert_rejected(project(payload(plans=[plan(), plan()])))
        self.assert_rejected(project(payload(incidents={"foreign": incident()})))
        self.assert_rejected(project(payload(incidents=[incident()] * 51)))

    def test_rechaza_datos_reales(self) -> None:
        samples = (
            payload(email="person@example.test"),
            payload(incidents=[incident(document_number="1234567890")]),
            payload(plans=[plan(phone="3001234567")]),
            payload(plans=[plan(owner_ref="person@example.test")]),
            payload(plans=[plan(owner_ref="3001234567")]),
            payload(plans=[plan(owner_ref="fixture:responsible/ana@example.test")]),
            payload(incidents=[incident(risk_ref="123456789")]),
            payload(plans=[plan(plan_ref="https://example.test/secret")]),
            payload(plans=[plan(due_on="2026-10-01", owner_name="Real Person")]),
            payload(incidents=[incident(state=True)]),
            payload(plans=[plan(state="APROBADO")]),
        )
        for case in samples:
            with self.subTest(case=case):
                value = project(case)
                self.assert_rejected(value)
                serialized = json.dumps(value)
                for forbidden in ("@example", "300123", "123456789", "Real Person"):
                    self.assertNotIn(forbidden, serialized)
        source = CLASS.read_text(encoding="utf-8").lower()
        for forbidden in ("pdo(", "doctrine", "mysqli", "curl_", "httpclient", "file_get_contents(",
                          "shell_exec(", "exec(", "fetch(", "http://", "https://"):
            self.assertNotIn(forbidden, source)

    def test_sin_cierre_automatico(self) -> None:
        first = project()
        self.assertEqual("PENDIENTE", first["incidents"][0]["state"])
        self.assertEqual("PENDIENTE", first["plans"][0]["state"])
        self.assertTrue(first["requires_professional_review"])
        self.assertTrue(first["plans"][0]["follow_up_overdue"])
        for done in ("CERRADO", "APROBADO", "RESUELTO", "CUMPLE", "NO_APLICA", None, False):
            with self.subTest(state=done):
                self.assert_rejected(project(payload(incidents=[incident(state=done)])))
                self.assert_rejected(project(payload(plans=[plan(state=done)])))
        awaiting_review = project(payload(plans=[plan(state="REQUIERE_REVISION")]))
        self.assertEqual("REQUIERE_REVISION", awaiting_review["plans"][0]["state"])
        self.assertEqual("REQUIERE_REVISION", awaiting_review["state"])
        self.assertTrue(awaiting_review["requires_professional_review"])
        incident_review = project(payload(incidents=[incident(state="REQUIERE_REVISION")]))
        self.assertEqual("REQUIERE_REVISION", incident_review["state"])
        self.assertEqual("REQUIERE_REVISION", incident_review["incidents"][0]["state"])
        for state in ("CERRADO", "APROBADO", "RESUELTO", "CUMPLE"):
            self.assertNotIn(state, json.dumps(first))
        # Avoid unrecognized input keys silently granting approval.
        self.assert_rejected(project(payload(plans=[plan(approved=True)])))
        self.assertEqual(first, project(copy.deepcopy(payload())))


if __name__ == "__main__":
    unittest.main()
