#!/usr/bin/env python3
"""M12 S01-S05: execute genuine PHP with synthetic fixtures and negative cases."""
from __future__ import annotations

import copy
import json
import subprocess
import unittest
from pathlib import Path

ROOT = Path(__file__).resolve().parents[1]
CLASS = ROOT / "src/Application/Risk/RiskRegisterControls.php"
TENANT_A = "fixture:tenant/company-a"
TENANT_B = "fixture:tenant/company-b"


def risk(tenant: str = TENANT_A, **changes: object) -> dict:
    value = {
        "tenant_ref": tenant,
        "risk_ref": "fixture:risk/operational-1",
        "category": "operativo",
        "probability": 4,
        "impact": 5,
        "state": "PENDIENTE",
    }
    value.update(changes)
    return value


def control(tenant: str = TENANT_A, **changes: object) -> dict:
    value = {
        "tenant_ref": tenant,
        "control_ref": "fixture:risk-control/mitigation-1",
        "risk_ref": "fixture:risk/operational-1",
        "effectiveness": 50,
        "state": "PENDIENTE",
    }
    value.update(changes)
    return value


def payload(tenant: str = TENANT_A, **changes: object) -> dict:
    value = {"tenant_ref": tenant, "risks": [risk(tenant)], "controls": [control(tenant)]}
    value.update(changes)
    return value


def project(data: dict | None = None, tenant: str = TENANT_A) -> dict:
    php = r"""
require $argv[1];
use App\Application\Risk\RiskRegisterControls;
$data = json_decode($argv[2], true, 32, JSON_THROW_ON_ERROR);
echo json_encode(RiskRegisterControls::project($data['payload'], $data['tenant']), JSON_THROW_ON_ERROR);
"""
    result = subprocess.run(
        ["php", "-n", "-d", "allow_url_fopen=0", "-r", php,
         str(CLASS), json.dumps({"payload": payload() if data is None else data, "tenant": tenant})],
        cwd=ROOT, text=True, capture_output=True, check=True, timeout=20,
    )
    return json.loads(result.stdout)


class RiskRegisterControlsTests(unittest.TestCase):
    def assert_rejected(self, result: dict) -> None:
        self.assertEqual("REQUIERE_REVISION", result["state"])
        self.assertFalse(result["structurally_reviewable"])
        self.assertTrue(result["requires_professional_review"])
        self.assertTrue(result["synthetic_only"])
        self.assertEqual([], result["risks"])
        self.assertEqual([], result["categories"])
        self.assertEqual(0, result["risk_count"])

    def test_resultado_determinista(self) -> None:
        first = project()
        self.assertEqual(first, project())
        self.assertEqual("PENDIENTE", first["state"])
        self.assertEqual(1, first["risk_count"])
        self.assertEqual(20, first["risks"][0]["inherent_score"])
        self.assertEqual(10, first["risks"][0]["residual_score"])
        self.assertEqual("ALTO", first["risks"][0]["inherent_level"])
        self.assertEqual("MEDIO", first["risks"][0]["residual_level"])
        self.assertEqual(1, first["categories"]["operativo"]["count"])
        self.assertEqual(0, first["categories"]["legal"]["count"])
        self.assertEqual(0, project(payload(risks=[], controls=[]))["risk_count"])
        for candidate in (
            payload(risks=[risk(), risk()]),
            payload(controls=[control(), control()]),
            payload(risks={"other": risk()}),
            payload(risks=[risk()] * 51),
            payload(controls=[control(risk_ref="fixture:risk/missing")]),
            payload(risks=[risk(probability=0)]),
            payload(risks=[risk(impact=6)]),
            payload(controls=[control(effectiveness=101)]),
            payload(controls=[control(effectiveness=True)]),
        ):
            with self.subTest(candidate=candidate):
                self.assert_rejected(project(candidate))
        second_control = control(control_ref="fixture:risk-control/mitigation-2", effectiveness=20)
        a = project(payload(controls=[control(), second_control]))
        b = project(payload(controls=[second_control, control()]))
        self.assertEqual(a, b)
        self.assertGreaterEqual(a["risks"][0]["residual_score"], 1)

    def test_aislamiento_por_empresa(self) -> None:
        b = project(payload(TENANT_B), tenant=TENANT_B)
        self.assertTrue(b["structurally_reviewable"])
        self.assertEqual(1, b["risk_count"])
        self.assertEqual(20, b["risks"][0]["inherent_score"])
        for foreign in (
            project(payload(), tenant=TENANT_B),
            project(payload(TENANT_B), tenant=TENANT_A),
            project(payload(risks=[risk(TENANT_B)])),
            project(payload(controls=[control(TENANT_B)])),
            project(payload(risks=[risk(), risk(TENANT_B)])),
        ):
            with self.subTest(value=foreign):
                self.assert_rejected(foreign)
                self.assertNotIn("company-b", json.dumps(foreign))

    def test_rechaza_datos_reales(self) -> None:
        cases = (
            payload(email="person@example.test"),
            payload(risks=[risk(document_number="123456789")]),
            payload(controls=[control(phone="3001234567")]),
            payload(controls=[control(control_ref="person@example.test")]),
            payload(controls=[control(control_ref="fixture:risk-control/ana@example.test")]),
            payload(controls=[control(control_ref="3001234567")]),
            payload(risks=[risk(risk_ref="123456789")]),
            payload(risks=[risk(risk_ref="fixture:risk/123456789")]),
            payload(risks=[risk(risk_ref="fixture:risk/example123456789")]),
            payload(tenant_ref="fixture:tenant/3001234567"),
            payload(risks=[risk(risk_ref="https://example.test/secret")]),
            payload(risks=[risk(category="Persona Real")]),
            payload(risks=[risk(state=True)]),
            payload(controls=[control(effectiveness=75.5)]),
        )
        for item in cases:
            with self.subTest(item=item):
                result = project(item)
                self.assert_rejected(result)
                for forbidden in ("@example", "300123", "123456789", "Persona Real"):
                    self.assertNotIn(forbidden, json.dumps(result))
        source = CLASS.read_text(encoding="utf-8").lower()
        for prohibited in (
            "pdo(", "doctrine", "mysqli", "curl_", "httpclient", "file_get_contents(",
            "shell_exec(", "exec(", "fetch(", "http://", "https://",
        ):
            self.assertNotIn(prohibited, source)

    def test_sin_cierre_automatico(self) -> None:
        first = project()
        self.assertEqual("PENDIENTE", first["state"])
        self.assertEqual("PENDIENTE", first["risks"][0]["state"])
        self.assertEqual("PENDIENTE", first["risks"][0]["controls"][0]["state"])
        self.assertTrue(first["requires_professional_review"])
        for banned in ("CERRADO", "APROBADO", "RESUELTO", "CUMPLE", "NO_APLICA", False, None):
            with self.subTest(banned=banned):
                self.assert_rejected(project(payload(risks=[risk(state=banned)])))
                self.assert_rejected(project(payload(controls=[control(state=banned)])))
        flagged = project(payload(controls=[control(state="REQUIERE_REVISION")]))
        self.assertEqual("REQUIERE_REVISION", flagged["state"])
        self.assertTrue(flagged["structurally_reviewable"])
        self.assertEqual("REQUIERE_REVISION", flagged["risks"][0]["controls"][0]["state"])
        # Even 100% synthetic effectiveness never means risk is closed or eliminated.
        strongest = project(payload(controls=[control(effectiveness=100)]))
        self.assertEqual("PENDIENTE", strongest["state"])
        self.assertEqual(1, strongest["risks"][0]["residual_score"])
        self.assertTrue(strongest["requires_professional_review"])
        self.assert_rejected(project(payload(approved=True)))
        self.assertEqual(first, project(copy.deepcopy(payload())))


if __name__ == "__main__":
    unittest.main()
