#!/usr/bin/env python3
"""M03 S02-S04: run real PHP in an isolated CLI; never use mocked source text."""
from __future__ import annotations

import copy
import json
from pathlib import Path
import subprocess
import unittest

ROOT = Path(__file__).resolve().parents[1]
CLASS = ROOT / "src/Application/Compliance/OccupationalHazardMatrix.php"
TENANT = "fixture:tenant/tenant-a"
ENTITY = "fixture:legal-entity/entity-a"
HAZARD = "fixture:sgsst-hazard/h-1"


def control(**changes: object) -> dict:
    data = {
        "control_ref": "fixture:sgsst-control/c-1",
        "tenant_ref": TENANT,
        "legal_entity_ref": ENTITY,
        "hierarchy": "INGENIERIA",
        "status": "PROPUESTO",
    }
    data.update(changes)
    return data


def hazard(**changes: object) -> dict:
    data = {
        "hazard_ref": HAZARD,
        "tenant_ref": TENANT,
        "legal_entity_ref": ENTITY,
        "probability": 3,
        "severity": 4,
        "controls": [control()],
    }
    data.update(changes)
    return data


def inspection(**changes: object) -> dict:
    data = {
        "inspection_ref": "fixture:sgsst-inspection/i-1",
        "tenant_ref": TENANT,
        "legal_entity_ref": ENTITY,
        "hazard_ref": HAZARD,
        "scheduled_on": "2026-12-01",
        "status": "PROGRAMADA",
    }
    data.update(changes)
    return data


def matrix(**changes: object) -> dict:
    data = {
        "fixture_ref": "fixture:sgsst-hazard-matrix/m-1",
        "tenant_ref": TENANT,
        "legal_entity_ref": ENTITY,
        "hazards": [hazard()],
        "inspections": [inspection()],
    }
    data.update(changes)
    return data


def project(payload: dict | None = None, tenant: str = TENANT, entity: str = ENTITY) -> dict:
    php = r"""
require $argv[1];
use App\Application\Compliance\OccupationalHazardMatrix;
$data = json_decode($argv[2], true, 32, JSON_THROW_ON_ERROR);
echo json_encode(OccupationalHazardMatrix::project(
    $data['matrix'], $data['tenant'], $data['entity']
), JSON_THROW_ON_ERROR);
"""
    done = subprocess.run(
        ["php", "-n", "-d", "allow_url_fopen=0", "-r", php,
         str(CLASS), json.dumps({"matrix": matrix() if payload is None else payload,
                                 "tenant": tenant, "entity": entity})],
        cwd=ROOT, text=True, capture_output=True, check=True, timeout=20,
    )
    return json.loads(done.stdout)


class OccupationalHazardMatrixTests(unittest.TestCase):
    def assert_safe(self, output: dict, hazards: int, inspections: int) -> None:
        self.assertEqual("REQUIERE_REVISION", output["state"])
        self.assertIs(output["requires_professional_review"], True)
        self.assertIs(output["synthetic_only"], True)
        self.assertEqual(hazards, output["hazard_count"])
        self.assertEqual(inspections, output["inspection_count"])
        self.assertEqual(hazards, len(output["hazards"]))
        self.assertEqual(inspections, len(output["inspections"]))
        self.assertEqual(
            {"state", "requires_professional_review", "synthetic_only", "hazard_count",
             "inspection_count", "hazards", "inspections"}, set(output),
        )
        output_text = json.dumps(output)
        for forbidden in ("CUMPLE", "APROBADO", "CERRADO", "correo", "email", "telefono"):
            self.assertNotIn(forbidden, output_text)

    def test_resultado_determinista(self) -> None:
        first = project()
        self.assertEqual(first, project())
        self.assert_safe(first, 1, 1)
        self.assertEqual(12, first["hazards"][0]["risk_score_synthetic"])
        self.assertEqual("MEDIO_SINTETICO", first["hazards"][0]["risk_band_synthetic"])
        self.assertEqual("INGENIERIA", first["hazards"][0]["controls"][0]["hierarchy"])
        self.assertEqual("PROGRAMADA", first["inspections"][0]["state"])

        order_a = matrix(hazards=[hazard(controls=[
            control(control_ref="fixture:sgsst-control/epp", hierarchy="EPP"),
            control(),
            control(control_ref="fixture:sgsst-control/sub", hierarchy="SUSTITUCION"),
        ])])
        order_b = copy.deepcopy(order_a)
        order_b["hazards"][0]["controls"].reverse()
        self.assertEqual(project(order_a), project(order_b))
        self.assertEqual(
            ["SUSTITUCION", "INGENIERIA", "EPP"],
            [c["hierarchy"] for c in project(order_a)["hazards"][0]["controls"]],
        )
        for probability, severity, expected in (
            (1, 1, "BAJO_SINTETICO"),
            (3, 4, "MEDIO_SINTETICO"),
            (5, 5, "ALTO_SINTETICO"),
        ):
            with self.subTest(probability=probability, severity=severity):
                item = matrix(hazards=[hazard(probability=probability, severity=severity)])
                self.assertEqual(expected, project(item)["hazards"][0]["risk_band_synthetic"])

    def test_aislamiento_por_empresa(self) -> None:
        other_tenant = "fixture:tenant/tenant-b"
        other_entity = "fixture:legal-entity/entity-b"
        for bad in (
            matrix(tenant_ref=other_tenant),
            matrix(legal_entity_ref=other_entity),
            matrix(hazards=[hazard(tenant_ref=other_tenant)]),
            matrix(hazards=[hazard(legal_entity_ref=other_entity)]),
            matrix(hazards=[hazard(controls=[control(tenant_ref=other_tenant)])]),
            matrix(hazards=[hazard(controls=[control(legal_entity_ref=other_entity)])]),
            matrix(inspections=[inspection(tenant_ref=other_tenant)]),
            matrix(inspections=[inspection(legal_entity_ref=other_entity)]),
            matrix(inspections=[inspection(hazard_ref="fixture:sgsst-hazard/foreign")]),
        ):
            with self.subTest(bad=bad):
                output = project(bad)
                self.assert_safe(output, 0, 0)
                self.assertNotIn("tenant-b", json.dumps(output))
                self.assertNotIn("entity-b", json.dumps(output))
        self.assert_safe(project(tenant=other_tenant), 0, 0)
        self.assert_safe(project(entity=other_entity), 0, 0)
        second = matrix(tenant_ref=other_tenant, legal_entity_ref=other_entity)
        second["hazards"][0]["tenant_ref"] = other_tenant
        second["hazards"][0]["legal_entity_ref"] = other_entity
        second["hazards"][0]["controls"][0]["tenant_ref"] = other_tenant
        second["hazards"][0]["controls"][0]["legal_entity_ref"] = other_entity
        second["inspections"][0]["tenant_ref"] = other_tenant
        second["inspections"][0]["legal_entity_ref"] = other_entity
        self.assertEqual(project(), project(second, other_tenant, other_entity))

    def test_rechaza_datos_reales(self) -> None:
        invalid = (
            matrix(email="employee@example.com"),
            matrix(cedula="1234567890"),
            matrix(telefono="3001234567"),
            matrix(fixture_ref="1234567890"),
            matrix(tenant_ref="someone@example.com"),
            matrix(hazards=[hazard(worker_name="private")]),
            matrix(hazards=[hazard(documento_identidad="1234567890")]),
            matrix(hazards=[hazard(probability="3")]),
            matrix(hazards=[hazard(probability=True)]),
            matrix(hazards=[hazard(probability=6)]),
            matrix(hazards=[hazard(controls=[control(email="mail@example.com")])]),
            matrix(hazards=[hazard(controls=[control(status="APROBADO")])]),
            matrix(hazards=[hazard(controls=[control(control_ref="1234567890")])]),
            matrix(inspections=[inspection(phone="3001234567")]),
            matrix(inspections=[inspection(inspection_ref="john@example.com")]),
            matrix(inspections=[inspection(scheduled_on="2026-02-30")]),
            matrix(inspections=[inspection(scheduled_on="2026-1-1")]),
            matrix(inspections=[inspection(status="REALIZADA")]),
            matrix(hazards={"named": hazard()}),
            matrix(inspections={"named": inspection()}),
        )
        for bad in invalid:
            with self.subTest(case=bad):
                output = project(bad)
                self.assert_safe(output, 0, 0)
                for sensitive in ("john", "employee", "3001234567", "1234567890", "private"):
                    self.assertNotIn(sensitive, json.dumps(output))
        source = CLASS.read_text(encoding="utf-8").lower()
        for unsafe in ("curl_", "file_get_contents(", "pdo(", "doctrine", "mysqli",
                       "httpclient", "shell_exec(", "exec(", "file_put_contents("):
            self.assertNotIn(unsafe, source)

    def test_sin_cierre_automatico(self) -> None:
        output = project()
        self.assert_safe(output, 1, 1)
        self.assertEqual("REQUIERE_REVISION", output["hazards"][0]["state"])
        self.assertEqual("PROPUESTO", output["hazards"][0]["controls"][0]["state"])
        self.assertEqual("PROGRAMADA", output["inspections"][0]["state"])
        for unsafe in (
            matrix(hazards=[hazard(controls=[control(status="CERRADO")])]),
            matrix(hazards=[hazard(controls=[control(status="VERIFICADO")])]),
            matrix(inspections=[inspection(status="APROBADA")]),
            matrix(hazards=[hazard(), hazard()]),
            matrix(hazards=[hazard(controls=[control(), control()])]),
            matrix(inspections=[inspection(), inspection()]),
            matrix(hazards=[hazard(controls=[control(hierarchy="MEDICO")])]),
            matrix(hazards=[hazard(controls=[control(hierarchy="PROTECCION_PERSONAL")])]),
        ):
            with self.subTest(unsafe=unsafe):
                self.assert_safe(project(unsafe), 0, 0)
        self.assert_safe(project(matrix(hazards=[], inspections=[])), 0, 0)
        self.assert_safe(project(matrix(hazards=[hazard(controls=[])], inspections=[])), 1, 0)
        self.assert_safe(project(matrix(hazards=[hazard()] * 51)), 0, 0)
        self.assert_safe(project(matrix(inspections=[inspection()] * 101)), 0, 0)
        self.assert_safe(project(matrix(hazards=[hazard(controls=[
            control(control_ref=f"fixture:sgsst-control/c-{i}") for i in range(13)
        ])])), 0, 0)


if __name__ == "__main__":
    unittest.main()
