#!/usr/bin/env python3
"""M16 synthetic SLA contract: invoke real offline PHP without mocks or skips."""
from __future__ import annotations

import copy
import json
import subprocess
import unittest
from pathlib import Path

ROOT = Path(__file__).resolve().parents[1]
CLASS = ROOT / "src/Application/CustomerService/CustomerCaseSla.php"
TENANT = "fixture:tenant/tenant-a"
OPENED = "2026-10-26T15:00:00Z"  # Monday 10:00 Bogota
NOW = "2026-10-26T19:00:00Z"     # Monday 14:00 Bogota


def sample_case(**patch: object) -> dict:
    result = {
        "case_ref": "fixture:pqrs-case/case-1", "tenant_ref": TENANT,
        "type": "PQRS", "priority": "ALTA", "opened_at": OPENED,
        "status": "PENDIENTE",
    }
    result.update(patch)
    return result


def batch(**patch: object) -> dict:
    result = {
        "fixture_ref": "fixture:pqrs-batch/sample-1", "tenant_ref": TENANT,
        "sla_hours": {"ALTA": 4, "MEDIA": 8, "BAJA": 16},
        "cases": [sample_case()],
    }
    result.update(patch)
    return result


def project(payload: dict | None = None, tenant: str = TENANT, now: str = NOW) -> dict:
    script = r"""
require $argv[1];
use App\Application\CustomerService\CustomerCaseSla;
$data = json_decode($argv[2], true, 32, JSON_THROW_ON_ERROR);
echo json_encode(CustomerCaseSla::project($data['batch'], $data['tenant'], $data['now']), JSON_THROW_ON_ERROR);
"""
    request = {"batch": batch() if payload is None else payload,
               "tenant": tenant, "now": now}
    completed = subprocess.run(
        ["php", "-n", "-d", "allow_url_fopen=0", "-r", script,
         str(CLASS), json.dumps(request)], cwd=ROOT,
        capture_output=True, text=True, check=True, timeout=20,
    )
    return json.loads(completed.stdout)


class CustomerCaseSlaTests(unittest.TestCase):
    def safe(self, value: dict, state: str, count: int, reviewable: bool) -> None:
        self.assertEqual(value["state"], state)
        self.assertIs(value["synthetic_only"], True)
        self.assertIs(value["requires_professional_review"], True)
        self.assertIs(value["structurally_reviewable"], reviewable)
        self.assertEqual(value["case_count"], count)
        self.assertEqual(len(value["cases"]), count)
        self.assertNotIn("CERRADO", json.dumps(value))
        self.assertNotIn("APROBADO", json.dumps(value))

    def test_resultado_determinista(self) -> None:
        first = project()
        self.assertEqual(first, project())
        self.safe(first, "PENDIENTE", 1, True)
        self.assertEqual(first["cases"][0]["sla_due_at"], "2026-10-26T19:00:00Z")
        self.assertEqual(first["cases"][0]["alert"], "EN_PLAZO")
        self.assertEqual("VENCIDO", project(now="2026-10-26T19:01:00Z")["cases"][0]["alert"])
        # Friday 16:00 Bogota + 2 business hours = Monday 10:00 Bogota.
        friday = batch(sla_hours={"ALTA": 2, "MEDIA": 8, "BAJA": 16},
                       cases=[sample_case(opened_at="2026-10-23T21:00:00Z")])
        self.assertEqual("2026-10-26T15:00:00Z", project(friday)["cases"][0]["sla_due_at"])
        self.assertEqual("VENCIDO", project(friday)["cases"][0]["alert"])

    def test_aislamiento_por_empresa(self) -> None:
        other = "fixture:tenant/tenant-b"
        foreign = sample_case(tenant_ref=other, case_ref="fixture:pqrs-case/private-b")
        for bad in (
            batch(tenant_ref=other),
            batch(cases=[foreign]),
            batch(cases=[sample_case(), foreign]),
        ):
            with self.subTest(payload=bad):
                rejected = project(bad)
                self.safe(rejected, "REQUIERE_REVISION", 0, False)
                self.assertNotIn("private-b", json.dumps(rejected))
                self.assertNotIn("tenant-b", json.dumps(rejected))
        self.safe(project(tenant=other), "REQUIERE_REVISION", 0, False)
        isolated = batch(tenant_ref=other, cases=[sample_case(tenant_ref=other)])
        self.safe(project(isolated, tenant=other), "PENDIENTE", 1, True)
        self.safe(project(isolated, tenant=TENANT), "REQUIERE_REVISION", 0, False)

    def test_rechaza_datos_reales(self) -> None:
        for candidate in (
            batch(email="alice@example.test"),
            batch(cases=[sample_case(email="alice@example.test")]),
            batch(cases=[sample_case(phone="3001234567")]),
            batch(cases=[sample_case(document_number="123456789")]),
            batch(cases=[sample_case(case_ref="someone@example.test")]),
            batch(cases=[sample_case(case_ref="fixture:pqrs-case/1000000@evil")]),
            batch(cases=[sample_case(tenant_ref="123456789")]),
            batch(cases={"x": sample_case()}),
            batch(cases=[sample_case(), sample_case()]),
            batch(cases=[sample_case(status=True)]),
            batch(sla_hours={"ALTA": True, "MEDIA": 8, "BAJA": 16}),
            batch(sla_hours={"ALTA": "4", "MEDIA": 8, "BAJA": 16}),
        ):
            with self.subTest(case=candidate):
                res = project(candidate)
                self.safe(res, "REQUIERE_REVISION", 0, False)
                for secret in ("alice", "example.test", "3001234567", "123456789"):
                    self.assertNotIn(secret, json.dumps(res))
        source = CLASS.read_text(encoding="utf-8").lower()
        for forbidden in ("pdo(", "doctrine", "curl_", "file_get_contents(",
                          "shell_exec(", "exec(", "httpclient", "mysqli"):
            self.assertNotIn(forbidden, source)

    def test_sin_cierre_automatico(self) -> None:
        for status in ("CERRADO", "APROBADO", "RESUELTO", "CUMPLE", None):
            with self.subTest(status=status):
                self.safe(project(batch(cases=[sample_case(status=status)])),
                          "REQUIERE_REVISION", 0, False)
        overdue = project(now="2026-10-26T21:00:00Z")
        self.safe(overdue, "REQUIERE_REVISION", 1, True)
        self.assertEqual("REQUIERE_REVISION", overdue["cases"][0]["state"])
        self.assertEqual("VENCIDO", overdue["cases"][0]["alert"])
        self.safe(project(batch(cases=[sample_case(status="REQUIERE_REVISION")])),
                  "REQUIERE_REVISION", 1, True)
        for bad_time in ("2026-02-30T12:00:00Z", "bad", "2026-10-27T15:00:00+00:00"):
            self.safe(project(now=bad_time), "REQUIERE_REVISION", 0, False)
        self.safe(project(batch(cases=[sample_case(opened_at="2026-10-27T15:00:00Z")])),
                  "REQUIERE_REVISION", 0, False)


if __name__ == "__main__":
    unittest.main()
