#!/usr/bin/env python3
"""M06 offline evidence acceptance: execute the actual PHP implementation.

No provider calls, database, PII or synthetic approvals issued by the test.
"""
from __future__ import annotations

import copy
import json
import subprocess
import unittest
from pathlib import Path

ROOT = Path(__file__).resolve().parents[1]
CLASS = ROOT / "src/Application/Compliance/ComplianceEvidenceEvaluation.php"
TENANT_A = "fixture:tenant/tenant-a"
COMPANY_A = "fixture:company/company-a"


def obligation(tenant: str = TENANT_A, company: str = COMPANY_A, suffix: str = "policy-1") -> dict:
    return {
        "tenant_ref": tenant,
        "company_ref": company,
        "obligation_ref": f"fixture:obligation/{suffix}",
        "source_ref": "fixture:source/norma-fictional-1",
        "effective_from": "2026-01-01", "effective_until": "2027-12-31",
    }


def evidence(tenant: str = TENANT_A, company: str = COMPANY_A, **patch: object) -> dict:
    item = {
        "tenant_ref": tenant, "company_ref": company,
        "obligation_ref": "fixture:obligation/policy-1",
        "evidence_ref": "fixture:evidence/certificate-fictional-1",
        "issued_on": "2026-02-01", "valid_until": "2027-06-30",
        "review_state": "APROBADA", "reviewer_ref": "fixture:reviewer/human-fictional-1",
        "reviewed_on": "2026-02-05",
    }
    item.update(patch)
    return item


def payload(tenant: str = TENANT_A, company: str = COMPANY_A) -> dict:
    return {
        "tenant_ref": tenant, "company_ref": company,
        "reference_date": "2026-10-27",
        "obligations": [obligation(tenant, company)],
        "evidence": [evidence(tenant, company)],
    }


def project(data: dict, *, fails: bool = False) -> dict:
    php = r"""
require $argv[1];
use App\Application\Compliance\ComplianceEvidenceEvaluation;
try {
    $input = json_decode($argv[2], true, 32, JSON_THROW_ON_ERROR);
    echo json_encode(ComplianceEvidenceEvaluation::project($input), JSON_THROW_ON_ERROR);
} catch (\InvalidArgumentException $error) {
    fwrite(STDERR, 'REJECTED');
    exit(4);
}
"""
    result = subprocess.run(
        ["php", "-n", "-d", "allow_url_fopen=0", "-r", php,
         str(CLASS), json.dumps(data)],
        cwd=ROOT, text=True, capture_output=True, timeout=20, check=False,
    )
    if fails:
        if result.returncode != 4 or result.stderr != "REJECTED":
            raise AssertionError(f"Expected safe rejection, got {result.returncode}")
        return {}
    if result.returncode != 0:
        raise AssertionError(f"PHP projection failed: {result.stderr[:700]}")
    return json.loads(result.stdout)


class ComplianceEvidenceEvaluationTests(unittest.TestCase):
    def test_resultado_determinista(self) -> None:
        base = payload()
        a = project(base)
        self.assertEqual(a, project(copy.deepcopy(base)))
        self.assertEqual("CUMPLE", a["rows"][0]["state"])
        self.assertEqual([], a["pending_evaluations"])
        self.assertEqual(COMPANY_A, a["company_ref"])
        self.assertTrue(a["synthetic_only"])
        for expiration, expected in (
            ("2026-11-01", "POR_VENCER"),
            ("2026-10-26", "VENCIDA"),
        ):
            with self.subTest(expiration=expiration):
                data = payload()
                data["evidence"][0]["valid_until"] = expiration
                result = project(data)
                self.assertEqual(expected, result["rows"][0]["state"])
                self.assertEqual(1, len(result["pending_evaluations"]))
        data = payload()
        data["evidence"] = []
        self.assertEqual("SIN_EVIDENCIA", project(data)["rows"][0]["state"])

    def test_aislamiento_por_empresa(self) -> None:
        other_company = "fixture:company/company-b"
        other_tenant = "fixture:tenant/tenant-b"
        data = payload()
        data["obligations"].append(obligation(TENANT_A, other_company, "foreign-secret"))
        data["evidence"].append(evidence(TENANT_A, other_company,
            obligation_ref="fixture:obligation/foreign-secret",
            evidence_ref="fixture:evidence/secret-other-company"))
        data["evidence"].append(evidence(other_tenant, COMPANY_A,
            evidence_ref="fixture:evidence/secret-other-tenant"))
        result = project(data)
        self.assertEqual(1, len(result["rows"]))
        self.assertEqual("CUMPLE", result["rows"][0]["state"])
        self.assertNotIn("secret", json.dumps(result))
        other = project(payload(other_tenant, other_company))
        self.assertEqual(other_tenant, other["tenant_ref"])
        self.assertEqual(other_company, other["company_ref"])
        self.assertNotEqual(other["company_ref"], result["company_ref"])
        data["evidence"][0]["company_ref"] = other_company
        self.assertEqual("SIN_EVIDENCIA", project(data)["rows"][0]["state"])

    def test_rechaza_datos_reales(self) -> None:
        changes = (
            ("company_ref", "900123456-1"),
            ("tenant_ref", "123456789"),
            ("contact_email", "persona@example.com"),
        )
        for key, value in changes:
            with self.subTest(field=key):
                data = payload()
                data[key] = value
                project(data, fails=True)
        for field, value in (
            ("reviewer_ref", "persona@example.com"),
            ("evidence_ref", "+573001234567"),
            ("evidence_ref", "1023456789"),
            ("phone", "3001234567"),
            ("issued_on", "2026-02-30"),
        ):
            with self.subTest(field=field):
                data = payload()
                data["evidence"][0][field] = value
                project(data, fails=True)
        data = payload()
        data["obligations"][0]["documento_identidad"] = "123456789"
        project(data, fails=True)
        for bad_scope in (None, "unknown", "fixture:tenant/tenant-a@customer.com"):
            data = payload()
            data["evidence"][0]["tenant_ref"] = bad_scope
            project(data, fails=True)

    def test_sin_cierre_automatico(self) -> None:
        good = project(payload())
        self.assertIs(good["automatic_approval"], False)
        self.assertIs(good["automatic_closure"], False)
        self.assertIs(good["rows"][0]["automatically_closed"], False)
        self.assertIs(good["rows"][0]["automatically_approved"], False)
        self.assertTrue(good["rows"][0]["requires_professional_review"])
        for review_state in ("PENDIENTE", "RECHAZADA"):
            data = payload()
            data["evidence"][0].update(review_state=review_state,
                reviewer_ref=None, reviewed_on=None)
            projected = project(data)
            self.assertEqual("SIN_EVIDENCIA", projected["rows"][0]["state"])
            self.assertEqual("REVISION_HUMANA_PENDIENTE",
                             projected["pending_evaluations"][0]["reason"])
        for invalid in (
            {"review_state": "APROBADA", "reviewer_ref": None},
            {"review_state": "APROBADA", "reviewed_on": "2027-01-01"},
            {"review_state": "CUMPLE"},
        ):
            data = payload()
            data["evidence"][0].update(invalid)
            project(data, fails=True)
        code = CLASS.read_text(encoding="utf-8").lower()
        for forbidden in ("pdo(", "doctrine", "curl_", "file_get_contents(",
                          "shell_exec(", "exec(", "httpclient", "entitymanager",
                          "->flush(", "->persist("):
            self.assertNotIn(forbidden, code)
        self.assertEqual([], project(payload() | {"obligations": [], "evidence": []})["rows"])


if __name__ == "__main__":
    unittest.main()
