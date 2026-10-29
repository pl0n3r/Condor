#!/usr/bin/env python3
"""#1338: real PHP behavior for synthetic payroll and affiliation boundaries.

Proves three offline subcontrols, not regulated payroll/PILA workflows.
Other nine rows require missing functionality or independent external proof.
"""
from __future__ import annotations

import importlib.util
import json
from pathlib import Path
import shutil
import unittest

ROOT = Path(__file__).resolve().parents[1]
CI = ROOT / ".github/workflows/ci.yml"

A = {
    "GEST-M04-S01": "Create/view/list requires typed synthetic period_ref; calendar and real payroll close still missing",
    "GEST-M05-S01": "Real PHP register/view/list admits affiliation BORRADOR only; provider not contacted",
    "GEST-M05-S02": "Real PHP revise requires optimistic version; regulatory novelty still unverified",
}
OTHER = {
    "GEST-M04-S02": ("D", "Versioned payroll concept calculation not implemented"),
    "GEST-M04-S03": ("D", "Payroll incident accounting not implemented"),
    "GEST-M04-S04": ("D", "Time-to-payroll hour computation not implemented"),
    "GEST-M04-S05": ("D", "Jurisdiction-specific surcharge calculation not implemented"),
    "GEST-M04-S06": ("D", "Payroll deduction limits and calculation not implemented"),
    "GEST-M04-S07": ("D", "Payroll benefits settlement not implemented"),
    "GEST-M04-S08": ("D", "Statutory payroll receipt generation not implemented"),
    "GEST-M04-S09": ("B", "Actual DIAN electronic filing and legal authority required"),
    "GEST-M05": ("B", "Integral social-security compliance and PILA require independent legal/provider evidence"),
}
IDS = set(A) | set(OTHER)


def _real_php_fixture(module_name: str):
    """Import existing checked-in subprocess bridge to real production PHP."""
    if module_name not in ("test_payroll_http_api", "test_social_security_http_api"):
        raise AssertionError("unapproved fixture")
    path = ROOT / "tests" / (module_name + ".py")
    if not path.is_file() or shutil.which("php") is None:
        raise AssertionError("real PHP and checked-in test bridge required")
    spec = importlib.util.spec_from_file_location("offline_1338_" + module_name, path)
    if spec is None or spec.loader is None:
        raise AssertionError("cannot load product test bridge")
    module = importlib.util.module_from_spec(spec)
    spec.loader.exec_module(module)
    return module


class CoberturaCondor19Tests(unittest.TestCase):
    @classmethod
    def setUpClass(cls) -> None:
        cls.pay = _real_php_fixture("test_payroll_http_api")
        cls.soc = _real_php_fixture("test_social_security_http_api")

    def test_contrato_principal(self) -> None:
        """AC-01: PHP real for A subcontrols; explicit B/D disposition."""
        self.assertEqual(len(A), 3)
        self.assertEqual(len(OTHER), 9)
        self.assertEqual(set(A) | set(OTHER), IDS)
        for code, (kind, reason) in OTHER.items():
            with self.subTest(row=code):
                self.assertIn(kind, ("B", "C", "D"))
                self.assertGreater(len(reason), 30)

        p = self.pay
        records = p.call([
            p.op("create", input=p.fixture(period_ref="fixture:period/oct")),
            p.op("view", input={"ref": "fixture:payroll/sample"}),
            p.op("create", company="company-b", input=p.fixture(
                payroll_ref="fixture:payroll/second", period_ref="fixture:period/nov")),
            p.op("list"), p.op("list", company="company-b"),
        ])
        with self.subTest(code="GEST-M04-S01"):
            self.assertEqual("fixture:period/oct", records[0]["record"]["period_ref"])
            self.assertEqual(records[0]["record"], records[1]["record"])
            self.assertEqual([records[0]["record"]], records[3]["records"])
            self.assertEqual([records[2]["record"]], records[4]["records"])
            self.assertNotEqual(records[0]["record"]["company_ref"],
                                records[2]["record"]["company_ref"])
            self.assertEqual("BORRADOR", records[0]["record"]["state"])

        s = self.soc
        affiliations = s.run(
            s.act("register", data=s.data()),
            s.act("view", ref="fixture:affiliation/example"),
            s.act("revise", ref="fixture:affiliation/example",
                  data={"state": "EN_REVISION", "expected_version": 1}),
            s.act("view", ref="fixture:affiliation/example"),
            s.act("list"),
        )
        with self.subTest(code="GEST-M05-S01"):
            self.assertEqual("fixture:affiliation/example", affiliations[0]["affiliation_ref"])
            self.assertEqual("BORRADOR", affiliations[0]["state"])
            self.assertEqual(1, affiliations[0]["version"])
            self.assertEqual("fixture:plan/example", affiliations[1]["plan_ref"])
            self.assertEqual([affiliations[3]], affiliations[4])
        with self.subTest(code="GEST-M05-S02"):
            self.assertEqual("EN_REVISION", affiliations[2]["state"])
            self.assertEqual(2, affiliations[2]["version"])
            self.assertEqual(affiliations[2], affiliations[3])
            self.assertEqual(["register", "social_security.view", "revise",
                              "social_security.view", "social_security.list"],
                             affiliations[-1]["audits"])

    def test_falla_cerrado(self) -> None:
        """AC-02: real PHP rejects wrong tenant, permissions, PII and stale CAS."""
        p = self.pay
        bad = p.call([
            p.op("create", forbidden=True, input=p.fixture()),
            p.op("create", inactive=True, input=p.fixture()),
            p.op("create", foreign="other-tenant", input=p.fixture()),
            p.op("create", input=p.fixture(period_ref="2026-10")),
            p.op("create", input=p.fixture(employee_ref="person@example.test")),
            p.op("create", input=p.fixture(hours=40)),
            p.op("create", input=p.fixture(concept_version="v1")),
            p.op("hours"), p.op("deductions"),
        ])
        self.assertEqual([{"error": "denied"}] * 9, bad[:9])
        self.assertEqual([], bad[-1]["events"])
        cas = p.call([
            p.op("create", input=p.fixture()),
            p.op("state", input={"ref": "fixture:payroll/sample",
                                 "state": "EN_REVISION", "expected_version": 1}),
            p.op("state", input={"ref": "fixture:payroll/sample",
                                 "state": "PAGADO", "expected_version": 2}),
            p.op("state", input={"ref": "fixture:payroll/sample",
                                 "state": "BORRADOR", "expected_version": 1}),
        ])
        self.assertEqual("EN_REVISION", cas[1]["record"]["state"])
        self.assertEqual([{"error": "denied"}] * 2, cas[2:4])

        s = self.soc
        invalid = s.run(
            s.act("register", denied=True, data=s.data()),
            s.act("register", inactive=True, data=s.data()),
            s.act("register", owner="other-tenant", data=s.data()),
            s.act("register", data=s.data(employee_ref="person@example.test")),
            s.act("register", data=s.data(legal_status="approved")),
            s.act("register", data=s.data()),
            s.act("revise", ref="fixture:affiliation/example",
                  data={"state": "ENVIADO", "expected_version": 1}),
            s.act("revise", ref="fixture:affiliation/example",
                  data={"state": "EN_REVISION", "expected_version": 2}),
        )
        self.assertEqual([{"error": "denied"}] * 5, invalid[:5])
        self.assertEqual("BORRADOR", invalid[5]["state"])
        self.assertEqual([{"error": "denied"}] * 2, invalid[6:8])
        self.assertEqual(["register"], invalid[-1]["audits"])

    def test_sin_datos_reales(self) -> None:
        """AC-03: in-memory fakes, no external services, legal gates intact."""
        data = json.loads((ROOT / "datos.yml").read_text(encoding="utf-8"))
        self.assertEqual("construccion", data["phase"])
        self.assertTrue(data["d063_attestation"]["no_real_customer_data"])
        for file in ("src/Application/Payroll/PayrollApiService.php",
                     "src/Application/SocialSecurity/SocialSecurityApiService.php"):
            source = (ROOT / file).read_text(encoding="utf-8")
            self.assertIn("fixture:", source)
            self.assertNotIn("curl_", source)
        self.assertIn("class AuditedMemory", self.pay.HARNESS)
        self.assertIn("class FakeLedger", self.soc.RUNTIME)
        self.assertEqual(set(A) & set(OTHER), set())

    def test_ci_gate_contract(self) -> None:
        """AC-04: check CI wiring; real terminal GitHub gate remains separate."""
        workflow = CI.read_text(encoding="utf-8")
        self.assertIn("name: Validar", workflow)
        self.assertIn("name: Pruebas de contrato e integración", workflow)
        self.assertIn("python3 -m unittest discover", workflow)


if __name__ == "__main__":
    unittest.main()
