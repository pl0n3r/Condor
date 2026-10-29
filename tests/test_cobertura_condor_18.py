#!/usr/bin/env python3
"""Condor #1337: twelve real PHP synthetic M03/M04 behavior contracts.

Offline projections are not professional SG-SST approval, real payroll,
deployment, or authority to process labor or medical personal data.
"""
from __future__ import annotations

import ast
import json
from pathlib import Path
import sys
import unittest

ROOT = Path(__file__).resolve().parents[1]
TESTS = ROOT / "tests"
if str(TESTS) not in sys.path:
    sys.path.insert(0, str(TESTS))

import test_compliance_occupational_safety_precheck as precheck
import test_m03_peligros as hazards
import test_m03_incidentes as incidents
import test_m03_epp as activities
import test_m04_periodos as payroll

CLAUSES = {
    "GEST-M03": "Módulo 3: SG-SST",
    "GEST-M03-S01": "SG-SST / perfil de estándares",
    "GEST-M03-S02": "SG-SST / matriz de peligros",
    "GEST-M03-S03": "SG-SST / controles",
    "GEST-M03-S04": "SG-SST / inspecciones",
    "GEST-M03-S05": "SG-SST / incidentes",
    "GEST-M03-S06": "SG-SST / investigación",
    "GEST-M03-S07": "SG-SST / EPP",
    "GEST-M03-S08": "SG-SST / capacitación",
    "GEST-M03-S09": "SG-SST / emergencias",
    "GEST-M03-S10": "SG-SST / comités/vigías",
    "GEST-M04": "Módulo 4: Nómina",
}


def ppe_preview(kind: str, **changes: object) -> dict:
    data = activities.payload()
    data["activities"] = [activities.activity(kind, "case-" + kind.lower(), **changes)]
    return activities.project(data)


class CoberturaCondor18Tests(unittest.TestCase):
    def test_contrato_principal(self) -> None:
        """Every claimed row executes its genuine PHP behavior with synthetic inputs."""
        # Module 3: global SG-SST cannot certify legal compliance.
        summary = precheck.project()
        self.assertTrue(summary["requires_professional_review"])
        self.assertTrue(summary["synthetic_only"])
        self.assertNotEqual("CUMPLE", summary["state"])
        # S01: profile of standards exercises real legal-entity-scoped PHP.
        standards = precheck.project(precheck.profile())
        self.assertEqual("EN_REVISION", standards["state"])
        self.assertTrue(standards["structurally_reviewable"])
        # S02, S03, S04: hazard scoring, control hierarchy and inspection.
        hazard = hazards.project()
        self.assertEqual(12, hazard["hazards"][0]["risk_score_synthetic"])
        self.assertEqual("MEDIO_SINTETICO", hazard["hazards"][0]["risk_band_synthetic"])
        self.assertEqual("INGENIERIA", hazard["hazards"][0]["controls"][0]["hierarchy"])
        self.assertEqual("PROPUESTO", hazard["hazards"][0]["controls"][0]["state"])
        self.assertEqual("PROGRAMADA", hazard["inspections"][0]["state"])
        # S05, S06: actual incident and unapproved investigation timeline.
        investigation = incidents.project(incidents.fixture())
        self.assertEqual(1, len(investigation["incidents"]))
        self.assertFalse(investigation["automatic_approval"])
        event = investigation["incidents"][0]
        self.assertEqual("EN_REVISION", event["investigation_state"])
        self.assertEqual("2026-10-22", event["deadline"]["due_on"])
        self.assertEqual("PENDIENTE_REVISION", event["actions"][0]["state"])
        # S07-S10: EPP, training, emergency drill, committee/vigía.
        ppe = ppe_preview("EPP_ENTREGA")
        self.assertEqual("PENDIENTE", ppe["rows"][0]["state"])
        training = ppe_preview("CAPACITACION")
        self.assertEqual("PENDIENTE", training["rows"][0]["state"])
        self.assertTrue(training["rows"][0]["requires_professional_review"])
        drill = ppe_preview("SIMULACRO_EMERGENCIA", due_on="2026-10-15")
        self.assertEqual("VENCIDA", drill["rows"][0]["state"])
        committee = ppe_preview("COMITE_VIGIA", occurred_on=None, evidence_ref=None)
        self.assertEqual("SIN_EVIDENCIA", committee["rows"][0]["state"])
        for evidence in (ppe, training, drill, committee):
            self.assertFalse(evidence["automatic_approval"])
            self.assertFalse(evidence["automatic_closure"])
        # Module 4: pure real PHP payroll, exact amounts without legal effect.
        payroll_case = payroll.project(payroll.fixture())
        self.assertEqual([], payroll_case["errors"])
        self.assertEqual(123450, payroll_case["concepts"][0]["amount_hundredths"])
        self.assertEqual(125, payroll_case["novelties"][0]["quantity_hundredths"])
        self.assertEqual(4075, payroll_case["hours"][0]["hours_hundredths"])
        self.assertFalse(payroll_case["legal_effect"])
        self.assertFalse(payroll_case["automatic_approval"])

    def test_falla_cerrado(self) -> None:
        """Missing records, bad dates, false approvals and foreign entity denied."""
        wrong_profile = precheck.project(precheck.profile(
            legal_entity_ref="fixture:legal-entity/foreign"))
        self.assertEqual("REQUIERE_REVISION", wrong_profile["state"])
        self.assertFalse(wrong_profile["structurally_reviewable"])
        wrong_hazard = hazards.project(hazards.matrix(
            legal_entity_ref="fixture:legal-entity/foreign"))
        self.assertEqual([], wrong_hazard["hazards"])
        self.assertEqual([], wrong_hazard["inspections"])
        incident = incidents.fixture()
        incident["investigations"][0]["due_on"] = "2026-02-30"
        self.assertEqual(4, incidents.run_php(incident).returncode)
        ppe = activities.payload()
        ppe["activities"][0]["review_state"] = "CUMPLE"
        self.assertEqual({}, activities.project(ppe, rejected=True))
        foreign_payroll = payroll.fixture()
        foreign_payroll["periods"][0]["legal_entity_ref"] = "fixture:entity/foreign"
        result = payroll.project(foreign_payroll)
        self.assertTrue(result["errors"])
        self.assertEqual([], result["periods"])

    def test_sin_datos_reales(self) -> None:
        """Only synthetic fixtures; no network or contact data in the output."""
        tree = ast.parse(Path(__file__).read_text(encoding="utf-8"))
        imports = set()
        for node in ast.walk(tree):
            if isinstance(node, ast.Import):
                imports.update(x.name.split(".")[0] for x in node.names)
            elif isinstance(node, ast.ImportFrom) and node.module:
                imports.add(node.module.split(".")[0])
        self.assertFalse(imports & {"socket", "requests", "urllib", "httpx",
                                    "pymysql", "psycopg2"})
        for result in (precheck.project(), hazards.project(),
                       incidents.project(incidents.fixture()),
                       activities.project(activities.payload()),
                       payroll.project(payroll.fixture())):
            output = json.dumps(result)
            self.assertNotIn("@", output)
            self.assertNotIn("CUMPLE", output)
        self.assertEqual(len(CLAUSES), 12)


if __name__ == "__main__":
    unittest.main()
