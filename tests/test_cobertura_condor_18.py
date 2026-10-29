#!/usr/bin/env python3
"""Condor #1337: twelve real PHP synthetic M03/M04 behavior contracts.

Offline projections are not professional SG-SST approval, real payroll,
deployment, or authority to process labor or medical personal data.
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

import test_compliance_occupational_safety_precheck as precheck
import test_m03_peligros as hazards
import test_m03_incidentes as incidents
import test_m03_epp as activities
import test_m04_periodos as payroll

MATRIX = ROOT / "docs/matriz-cobertura.md"
BACKTICK = chr(96)
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


def matrix_rows(raw: object) -> dict[str, tuple[str, ...]]:
    """Fail closed on any missing, duplicated, truncated or promoted matrix row."""
    if type(raw) is not str or not raw.strip():
        raise ValueError("UNKNOWN: missing matrix")
    result = {}
    for line in raw.splitlines():
        if not line.startswith("| " + BACKTICK + "GEST-M0"):
            continue
        cols = tuple(part.strip() for part in line.strip("|").split("|"))
        if not cols or BACKTICK + " · " not in cols[0]:
            continue
        key = cols[0].split(BACKTICK + " · ", 1)[0].strip(BACKTICK)
        if key not in CLAUSES:
            continue
        if key in result:
            raise ValueError("UNKNOWN: duplicated " + key)
        expected = BACKTICK + key + BACKTICK + " · " + BACKTICK + "docs/gestion-empresarial-integral.md" + BACKTICK
        if (len(cols) != 5 or cols[0] != expected
                or cols[1] != CLAUSES[key] or cols[2] != BACKTICK + "NO_VERIFICABLE" + BACKTICK
                or "estado B de #742" not in cols[3] or cols[4] != "#802"):
            raise ValueError("UNKNOWN: altered row " + key)
        result[key] = cols
    if set(result) != set(CLAUSES):
        raise ValueError("UNKNOWN: incomplete 12-row matrix")
    return result


def ppe_preview(kind: str, **changes: object) -> dict:
    data = activities.payload()
    data["activities"] = [activities.activity(kind, "case-" + kind.lower(), **changes)]
    return activities.project(data)


class CoberturaCondor18Tests(unittest.TestCase):
    def test_contrato_principal(self) -> None:
        """Every claimed row executes its genuine PHP behavior with synthetic inputs."""
        matrix = MATRIX.read_text(encoding="utf-8")
        rows = matrix_rows(matrix)
        self.assertEqual(len(rows), 12)
        for key in CLAUSES:
            with self.subTest(fila=key):
                self.assertEqual(rows[key][1], CLAUSES[key])

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
        matrix = MATRIX.read_text(encoding="utf-8")
        self.assertEqual(len(matrix_rows(matrix)), 12)
        for key in CLAUSES:
            row = next(line for line in matrix.splitlines()
                       if line.startswith("| " + BACKTICK + key + BACKTICK + " · "))
            for tampered in (matrix.replace(row, "", 1),
                             matrix + "\n" + row,
                             matrix.replace(row, row.replace("NO_VERIFICABLE", "CUBIERTO", 1), 1)):
                with self.subTest(fila=key):
                    with self.assertRaisesRegex(ValueError, "UNKNOWN"):
                        matrix_rows(tampered)
        with self.assertRaisesRegex(ValueError, "UNKNOWN"):
            matrix_rows(None)
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
