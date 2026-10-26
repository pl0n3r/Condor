#!/usr/bin/env python3
"""F3a SG-SST synthetic fixture precheck; calls real PHP without network/data."""
from __future__ import annotations

import json
import subprocess
import unittest
from pathlib import Path

ROOT = Path(__file__).resolve().parents[1]
CLASS = ROOT / "src/Application/Compliance/OccupationalSafetyPrecheckProjection.php"
TENANT = "fixture:tenant/tenant-a"
ENTITY = "fixture:legal-entity/entity-a"
DATE = "2026-10-24"


def profile(**changes: object) -> dict:
    fixture = {
        "tenant_ref": TENANT,
        "legal_entity_ref": ENTITY,
        "fixture_ref": "fixture:sgsst-profile/example-1",
        "workers_min": 10,
        "workers_max": 20,
        "risk_category": "III",
        "reference_date": DATE,
    }
    fixture.update(changes)
    return fixture


def project(
    item: dict | None = None,
    tenant: str = TENANT,
    entity: str = ENTITY,
    date: str = DATE,
) -> dict:
    php = r"""
require $argv[1];
use App\Application\Compliance\OccupationalSafetyPrecheckProjection;
$data = json_decode($argv[2], true, 32, JSON_THROW_ON_ERROR);
echo json_encode(OccupationalSafetyPrecheckProjection::project(
    $data['profile'], $data['tenant'], $data['entity'], $data['date']
), JSON_THROW_ON_ERROR);
"""
    payload = {
        "profile": profile() if item is None else item,
        "tenant": tenant, "entity": entity, "date": date,
    }
    done = subprocess.run(
        ["php", "-n", "-d", "allow_url_fopen=0", "-r", php, str(CLASS), json.dumps(payload)],
        cwd=ROOT, text=True, capture_output=True, check=True, timeout=20,
    )
    return json.loads(done.stdout)


class OccupationalSafetyPrecheckTests(unittest.TestCase):
    def assert_review(self, result: dict, reviewable: bool = False) -> None:
        self.assertEqual("EN_REVISION" if reviewable else "REQUIERE_REVISION", result["state"])
        self.assertTrue(result["requires_professional_review"])
        self.assertTrue(result["synthetic_only"])
        self.assertEqual(reviewable, result["structurally_reviewable"])
        self.assertNotIn("CUMPLE", json.dumps(result))
        self.assertNotIn("NO_APLICA", json.dumps(result))

    def test_unknown_risk_or_workforce_never_implies_compliance(self) -> None:
        self.assert_review(project(), reviewable=True)
        for risk in (None, "", "UNKNOWN", "VI", "iii", [], 3, "PENDIENTE"):
            with self.subTest(risk=risk):
                self.assert_review(project(profile(risk_category=risk)))
        for values in ({"workers_min": None}, {"workers_max": "20"},
                       {"workers_min": -1}, {"workers_max": -1},
                       {"workers_min": 30, "workers_max": 20},
                       {"workers_max": 1000001}, {"workers_min": True}):
            with self.subTest(workers=values):
                self.assert_review(project(profile(**values)))
        invalid = profile()
        invalid.pop("risk_category")
        self.assert_review(project(invalid))

    def test_cross_tenant_or_legal_entity_is_rejected(self) -> None:
        for item, tenant, entity in (
            (profile(tenant_ref="fixture:tenant/tenant-b"), TENANT, ENTITY),
            (profile(legal_entity_ref="fixture:legal-entity/entity-b"), TENANT, ENTITY),
            (profile(), "fixture:tenant/tenant-b", ENTITY),
            (profile(), TENANT, "fixture:legal-entity/entity-b"),
            (profile(tenant_ref="unknown"), TENANT, ENTITY),
            (profile(legal_entity_ref=""), TENANT, ENTITY),
            (profile(), "tenant-a", ENTITY),
        ):
            with self.subTest(item=item, tenant=tenant, entity=entity):
                result = project(item, tenant, entity)
                self.assert_review(result)
                self.assertNotIn("entity-b", json.dumps(result))
                self.assertNotIn("tenant-b", json.dumps(result))
        extra = profile(employee_name="fictional")
        self.assert_review(project(extra))

    def test_invalid_reference_date_requires_professional_review(self) -> None:
        for invalid in ("2026-02-30", "2026-10-32", "26-10-2026", "2026-1-1", "", "unknown"):
            with self.subTest(date=invalid):
                self.assert_review(project(profile(reference_date=invalid), date=invalid))
        self.assert_review(project(profile(), date="2026-10-25"))
        self.assert_review(project(profile(reference_date=None)))

    def test_pure_synthetic_no_pii_network_or_legal_certification(self) -> None:
        code = CLASS.read_text(encoding="utf-8").lower()
        for prohibited in ("curl_", "file_get_contents(", "pdo(", "doctrine", "httpclient",
                           "shell_exec(", "exec(", "entitymanager", "http://", "https://"):
            self.assertNotIn(prohibited, code)
        first = project()
        self.assert_review(first, reviewable=True)
        self.assertEqual(first, project())
        self.assertEqual({"state", "requires_professional_review", "synthetic_only", "structurally_reviewable"}, set(first))
        self.assertNotIn(TENANT, json.dumps(first))
        self.assertNotIn(ENTITY, json.dumps(first))
        self.assert_review(project(profile(fixture_ref="customer-123")))
        self.assert_review(project(profile(unknown_field="x")))


if __name__ == "__main__":
    unittest.main()
