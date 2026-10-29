#!/usr/bin/env python3
"""Condor #1339: executable partial M05-M08 coverage with synthetic fixtures.

These checks execute existing PHP projections. No legal, financial, electronic
signature or production compliance is certified by local synthetic outputs.
"""
from __future__ import annotations

import ast
import copy
import json
from pathlib import Path
import sys
import unittest

ROOT = Path(__file__).resolve().parents[1]
TESTS = ROOT / "tests"
if str(TESTS) not in sys.path:
    sys.path.insert(0, str(TESTS))

import test_m05_afiliaciones as contributions
import test_m05_pila as pila
import test_compliance_normative_applicability as applicability
import test_compliance_normative_matrix as matrix
import test_compliance_normative_review_projection as review
import test_compliance_attention_projection as calendar
import test_compliance_audit_candidate_projection as audit
import test_m07_bancos as finance
import test_m08_retencion as documents

MATRIX = ROOT / "docs/matriz-cobertura.md"
ROWS = {
    "GEST-M05-S03": "Seguridad social / aportes",
    "GEST-M05-S04": "Seguridad social / soportes",
    "GEST-M05-S05": "Seguridad social / alertas",
    "GEST-M05-S06": "Seguridad social / preparación para PILA",
    "GEST-M06-S01": "Cumplimiento legal / perfil legal",
    "GEST-M06-S02": "Cumplimiento legal / catálogo normativo",
    "GEST-M06-S03": "Cumplimiento legal / matriz de obligaciones/prohibiciones",
    "GEST-M06-S04": "Cumplimiento legal / calendario",
    "GEST-M06-S06": "Cumplimiento legal / evaluaciones",
    "GEST-M06-S07": "Cumplimiento legal / auditorías",
    "GEST-M07-S06": "Finanzas y contabilidad / cierres",
    "GEST-M08-S05": "Gestión documental / firma/evidencia",
}


def matrix_rows(source: object) -> dict[str, tuple[str, ...]]:
    """Reject unknown/missing/duplicated/promoted requirements, not claim release."""
    if not isinstance(source, str) or not source.strip():
        raise ValueError("UNKNOWN: missing matrix")
    found: dict[str, tuple[str, ...]] = {}
    for line in source.splitlines():
        if not line.startswith("| `GEST-M0"):
            continue
        columns = tuple(value.strip() for value in line.strip("|").split("|"))
        if not columns or "` · " not in columns[0]:
            continue
        key = columns[0].split("` · ", 1)[0].strip("`")
        if key not in ROWS:
            continue
        if key in found:
            raise ValueError("UNKNOWN: duplicate row " + key)
        expected = "`" + key + "` · `docs/gestion-empresarial-integral.md`"
        if (len(columns) != 5 or columns[0] != expected
                or columns[1] != ROWS[key]
                or columns[2] != "`NO_VERIFICABLE`"
                or not columns[3].strip() or columns[4] != "#802"):
            raise ValueError("UNKNOWN: altered row " + key)
        found[key] = columns
    if set(found) != set(ROWS):
        raise ValueError("UNKNOWN: incomplete 12-row matrix")
    return found


class CoberturaCondor20Tests(unittest.TestCase):
    def test_contrato_principal(self) -> None:
        """Test genuine PHP behavior for each of twelve offline *partial* slices."""
        all_rows = matrix_rows(MATRIX.read_text(encoding="utf-8"))
        self.assertEqual(set(ROWS), set(all_rows))
        for key in ROWS:
            with self.subTest(row=key):
                self.assertEqual(ROWS[key], all_rows[key][1])

        # M05 S03: simulated contributions calculate integer units, not tax liability.
        amounts = contributions.run_php(contributions.fixture())
        self.assertEqual(400, amounts["rows"][0]["health_units"])
        self.assertEqual(1600, amounts["rows"][0]["pension_units"])
        self.assertEqual("EN_REVISION", amounts["rows"][0]["state"])
        self.assertFalse(amounts["legal_value"])
        # M05 S04: evidence support is counted, not uploaded to a provider.
        preview = pila.run_php(pila.fixture())
        self.assertEqual(1, preview["pila_example"]["support_count"])
        # M05 S05: overdue alert is only an attention candidate.
        self.assertTrue(preview["alerts"][0]["attention_candidate"])
        self.assertEqual("EN_REVISION", preview["alerts"][0]["state"])
        # M05 S06: PILA cannot be submitted, even with well-formed support.
        self.assertEqual("BORRADOR_NO_ENVIABLE", preview["pila_example"]["state"])
        self.assertFalse(preview["can_send"])
        self.assertFalse(preview["legal_value"])

        # M06 S01: scoped legal profile is evaluated, not silently approved.
        legal = applicability.evaluate(applicability.rule(), applicability.profile())
        self.assertEqual("OBLIGATORIO", legal["kind"])
        self.assertEqual("APLICA", legal["applicability"])
        # M06 S02: synthetic normative catalog provenance survives verification.
        self.assertEqual("authority-synthetic", legal["source"]["issuer"])
        self.assertEqual("v1", legal["source"]["version"])
        # M06 S03: the real matrix preserves obligations/prohibitions by scope.
        obligations = matrix.matrix([matrix.rule()], matrix.profile())
        self.assertEqual("OBLIGATORIO", obligations["rows"][0]["kind"])
        prohibitions = matrix.matrix([matrix.rule(kind="PROHIBIDO")], matrix.profile())
        self.assertEqual("PROHIBIDO", prohibitions["rows"][0]["kind"])
        # M06 S04: deadline alert depends on legal provenance and date.
        notice = calendar.project()["rows"][0]
        self.assertTrue(notice["attention_candidate"])
        self.assertEqual("fixture:deadline/notice-1", notice["deadline_ref"])
        # M06 S06: review with a synthetic evidence reference is *in review*.
        evaluation = review.project(reviews=[review.review()])["rows"][0]
        self.assertEqual("EN_REVISION", evaluation["state"])
        self.assertEqual("fixture:evidence/document-1", evaluation["evidence_ref"])
        # M06 S07: audit and corrective action are candidates, not closure.
        candidate = audit.project()["rows"][0]
        self.assertTrue(candidate["corrective_action_candidate"])
        self.assertEqual("EN_REVISION", candidate["state"])
        self.assertEqual("fixture:action/remediation-1", candidate["action_ref"])
        # M07 S06: accounting period remains open after synthetic reconciliation.
        closing = finance.project()
        self.assertEqual("12.30", closing["budgets"][0]["actual_decimal"])
        self.assertEqual("-7.70", closing["budgets"][0]["variance_decimal"])
        self.assertTrue(closing["periods"][0]["review_candidate"])
        self.assertFalse(closing["periods"][0]["can_close"])
        self.assertFalse(closing["can_close"])
        # M08 S05: author, reviewer, approver are different synthetic actors.
        dossier = documents.fixture()
        dossier["records"][0]["events"] = [
            documents.event("ACCESO", "reader", "lector", "2026-10-24"),
            documents.event("REVISAR", "reviewer", "revisor", "2026-10-25"),
            documents.event("APROBAR", "approver", "aprobador", "2026-10-26"),
        ]
        signed = documents.project(dossier)
        self.assertTrue(signed["valid"])
        self.assertEqual("APROBADO_EXPLICITO", signed["records"][0]["state"])
        self.assertEqual(3, len(signed["records"][0]["history"]))
        self.assertFalse(signed["automatic_approval"])
        self.assertFalse(signed["can_close"])

    def test_falla_cerrado(self) -> None:
        """Regression: missing rows, forged approvals and foreign tenants fail closed."""
        source = MATRIX.read_text(encoding="utf-8")
        self.assertEqual(12, len(matrix_rows(source)))
        for key in ROWS:
            line = next(value for value in source.splitlines()
                        if value.startswith("| `" + key + "` · "))
            for altered in (source.replace(line, "", 1), source + "\n" + line,
                            source.replace(line, line.replace("NO_VERIFICABLE", "CUBIERTO", 1), 1)):
                with self.subTest(row=key):
                    with self.assertRaisesRegex(ValueError, "UNKNOWN"):
                        matrix_rows(altered)
        with self.assertRaisesRegex(ValueError, "UNKNOWN"):
            matrix_rows(None)

        # M05 contributions and PILA do not reveal a foreign tenant's entries.
        wrong_contrib = contributions.fixture()
        wrong_contrib["affiliations"] += contributions.fixture("fixture:tenant/b")["affiliations"]
        wrong_contrib["novelties"] += contributions.fixture("fixture:tenant/b")["novelties"]
        scoped = contributions.run_php(wrong_contrib)
        self.assertEqual(1, len(scoped["rows"]))
        self.assertNotIn("tenant/b", json.dumps(scoped))
        wrong_pila = pila.fixture()
        wrong_pila["supports"] += pila.fixture("fixture:tenant/b")["supports"]
        wrong_pila["alerts"] += pila.fixture("fixture:tenant/b")["alerts"]
        screened = pila.run_php(wrong_pila)
        self.assertEqual(1, len(screened["supports"]))
        self.assertEqual(1, len(screened["alerts"]))
        self.assertNotIn("tenant/b", json.dumps(screened))
        rejected_pila = pila.fixture()
        rejected_pila["alerts"][0]["due_on"] = "2026-02-30"
        self.assertIsNone(pila.run_php(rejected_pila, valid=False))
        rejected_amount = contributions.fixture()
        rejected_amount["affiliations"][0]["employee_ref"] = "person@example.test"
        self.assertIsNone(contributions.run_php(rejected_amount, valid=False))

        # M06 unapproved rule, foreign scope, calendar and review errors.
        self.assertEqual("REQUIERE_REVISION", applicability.evaluate(
            applicability.rule(approved=False), applicability.profile())["kind"])
        self.assertEqual("REQUIERE_REVISION", applicability.evaluate(
            applicability.rule(), applicability.profile(tenant="tenant-b"))["kind"])
        self.assertEqual("REQUIERE_REVISION", matrix.matrix(
            [matrix.rule(source={})], matrix.profile())["rows"][0]["kind"])
        row = calendar.project(deadlines=[calendar.deadline(tenant_ref="tenant-b")])["rows"][0]
        self.assertFalse(row["attention_candidate"])
        self.assertEqual("REQUIERE_REVISION", row["state"])
        bad_review = review.project(reviews=[review.review(state="CUMPLE")])["rows"][0]
        self.assertEqual("REQUIERE_REVISION", bad_review["state"])
        self.assertIsNone(bad_review["evidence_ref"])
        bad_audit = audit.project(audits=[audit.audit(tenant_ref="tenant-b")])["rows"][0]
        self.assertEqual("REQUIERE_REVISION", bad_audit["state"])
        self.assertFalse(bad_audit["corrective_action_candidate"])
        # M07: no forged finance period closing or cross-entity leak.
        closed = finance.project(finance.fixture(periods=[finance.period(state="CERRADO")]))
        self.assertEqual([], closed["periods"])
        wrong_finance = finance.project(finance.fixture(legal_entity_ref="fixture:legal-entity/foreign"))
        self.assertEqual([], wrong_finance["periods"])
        # M08: a signer without an independent review is rejected.
        unapproved = documents.fixture()
        unapproved["records"][0]["events"] = [
            documents.event("APROBAR", "approver", "aprobador"),
        ]
        self.assertFalse(documents.project(unapproved)["valid"])
        forged = documents.fixture()
        forged["records"][0]["author_ref"] = "person@example.test"
        result = documents.project(forged)
        self.assertFalse(result["valid"])
        self.assertNotIn("person@example.test", json.dumps(result))

    def test_sin_datos_reales(self) -> None:
        """No network, credentials, real employee data or automatic legal effects."""
        source = Path(__file__).read_text(encoding="utf-8")
        tree = ast.parse(source)
        used = set()
        for item in ast.walk(tree):
            if isinstance(item, ast.Import):
                used.update(alias.name.split(".")[0] for alias in item.names)
            elif isinstance(item, ast.ImportFrom) and item.module:
                used.add(item.module.split(".")[0])
        self.assertFalse(used & {"requests", "httpx", "urllib", "socket", "psycopg2", "pymysql"})
        self.assertEqual(12, len(ROWS))
        outputs = (
            contributions.run_php(contributions.fixture()),
            pila.run_php(pila.fixture()),
            matrix.matrix([matrix.rule()], matrix.profile()),
            calendar.project(), audit.project(), finance.project(),
            documents.project(documents.fixture()),
        )
        for case in outputs:
            report = json.dumps(case)
            self.assertNotIn("@", report)
            self.assertNotIn('"CUMPLE"', report)
        self.assertFalse(pila.run_php(pila.fixture())["can_send"])
        self.assertFalse(finance.project()["can_close"])
        self.assertFalse(documents.project(documents.fixture())["automatic_approval"])


if __name__ == "__main__":
    unittest.main()
