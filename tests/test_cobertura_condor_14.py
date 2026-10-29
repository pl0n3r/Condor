#!/usr/bin/env python3
"""Condor #1333: real PHP projections for the GEST-FLUJO coverage inventory.

Only synthetic fixture references. These checks exercise shipped PHP classes;
they do not assert production readiness, persistence, real RBAC or legal advice.
The B/D classifications for whole workflows are recorded in the PR and Issue.
"""
from __future__ import annotations

import json
import shutil
import subprocess
import unittest
from pathlib import Path

ROOT = Path(__file__).resolve().parents[1]

# None of the 12 complete business workflows is implemented end-to-end.
# These categories apply to the *full row*, not the narrower tested PHP slice.
OUTSIDE_FULL_COVERAGE = {
    "GEST-FLUJO-1-P01": "D: perfil administrable persistido con entidad/sede y RBAC integral todavía no existe",
    "GEST-FLUJO-1-P02": "B: las reglas sintéticas se evalúan offline, pero fuente oficial y revisión real requieren evidencia externa",
    "GEST-FLUJO-1-P03": "D: crear y asignar obligaciones persistidas no es lo mismo que proyectar un fixture",
    "GEST-FLUJO-1-P04": "D: falta el flujo real de subida y autorización del revisor; solo se evalúan referencias",
    "GEST-FLUJO-1-P05": "B: alertas sintéticas no prueban no bloquear trámites legalmente indispensables en operación",
    "GEST-FLUJO-2": "D: no hay expediente transversal end-to-end con política ejecutada",
    "GEST-FLUJO-2-P01": "D: la clasificación de fixture no implementa autorización documental/retención real",
    "GEST-FLUJO-2-P02": "D: el historial de hash sintético no realiza una carga de documento persistida",
    "GEST-FLUJO-2-P03": "D: la proyección revisa separación de actores, no valida potestad real del aprobador",
    "GEST-FLUJO-2-P04": "D: no existe ejecución autorizada de legal hold, exportación y eliminación con auditoría",
    "GEST-FLUJO-3": "B: proyecciones sin I/O no acreditan el ciclo integrado de riesgos, calidad y mejora",
    "GEST-FLUJO-3-P01": "D: el registro real de incidente/severidad con persistencia sigue fuera del slice sintético",
}

PHP = r'''
require getcwd().'/src/Application/Compliance/NormativeApplicability.php';
require getcwd().'/src/Application/Compliance/NormativeMatrix.php';
require getcwd().'/src/Application/Compliance/ComplianceEvidenceEvaluation.php';
require getcwd().'/src/Application/Compliance/ComplianceAttentionProjection.php';
require getcwd().'/src/Application/Documents/DocumentDossierVersions.php';
require getcwd().'/src/Application/Documents/DocumentApprovalRetention.php';
require getcwd().'/src/Application/Risk/RiskRegisterControls.php';
require getcwd().'/src/Application/Risk/RiskIncidentTreatment.php';
require getcwd().'/src/Application/Quality/QualityNonconformityActions.php';
$input=json_decode(stream_get_contents(STDIN),true,32,JSON_THROW_ON_ERROR);
$p=$input['payload'];
try {
  $result=match($input['operation']) {
    'rule' => \App\Application\Compliance\NormativeApplicability::evaluate($p['rule'],$p['context'],$p['as_of']),
    'matrix' => \App\Application\Compliance\NormativeMatrix::evaluate($p['rules'],$p['context'],$p['as_of']),
    'evidence' => \App\Application\Compliance\ComplianceEvidenceEvaluation::project($p),
    'attention' => \App\Application\Compliance\ComplianceAttentionProjection::project($p['projection'],$p['deadlines'],$p['as_of']),
    'dossier' => \App\Application\Documents\DocumentDossierVersions::project($p),
    'approval' => \App\Application\Documents\DocumentApprovalRetention::project($p),
    'risk' => \App\Application\Risk\RiskRegisterControls::project($p,$p['tenant_ref']),
    'incident' => \App\Application\Risk\RiskIncidentTreatment::project($p,$p['tenant_ref'],$p['as_of']),
    'quality' => \App\Application\Quality\QualityNonconformityActions::project($p,$p['tenant_ref'],$p['as_of']),
    default => ['exception'=>'InvalidOperation'],
  };
} catch (\Throwable $e) {
  $result=['exception'=>get_class($e)];
}
echo json_encode($result,JSON_THROW_ON_ERROR);
'''


def php_call(operation: str, payload: dict) -> dict:
    """Execute the actual repository classes, never a reimplementation."""
    if not shutil.which("php"):
        raise AssertionError("PHP CLI is needed to verify real PHP behavior")
    result = subprocess.run(
        ["php", "-d", "display_errors=stderr", "-r", PHP],
        cwd=ROOT,
        input=json.dumps({"operation": operation, "payload": payload}),
        text=True,
        stdout=subprocess.PIPE,
        stderr=subprocess.PIPE,
        timeout=20,
        check=False,
    )
    if result.returncode != 0:
        raise AssertionError(
            f"PHP failed, code={result.returncode}: {result.stderr[:1200]}"
        )
    return json.loads(result.stdout)


def normative() -> tuple[dict, dict]:
    context = dict(
        ciiu="c0101", municipio="bogota", regimen="ordinario",
        riesgo="bajo", sector="servicios", tamano_empresa="pequena",
        tenant_ref="tenant-demo",
    )
    rule = dict(
        approved=True, conditions={"ciiu": "c0101"},
        effective_from="2026-01-01", effective_until="2026-12-31",
        kind="OBLIGATORIO", reviewer_ref="reviewer-demo",
        source=dict(
            article="art-1", issuer="emisor", kind="official",
            official_url="https://norma.invalid/fuente/uno", version="v1",
        ),
        tenant_ref="tenant-demo", verified_on="2026-02-01",
    )
    return rule, context


def evidence() -> dict:
    return dict(
        tenant_ref="fixture:tenant/uno", company_ref="fixture:company/uno",
        reference_date="2026-06-01", evidence=[],
        obligations=[dict(
            tenant_ref="fixture:tenant/uno",
            company_ref="fixture:company/uno",
            obligation_ref="fixture:obligation/uno",
            source_ref="fixture:source/uno",
            effective_from="2026-01-01", effective_until="2026-12-31",
        )],
    )


def dossiers() -> dict:
    return dict(
        tenant_ref="tenant-test-uno", company_ref="fixture:company/uno",
        as_of="2026-06-01",
        dossiers=[dict(
            tenant_ref="tenant-test-uno", company_ref="fixture:company/uno",
            ref="fixture:dossier/uno", entity_ref="fixture:entity/uno",
            classification="INTERNA",
            versions=[
                dict(ref="fixture:version/uno", number=1,
                     hash="fixture:sha256/" + "a" * 64, previous_ref=None),
                dict(ref="fixture:version/dos", number=2,
                     hash="fixture:sha256/" + "b" * 64,
                     previous_ref="fixture:version/uno"),
            ],
        )],
    )


def approval() -> dict:
    return dict(
        tenant_ref="tenant-test-uno", company_ref="fixture:company/uno",
        as_of="2026-06-01",
        records=[dict(
            tenant_ref="tenant-test-uno", company_ref="fixture:company/uno",
            ref="fixture:dossier/uno", classification="INTERNA",
            author_ref="fixture:actor/autor",
            events=[
                dict(action="REVISAR", actor_ref="fixture:actor/revisor",
                     role="revisor", at="2026-03-01"),
                dict(action="APROBAR", actor_ref="fixture:actor/aprobador",
                     role="aprobador", at="2026-03-02"),
            ],
        )],
    )


def risks() -> dict:
    return dict(
        tenant_ref="fixture:tenant/uno",
        risks=[dict(
            tenant_ref="fixture:tenant/uno", risk_ref="fixture:risk/uno",
            category="legal", probability=5, impact=4, state="PENDIENTE",
        )],
        controls=[dict(
            tenant_ref="fixture:tenant/uno",
            control_ref="fixture:risk-control/uno",
            risk_ref="fixture:risk/uno",
            effectiveness=50, state="PENDIENTE",
        )],
    )


def incidents() -> dict:
    return dict(
        tenant_ref="fixture:tenant/uno", as_of="2026-06-01",
        incidents=[dict(
            tenant_ref="fixture:tenant/uno",
            incident_ref="fixture:risk-incident/uno",
            risk_ref="fixture:risk/uno", state="PENDIENTE",
        )],
        plans=[dict(
            tenant_ref="fixture:tenant/uno",
            plan_ref="fixture:risk-plan/uno",
            incident_ref="fixture:risk-incident/uno",
            owner_ref="fixture:responsible/uno",
            due_on="2026-05-01", state="PENDIENTE",
        )],
    )


def quality() -> dict:
    return dict(
        tenant_ref="fixture:tenant/uno", as_of="2026-06-01",
        nonconformities=[dict(
            tenant_ref="fixture:tenant/uno",
            nonconformity_ref="fixture:quality-nc/uno",
            finding_ref="fixture:quality-finding/uno",
            cause_ref="fixture:quality-cause/uno",
            cause_state="PENDIENTE", state="PENDIENTE",
        )],
        actions=[dict(
            tenant_ref="fixture:tenant/uno",
            action_ref="fixture:quality-action/uno",
            nonconformity_ref="fixture:quality-nc/uno",
            kind="CORRECTIVA", owner_ref="fixture:responsible/uno",
            due_on="2026-05-01", state="PENDIENTE",
            verification_state="PENDIENTE",
        )],
    )


class CoberturaCondor14Tests(unittest.TestCase):
    def test_contrato_principal(self) -> None:
        """Call real regulatory, dossier, review, risk and quality code."""
        rule, context = normative()
        rule_result = php_call(
            "rule", dict(rule=rule, context=context, as_of="2026-06-01")
        )
        self.assertEqual(("OBLIGATORIO", "APLICA"),
                         (rule_result["kind"], rule_result["applicability"]))
        self.assertEqual(rule["source"], rule_result["source"])
        matrix = php_call(
            "matrix", dict(rules=[rule, {**rule, "tenant_ref": "tenant-otro"}],
                           context=context, as_of="2026-06-01")
        )
        self.assertEqual(1, len(matrix["rows"]))
        self.assertEqual("OBLIGATORIO", matrix["rows"][0]["kind"])

        obligations = php_call("evidence", evidence())
        self.assertEqual("SIN_EVIDENCIA", obligations["rows"][0]["state"])
        self.assertEqual("SIN_EVIDENCIA_VERIFICADA",
                         obligations["pending_evaluations"][0]["reason"])
        self.assertFalse(obligations["automatic_approval"])
        self.assertFalse(obligations["automatic_closure"])

        versions = php_call("dossier", dossiers())
        self.assertTrue(versions["valid"])
        self.assertEqual(
            [1, 2], [v["number"] for v in versions["dossiers"][0]["versions"]]
        )
        self.assertEqual(
            "fixture:version/uno",
            versions["dossiers"][0]["versions"][1]["previous_ref"],
        )
        self.assertFalse(versions["can_approve"])

        documents = php_call("approval", approval())
        self.assertTrue(documents["valid"])
        self.assertEqual("APROBADO_EXPLICITO", documents["records"][0]["state"])
        self.assertEqual("fixture:actor/aprobador",
                         documents["records"][0]["approver_ref"])
        self.assertEqual(730, documents["records"][0]["retention_proposal_days"])
        self.assertFalse(documents["records"][0]["retention_is_legal_policy"])
        self.assertFalse(documents["automatic_approval"])
        self.assertFalse(documents["automatic_deletion"])

        risk = php_call("risk", risks())
        self.assertTrue(risk["structurally_reviewable"])
        self.assertEqual((20, 10), (risk["risks"][0]["inherent_score"],
                                    risk["risks"][0]["residual_score"]))
        self.assertTrue(risk["requires_professional_review"])
        incident = php_call("incident", incidents())
        self.assertTrue(incident["structurally_reviewable"])
        self.assertTrue(incident["plans"][0]["follow_up_overdue"])
        q = php_call("quality", quality())
        self.assertTrue(q["structurally_reviewable"])
        self.assertTrue(q["actions"][0]["follow_up_overdue"])
        self.assertFalse(q["actions"][0]["verified"])

        # No valid F1b review/source means no actionable, automated alert.
        attention = php_call(
            "attention", dict(projection={"tenant_ref": "tenant-demo",
                                         "municipio": "bogota",
                                         "rows": [{"state": "PENDIENTE"}]},
                              deadlines=[], as_of="2026-06-01"),
        )
        self.assertEqual("REQUIERE_REVISION", attention["rows"][0]["state"])
        self.assertFalse(attention["rows"][0]["attention_candidate"])

    def test_falla_cerrado(self) -> None:
        """Unknown sources, foreign scopes and wrong reviewers never approve."""
        rule, context = normative()
        for changes in (
            {"approved": False},
            {"tenant_ref": "tenant-otro"},
            {"source": {**rule["source"], "kind": "unverified"}},
        ):
            with self.subTest(changes=changes):
                found = php_call(
                    "rule", dict(rule={**rule, **changes}, context=context,
                                 as_of="2026-06-01"),
                )
                self.assertEqual("REQUIERE_REVISION", found["kind"])
                self.assertIsNone(found["source"])
        missing = php_call("rule", dict(rule=rule, context={},
                                        as_of="2026-06-01"))
        self.assertEqual("REQUIERE_REVISION", missing["kind"])

        item = evidence()
        item["evidence"] = [dict(
            tenant_ref=item["tenant_ref"], company_ref=item["company_ref"],
            evidence_ref="fixture:evidence/uno",
            obligation_ref="fixture:obligation/uno",
            issued_on="2026-03-01", valid_until="2026-12-01",
            review_state="APROBADA", reviewed_on=None, reviewer_ref=None,
        )]
        denied = php_call("evidence", item)
        self.assertEqual("InvalidArgumentException", denied["exception"])

        broken = dossiers()
        broken["dossiers"][0]["versions"][1]["previous_ref"] = None
        self.assertFalse(php_call("dossier", broken)["valid"])
        foreign = dossiers()
        foreign["dossiers"][0]["tenant_ref"] = "tenant-test-otro"
        self.assertEqual([], php_call("dossier", foreign)["dossiers"])

        wrong_actor = approval()
        wrong_actor["records"][0]["events"][1]["actor_ref"] = "fixture:actor/revisor"
        self.assertFalse(php_call("approval", wrong_actor)["valid"])
        foreign_document = approval()
        foreign_document["records"][0]["company_ref"] = "fixture:company/otro"
        self.assertEqual([], php_call("approval", foreign_document)["records"])

        invalid_risk = risks()
        invalid_risk["controls"][0]["tenant_ref"] = "fixture:tenant/otro"
        self.assertFalse(php_call("risk", invalid_risk)["structurally_reviewable"])
        self.assertEqual("REQUIERE_REVISION",
                         php_call("risk", invalid_risk)["state"])

        invalid_incident = incidents()
        invalid_incident["plans"][0]["owner_ref"] = "nombre-real"
        self.assertFalse(
            php_call("incident", invalid_incident)["structurally_reviewable"]
        )
        invalid_quality = quality()
        invalid_quality["actions"][0]["verification_state"] = "CUMPLE"
        rejected = php_call("quality", invalid_quality)
        self.assertFalse(rejected["structurally_reviewable"])
        self.assertEqual("REQUIERE_REVISION", rejected["state"])

    def test_sin_datos_reales(self) -> None:
        """AC-03: local fixture-only execution, and no full-workflow claims."""
        expected = {
            "GEST-FLUJO-1-P01", "GEST-FLUJO-1-P02", "GEST-FLUJO-1-P03",
            "GEST-FLUJO-1-P04", "GEST-FLUJO-1-P05", "GEST-FLUJO-2",
            "GEST-FLUJO-2-P01", "GEST-FLUJO-2-P02", "GEST-FLUJO-2-P03",
            "GEST-FLUJO-2-P04", "GEST-FLUJO-3", "GEST-FLUJO-3-P01",
        }
        self.assertEqual(expected, set(OUTSIDE_FULL_COVERAGE))
        self.assertTrue(all(x.startswith(("B:", "C:", "D:"))
                            for x in OUTSIDE_FULL_COVERAGE.values()))
        self.assertTrue(all("fixture:" in json.dumps(x)
                            for x in (evidence(), dossiers(), approval(),
                                      risks(), incidents(), quality())))
        # Re-run a true production class with synthetic inputs, no service I/O.
        risk = php_call("risk", risks())
        self.assertTrue(risk["synthetic_only"])
        self.assertTrue(risk["requires_professional_review"])
        self.assertNotIn("health", json.dumps(risk))
        self.assertFalse(php_call("approval", approval())["can_close"])


if __name__ == "__main__":
    unittest.main()
