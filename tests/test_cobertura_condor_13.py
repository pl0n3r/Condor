#!/usr/bin/env python3
"""Condor #1332: cobertura conductual offline de cortes existentes GEST.

Ejercita clases PHP reales a través de los harnesses sintéticos existentes.
No certifica el catálogo integral, acceso live, revisión jurídica ni piloto.
"""
from __future__ import annotations

import json
import runpy
import unittest
from pathlib import Path

from scripts.ci_change_classifier import classify

ROOT = Path(__file__).resolve().parents[1]


def _harness(filename: str) -> dict:
    """Cargar exclusivamente harnesses locales, sin ejecutar su unittest.main."""
    names = {
        "test_compliance_normative_applicability.py",
        "test_compliance_normative_matrix.py",
        "test_compliance_normative_review_projection.py",
        "test_compliance_occupational_safety_precheck.py",
        "test_m12_riesgos.py",
        "test_m09_quotation_preview.py",
    }
    if filename not in names:
        raise AssertionError("Harness no autorizado")
    return runpy.run_path(str(ROOT / "tests" / filename))


F0 = _harness("test_compliance_normative_applicability.py")
F1 = _harness("test_compliance_normative_matrix.py")
REVIEW = _harness("test_compliance_normative_review_projection.py")
SST = _harness("test_compliance_occupational_safety_precheck.py")
RISK = _harness("test_m12_riesgos.py")
QUOTE = _harness("test_m09_quotation_preview.py")


class CoberturaCondor13Tests(unittest.TestCase):
    def test_contrato_principal(self) -> None:
        """GEST-AC-04/F0/F1/F2/F3/F5/FLUJO-1: PHP real, evidencia parcial."""
        # GEST-AC-04 y F0: clasificación condicionada a fuente/version/vigencia.
        rule = F0["rule"]()
        profile = F0["profile"]()
        current = F0["evaluate"](rule, profile)
        self.assertEqual(("OBLIGATORIO", "APLICA"),
                         (current["kind"], current["applicability"]))
        self.assertEqual(rule["source"], current["source"])
        changed_source = {**rule["source"], "version": "v2"}
        versioned = F0["evaluate"](F0["rule"](source=changed_source), profile)
        self.assertEqual("v2", versioned["source"]["version"])
        self.assertEqual("REQUIERE_REVISION",
                         F0["evaluate"](rule, profile, "2027-01-01")["kind"])

        # F1: matriz real, filtro de tenant y traza profesional de la norma.
        foreign = F1["rule"]("tenant-b", kind="PROHIBIDO",
                             conditions={"sector": "tecnologia", "municipio": "bogota"})
        matrix = F1["matrix"]([F1["rule"](), foreign], F1["profile"]())
        self.assertEqual("tenant-a", matrix["tenant_ref"])
        self.assertEqual(1, len(matrix["rows"]))
        self.assertEqual("OBLIGATORIO", matrix["rows"][0]["kind"])
        self.assertEqual("reviewer-synthetic", matrix["rows"][0]["reviewer_ref"])
        self.assertEqual("v1", matrix["rows"][0]["source"]["version"])
        self.assertEqual("2026-12-31", matrix["rows"][0]["effective_until"])

        # GEST-FLUJO-1 (corte offline): evidencia no autodeclara CUMPLE;
        # revisión humana y fecha quedan explícitas en la proyección PHP.
        pending = REVIEW["project"]()["rows"][0]
        self.assertEqual("PENDIENTE", pending["state"])
        self.assertIsNone(pending["evidence_ref"])
        reviewed = REVIEW["project"](reviews=[REVIEW["review"]()])["rows"][0]
        self.assertEqual("EN_REVISION", reviewed["state"])
        self.assertEqual("reviewer-synthetic", reviewed["reviewer_ref"])
        self.assertEqual("2026-03-10", reviewed["reviewed_on"])
        self.assertEqual("fixture:evidence/document-1", reviewed["evidence_ref"])
        self.assertEqual("v1", reviewed["source"]["version"])

        # F2: registro de riesgos real, resultados pendientes sin autocierre.
        risk = RISK["project"]()
        self.assertEqual("PENDIENTE", risk["state"])
        self.assertEqual(1, risk["risk_count"])
        self.assertEqual(20, risk["risks"][0]["inherent_score"])
        self.assertEqual("PENDIENTE", risk["risks"][0]["controls"][0]["state"])
        self.assertTrue(risk["requires_professional_review"])

        # F3: SG-SST solo prechequeo ficticio, jamás aptitud médica ni CUMPLE.
        precheck = SST["project"]()
        self.assertEqual("EN_REVISION", precheck["state"])
        self.assertTrue(precheck["synthetic_only"])
        self.assertTrue(precheck["requires_professional_review"])

        # F5: el dominio de compras existente permite preview, no otra orden.
        quote = QUOTE["run"](QUOTE["quote"]())["ok"]
        self.assertEqual("SYNTHETIC_PREVIEW", quote["status"])
        self.assertEqual("COP", quote["currency"])
        self.assertEqual(1250, quote["total_cop"])
        for permission in ("can_order", "can_pay", "can_approve",
                           "can_dispatch", "persisted"):
            self.assertIs(quote[permission], False)

    def test_falla_cerrado(self) -> None:
        """Denegación por vigencia, fuente, contexto, permisos y ámbito."""
        rule, profile = F0["rule"](), F0["profile"]()
        for denied in (
            F0["rule"](approved=False),
            F0["rule"](reviewer_ref=""),
            F0["rule"](source={}),
            F0["rule"](effective_until="2026-01-01"),
        ):
            with self.subTest(rule=denied):
                out = F0["evaluate"](denied, profile)
                self.assertEqual("REQUIERE_REVISION", out["kind"])
                self.assertIsNone(out["source"])
        self.assertEqual("REQUIERE_REVISION",
                         F0["evaluate"](rule, F0["profile"]("tenant-b"))["kind"])
        self.assertEqual("REQUIERE_REVISION",
                         F0["evaluate"](rule, {"tenant_ref": "tenant-a"})["kind"])

        # F1/F1b: una revisión ajena no filtra datos ni habilita CUMPLE.
        foreign = REVIEW["review"](tenant_ref="tenant-b",
                                     evidence_ref="fixture:evidence/foreign")
        projection = REVIEW["project"](reviews=[foreign])
        self.assertEqual("PENDIENTE", projection["rows"][0]["state"])
        self.assertNotIn("foreign", json.dumps(projection))
        for invalid in (REVIEW["review"](state="CUMPLE"),
                        REVIEW["review"](reviewer_ref=""),
                        REVIEW["review"](evidence_ref="https://invalid.example/evidence"),
                        REVIEW["review"](reviewed_on="2026-02-30")):
            with self.subTest(review=invalid):
                self.assertEqual(
                    "REQUIERE_REVISION",
                    REVIEW["project"](reviews=[invalid])["rows"][0]["state"],
                )

        # F2: no riesgo válido ni acceso entre tenants sin contexto.
        forbidden_risk = RISK["project"](RISK["payload"](), tenant=RISK["TENANT_B"])
        self.assertEqual("REQUIERE_REVISION", forbidden_risk["state"])
        self.assertEqual([], forbidden_risk["risks"])
        self.assertEqual("REQUIERE_REVISION",
                         RISK["project"](RISK["payload"](approved=True))["state"])
        # GEST-FLUJO-1: dos revisiones del mismo registro no dan permiso.
        duplicates = REVIEW["project"](reviews=[REVIEW["review"](), REVIEW["review"]()])
        self.assertEqual("REQUIERE_REVISION", duplicates["rows"][0]["state"])

        # F3: entidad legal y tenant son guardas independientes; sin PII.
        cross_entity = SST["project"](
            SST["profile"](), entity="fixture:legal-entity/entity-b"
        )
        self.assertEqual("REQUIERE_REVISION", cross_entity["state"])
        self.assertFalse(cross_entity["structurally_reviewable"])
        for item in (
            SST["profile"](tenant_ref="fixture:tenant/tenant-b"),
            SST["profile"](legal_entity_ref="fixture:legal-entity/entity-b"),
            SST["profile"](risk_category="UNKNOWN"),
            SST["profile"](employee_name="synthetic"),
        ):
            with self.subTest(sgst=item):
                out = SST["project"](item)
                self.assertEqual("REQUIERE_REVISION", out["state"])
                self.assertFalse(out["structurally_reviewable"])
                self.assertTrue(out["requires_professional_review"])

        # F5: el preview real no autoriza pago, despacho ni otra entidad.
        for bad in (
            QUOTE["quote"](mode="approve"),
            QUOTE["quote"](currency="USD"),
            QUOTE["quote"](lines=[QUOTE["line"](
                legal_entity_ref="fixture:legal-entity/demo-b")]),
        ):
            with self.subTest(quote=bad):
                denied = QUOTE["run"](bad)
                self.assertIn("error", denied)
                self.assertNotIn("ok", denied)

    def test_sin_datos_reales(self) -> None:
        """Entradas ficticias, salidas limitadas y rechazo de datos añadidos."""
        normative = F0["evaluate"](F0["rule"](), F0["profile"]())
        self.assertEqual("official", normative["source"]["kind"])
        self.assertEqual("authority-synthetic", normative["source"]["issuer"])
        precheck = SST["project"](SST["profile"]())
        self.assertEqual(
            {"state", "requires_professional_review", "synthetic_only",
             "structurally_reviewable"},
            set(precheck),
        )
        self.assertNotIn("tenant-a", json.dumps(precheck))
        self.assertNotIn("entity-a", json.dumps(precheck))
        denied_quote = QUOTE["run"](QUOTE["quote"](customer_name="fictitious"))
        self.assertIn("error", denied_quote)
        denied_risk = RISK["project"](
            RISK["payload"](risks=[RISK["risk"](email="synthetic@example.invalid")])
        )
        self.assertEqual("REQUIERE_REVISION", denied_risk["state"])
        self.assertNotIn("synthetic@example.invalid", json.dumps(denied_risk))
        for code in (
            "src/Application/Compliance/NormativeApplicability.php",
            "src/Application/Compliance/NormativeMatrix.php",
            "src/Application/Compliance/NormativeReviewProjection.php",
            "src/Application/Compliance/OccupationalSafetyPrecheckProjection.php",
        ):
            src = (ROOT / code).read_text(encoding="utf-8").lower()
            for forbidden in ("curl_", "file_get_contents(", "pdo(", "doctrine",
                              "httpclient", "shell_exec(", "exec(", "mysqli"):
                self.assertNotIn(forbidden, src, code)

    def test_ci_gate_contract(self) -> None:
        """AC-04: el selector real somete tests Python al CI del PR."""
        selection = classify(["tests/test_cobertura_condor_13.py"], "pull_request")
        self.assertTrue(selection.pruebas_base)
        self.assertIn(selection.modo, {"selectivo", "completo"})


if __name__ == "__main__":
    unittest.main()
