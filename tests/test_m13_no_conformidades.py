#!/usr/bin/env python3
"""M13 S02-S06 acceptance: execute genuine offline PHP, no mocks or skips."""
from __future__ import annotations

import copy
import json
import subprocess
import unittest
from pathlib import Path

ROOT = Path(__file__).resolve().parents[1]
CLASS = ROOT / "src/Application/Quality/QualityNonconformityActions.php"
TENANT_A = "fixture:tenant/company-a"
TENANT_B = "fixture:tenant/company-b"
NC = "fixture:quality-nc/issue-a"


def nonconformity(tenant: str = TENANT_A, **changes: object) -> dict:
    item = {
        "tenant_ref": tenant,
        "nonconformity_ref": NC,
        "finding_ref": "fixture:quality-finding/evidence-a",
        "cause_ref": "fixture:quality-cause/root-cause-a",
        "cause_state": "PENDIENTE",
        "state": "PENDIENTE",
    }
    item.update(changes)
    return item


def action(tenant: str = TENANT_A, **changes: object) -> dict:
    item = {
        "tenant_ref": tenant,
        "action_ref": "fixture:quality-action/corrective-a",
        "nonconformity_ref": NC,
        "kind": "CORRECTIVA",
        "owner_ref": "fixture:responsible/quality-role",
        "due_on": "2026-10-21",
        "state": "PENDIENTE",
        "verification_state": "PENDIENTE",
    }
    item.update(changes)
    return item


def payload(tenant: str = TENANT_A, **changes: object) -> dict:
    item = {
        "tenant_ref": tenant,
        "nonconformities": [nonconformity(tenant)],
        "actions": [action(tenant)],
    }
    item.update(changes)
    return item


def project(
    data: dict | None = None, tenant: str = TENANT_A, today: str = "2026-10-27"
) -> dict:
    php = r"""
require $argv[1];
use App\Application\Quality\QualityNonconformityActions;
$data = json_decode($argv[2], true, 32, JSON_THROW_ON_ERROR);
echo json_encode(QualityNonconformityActions::project(
    $data['payload'], $data['tenant'], $data['today']
), JSON_THROW_ON_ERROR);
"""
    result = subprocess.run(
        ["php", "-n", "-d", "allow_url_fopen=0", "-r", php, str(CLASS),
         json.dumps({"payload": payload() if data is None else data,
                     "tenant": tenant, "today": today})],
        cwd=ROOT, text=True, capture_output=True, check=True, timeout=20,
    )
    return json.loads(result.stdout)


class QualityNonconformityActionsTests(unittest.TestCase):
    def assert_rejected(self, value: dict) -> None:
        self.assertEqual("REQUIERE_REVISION", value["state"])
        self.assertFalse(value["structurally_reviewable"])
        self.assertTrue(value["synthetic_only"])
        self.assertTrue(value["requires_professional_review"])
        self.assertEqual(0, value["nonconformity_count"])
        self.assertEqual(0, value["action_count"])
        self.assertEqual([], value["nonconformities"])
        self.assertEqual([], value["actions"])

    def test_resultado_determinista(self) -> None:
        first = project()
        self.assertEqual(first, project())
        self.assertEqual(first, project(copy.deepcopy(payload())))
        self.assertTrue(first["structurally_reviewable"])
        self.assertTrue(first["requires_professional_review"])
        self.assertEqual("PENDIENTE", first["state"])
        self.assertEqual(1, first["nonconformity_count"])
        self.assertEqual(1, first["action_count"])
        self.assertEqual("fixture:quality-cause/root-cause-a",
                         first["nonconformities"][0]["cause_ref"])
        self.assertTrue(first["actions"][0]["follow_up_overdue"])
        self.assertFalse(project(today="2026-10-21")["actions"][0]["follow_up_overdue"])
        self.assertFalse(project(today="2026-10-01")["actions"][0]["follow_up_overdue"])
        self.assert_rejected(project(today="2026-02-29"))
        self.assert_rejected(project(payload(actions=[action(due_on="2026-02-30")])))
        self.assertEqual(0, project(payload(nonconformities=[], actions=[]))["action_count"])
        preventive = project(payload(actions=[action(kind="PREVENTIVA")]))
        self.assertTrue(preventive["structurally_reviewable"])
        self.assertEqual("PREVENTIVA", preventive["actions"][0]["kind"])

    def test_aislamiento_por_empresa(self) -> None:
        other = project(payload(TENANT_B), tenant=TENANT_B)
        self.assertTrue(other["structurally_reviewable"])
        self.assertEqual(1, other["action_count"])
        for foreign in (
            project(payload(), tenant=TENANT_B),
            project(payload(nonconformities=[nonconformity(TENANT_B)])),
            project(payload(actions=[action(TENANT_B)])),
            project(payload(nonconformities=[nonconformity(tenant_ref=TENANT_B)])),
            project(payload(actions=[action(tenant_ref=TENANT_B)])),
            project(payload(actions=[action(nonconformity_ref="fixture:quality-nc/foreign")])),
            project(payload(nonconformities=[nonconformity(), nonconformity()])),
            project(payload(actions=[action(), action()])),
            project(payload(nonconformities=[nonconformity()] * 51)),
            project(payload(actions=[action()] * 51)),
            project(payload(nonconformities={"foreign": nonconformity()})),
        ):
            with self.subTest(foreign=foreign):
                self.assert_rejected(foreign)
                self.assertNotIn("company-", json.dumps(foreign))

    def test_rechaza_datos_reales(self) -> None:
        samples = (
            payload(email="person@example.test"),
            payload(nonconformities=[nonconformity(document_number="1234567890")]),
            payload(nonconformities=[nonconformity(cause_description="Real Person")]),
            payload(actions=[action(phone="3001234567")]),
            payload(actions=[action(owner_ref="ana@example.test")]),
            payload(actions=[action(owner_ref="3001234567")]),
            payload(actions=[action(owner_ref="fixture:responsible/ana@example.test")]),
            payload(nonconformities=[nonconformity(cause_ref="1234567890")]),
            payload(nonconformities=[nonconformity(finding_ref="https://example.test/secret")]),
            payload(actions=[action(action_ref="real-action-123")]),
            payload(actions=[action(kind="CONCLUIDA")]),
            payload(actions=[action(state=True)]),
            payload(nonconformities=[nonconformity(cause_ref=None)]),
            payload(actions=[action(owner_ref={"name": "Real Person"})]),
        )
        for sample in samples:
            with self.subTest(payload=sample):
                value = project(sample)
                self.assert_rejected(value)
                serialized = json.dumps(value)
                for forbidden in ("@example", "300123", "1234567890", "Real Person"):
                    self.assertNotIn(forbidden, serialized)
        source = CLASS.read_text(encoding="utf-8").lower()
        for forbidden in ("pdo(", "mysqli", "doctrine", "curl_", "httpclient",
                          "file_get_contents(", "shell_exec(", "exec(", "fetch(",
                          "http://", "https://"):
            self.assertNotIn(forbidden, source)

    def test_sin_cierre_automatico(self) -> None:
        first = project()
        self.assertEqual("PENDIENTE", first["nonconformities"][0]["state"])
        self.assertEqual("PENDIENTE", first["nonconformities"][0]["cause_state"])
        self.assertEqual("PENDIENTE", first["actions"][0]["state"])
        self.assertEqual("PENDIENTE", first["actions"][0]["verification_state"])
        self.assertFalse(first["actions"][0]["verified"])
        self.assertTrue(first["actions"][0]["follow_up_overdue"])
        for done in ("CERRADO", "APROBADO", "RESUELTO", "EFICAZ", "CUMPLE", None, False):
            with self.subTest(state=done):
                self.assert_rejected(project(payload(nonconformities=[nonconformity(state=done)])))
                self.assert_rejected(project(payload(nonconformities=[nonconformity(cause_state=done)])))
                self.assert_rejected(project(payload(actions=[action(state=done)])))
                self.assert_rejected(project(payload(actions=[action(verification_state=done)])))
        for changed in (
            payload(nonconformities=[nonconformity(cause_state="REQUIERE_REVISION")]),
            payload(nonconformities=[nonconformity(state="REQUIERE_REVISION")]),
            payload(actions=[action(state="REQUIERE_REVISION")]),
            payload(actions=[action(verification_state="REQUIERE_REVISION")]),
        ):
            with self.subTest(changed=changed):
                value = project(changed)
                self.assertTrue(value["structurally_reviewable"])
                self.assertEqual("REQUIERE_REVISION", value["state"])
                self.assertFalse(value["actions"][0]["verified"])
        self.assert_rejected(project(payload(actions=[action(approved=True)])))
        self.assertNotIn("CERRADO", json.dumps(first))
        self.assertNotIn("APROBADO", json.dumps(first))


if __name__ == "__main__":
    unittest.main()
