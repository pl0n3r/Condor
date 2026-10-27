#!/usr/bin/env python3
"""M03 offline acceptance executes real PHP on synthetic activities only."""
from __future__ import annotations

import copy
import json
import subprocess
import unittest
from pathlib import Path

ROOT = Path(__file__).resolve().parents[1]
CLASS = ROOT / "src/Application/Compliance/OccupationalPpeTraining.php"
TENANT = "fixture:tenant/tenant-a"
COMPANY = "fixture:company/company-a"


def activity(kind: str = "EPP_ENTREGA", suffix: str = "ppe-1", **changes: object) -> dict:
    item = {
        "activity_ref": f"fixture:activity/{suffix}",
        "tenant_ref": TENANT,
        "company_ref": COMPANY,
        "kind": kind,
        "occurred_on": "2026-10-01",
        "due_on": "2027-07-01",
        "evidence_ref": "fixture:evidence/doc-1",
        "attendee_refs": ["fixture:participant/person-fictitious-1"] if kind == "CAPACITACION" else [],
        "review_state": "PENDIENTE",
    }
    item.update(changes)
    return item


def payload(tenant: str = TENANT, company: str = COMPANY) -> dict:
    return {
        "tenant_ref": tenant,
        "company_ref": company,
        "reference_date": "2026-10-27",
        "activities": [activity(tenant_ref=tenant, company_ref=company)],
    }


def project(data: dict, rejected: bool = False) -> dict:
    script = r"""
require $argv[1];
use App\Application\Compliance\OccupationalPpeTraining;
try {
    $data = json_decode($argv[2], true, 32, JSON_THROW_ON_ERROR);
    echo json_encode(OccupationalPpeTraining::project($data), JSON_THROW_ON_ERROR);
} catch (\InvalidArgumentException $error) {
    fwrite(STDERR, 'REJECTED');
    exit(4);
}
"""
    result = subprocess.run(
        ["php", "-n", "-d", "allow_url_fopen=0", "-r", script,
         str(CLASS), json.dumps(data)],
        cwd=ROOT, text=True, capture_output=True, timeout=20, check=False,
    )
    if rejected:
        if result.returncode != 4 or result.stderr != "REJECTED":
            raise AssertionError(f"Expected rejection, got {result.returncode}: {result.stderr[:400]}")
        return {}
    if result.returncode:
        raise AssertionError(f"PHP failed with code {result.returncode}: {result.stderr[:500]}")
    return json.loads(result.stdout)


class OccupationalPpeTrainingTests(unittest.TestCase):
    def test_resultado_determinista(self) -> None:
        data = payload()
        data["activities"] = [
            activity("EPP_ENTREGA", "delivery"),
            activity("EPP_REPOSICION", "replacement", due_on="2026-11-02"),
            activity("CAPACITACION", "training"),
            activity("SIMULACRO_EMERGENCIA", "drill", due_on="2026-10-15"),
            activity("COMITE_VIGIA", "committee", occurred_on=None, evidence_ref=None),
        ]
        result = project(data)
        self.assertEqual(result, project(copy.deepcopy(data)))
        self.assertEqual(5, len(result["rows"]))
        self.assertEqual(
            ["PENDIENTE", "POR_VENCER", "PENDIENTE", "VENCIDA", "SIN_EVIDENCIA"],
            [r["state"] for r in result["rows"]],
        )
        self.assertEqual(5, len(result["pending_evaluations"]))
        self.assertTrue(result["synthetic_only"])

    def test_aislamiento_por_empresa(self) -> None:
        foreign_company = "fixture:company/company-b"
        foreign_tenant = "fixture:tenant/tenant-b"
        data = payload()
        data["activities"].extend([
            activity("EPP_ENTREGA", "hidden-company", company_ref=foreign_company,
                     evidence_ref="fixture:evidence/secret-company"),
            activity("EPP_ENTREGA", "hidden-tenant", tenant_ref=foreign_tenant,
                     evidence_ref="fixture:evidence/secret-tenant"),
        ])
        result = project(data)
        self.assertEqual(1, len(result["rows"]))
        self.assertNotIn("secret", json.dumps(result))
        self.assertNotIn("hidden", json.dumps(result))
        other = project(payload(foreign_tenant, foreign_company))
        self.assertEqual(foreign_tenant, other["tenant_ref"])
        self.assertEqual(foreign_company, other["company_ref"])
        self.assertNotEqual(other["company_ref"], result["company_ref"])
        data["activities"][0]["company_ref"] = foreign_company
        self.assertEqual([], project(data)["rows"])

    def test_rechaza_datos_reales(self) -> None:
        invalid_top = (
            ("company_ref", "900123456-1"),
            ("tenant_ref", "123456789"),
            ("email", "cliente@example.com"),
        )
        for key, value in invalid_top:
            with self.subTest(top=key):
                bad = payload()
                bad[key] = value
                project(bad, rejected=True)
        invalid_activity = (
            ("activity_ref", "123456789"),
            ("evidence_ref", "persona@example.com"),
            ("phone", "+573001234567"),
            ("tenant_ref", "unknown"),
            ("due_on", "2026-02-30"),
            ("attendee_refs", ["3001234567"]),
            ("attendee_refs", ["fixture:participant/person-a", "fixture:participant/person-a"]),
        )
        for key, value in invalid_activity:
            with self.subTest(item=key, value=value):
                bad = payload()
                bad["activities"][0][key] = value
                project(bad, rejected=True)
        bad = payload()
        bad["activities"] = [activity()] * 101
        project(bad, rejected=True)

    def test_sin_cierre_automatico(self) -> None:
        data = payload()
        data["activities"] = [
            activity("EPP_ENTREGA", "equip"),
            activity("CAPACITACION", "training", attendee_refs=[]),
            activity("SIMULACRO_EMERGENCIA", "emergency", review_state="EN_REVISION"),
            activity("COMITE_VIGIA", "committee"),
        ]
        result = project(data)
        self.assertIs(result["automatic_approval"], False)
        self.assertIs(result["automatic_closure"], False)
        self.assertEqual("SIN_EVIDENCIA", result["rows"][1]["state"])
        for row in result["rows"]:
            self.assertTrue(row["requires_professional_review"])
            self.assertFalse(row["automatically_approved"])
            self.assertFalse(row["automatically_closed"])
            self.assertNotIn(row["state"], ("CUMPLE", "APROBADO", "COMPLETADO"))
        for bad_state in ("APROBADA", "COMPLETADO", "CUMPLE"):
            with self.subTest(bad_state=bad_state):
                bad = payload()
                bad["activities"][0]["review_state"] = bad_state
                project(bad, rejected=True)
        code = CLASS.read_text(encoding="utf-8").lower()
        for banned in ("pdo(", "doctrine", "curl_", "file_get_contents(",
                       "shell_exec(", "httpclient", "entitymanager", "->flush(", "->persist("):
            self.assertNotIn(banned, code)
        data = payload()
        data["activities"] = []
        self.assertEqual([], project(data)["pending_evaluations"])


if __name__ == "__main__":
    unittest.main()
