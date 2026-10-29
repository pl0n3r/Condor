#!/usr/bin/env python3
"""Condor #1336: pruebas conductuales del preview sintético GEST-M02.

GEST-M01-S09/S10/S11 se clasifican D: incapacidad, evaluación y portal
laboral no están implementados. No acredita contrato real ni valor legal.
"""
from __future__ import annotations

import copy
import json
import shutil
import subprocess
import unittest
from pathlib import Path

ROOT = Path(__file__).resolve().parents[1]
TEMPLATES = ROOT / "src/Application/Contracts/ContractTemplatesProjection.php"
LIFECYCLE = ROOT / "src/Application/Contracts/ContractLifecycleProjection.php"
CI = ROOT / ".github/workflows/ci.yml"
TENANT = "tenant-test-alpha"
COMPANY = "fixture:company/alpha"
REVIEWER = "fixture:reviewer/qa"
CONTRACT = "fixture:contract/draft_a"

PHP = r'''
require $argv[1];
require $argv[2];
$ops = json_decode($argv[3], true, 32, JSON_THROW_ON_ERROR);
$results = [];
foreach ($ops as $op) {
    if ($op['kind'] === 'templates') {
        $results[] = \App\Application\Contracts\ContractTemplatesProjection::project($op['data']);
    } elseif ($op['kind'] === 'lifecycle') {
        $results[] = \App\Application\Contracts\ContractLifecycleProjection::project($op['data']);
    } else {
        throw new \InvalidArgumentException('Unsupported synthetic projection');
    }
}
echo json_encode($results, JSON_THROW_ON_ERROR);
'''


def run(operations: list[tuple[str, dict]]) -> list[dict]:
    php = shutil.which("php")
    if not php or not TEMPLATES.is_file() or not LIFECYCLE.is_file():
        raise AssertionError("UNKNOWN: falta PHP o código real de contratación")
    payload = [{"kind": kind, "data": data} for kind, data in operations]
    process = subprocess.run(
        [php, "-n", "-d", "allow_url_fopen=0", "-r", PHP,
         str(TEMPLATES), str(LIFECYCLE), json.dumps(payload)],
        cwd=ROOT, capture_output=True, text=True, timeout=20, check=False,
    )
    if process.returncode != 0:
        raise AssertionError("UNKNOWN: error PHP: " + process.stderr[-1200:])
    results = json.loads(process.stdout)
    if not isinstance(results, list) or len(results) != len(operations):
        raise AssertionError("UNKNOWN: respuesta ambigua")
    return results


def scope(**extra: object) -> dict:
    return {"tenant_ref": TENANT, "company_ref": COMPANY, **extra}


def templates() -> dict:
    return scope(
        templates=[scope(ref="fixture:template/baseline", reviewer_ref=REVIEWER, version=2)],
        contracts=[scope(ref=CONTRACT, template_ref="fixture:template/baseline",
                         template_version=2, worker_ref="fixture:worker/alpha")],
        addenda=[scope(ref="fixture:addendum/shift_a", contract_ref=CONTRACT,
                       reason_key="SCOPE_CHANGE")],
    )


def lifecycle() -> dict:
    return scope(
        reference_date="2026-10-29",
        contracts=[scope(ref=CONTRACT, worker_ref="fixture:worker/alpha",
                         start_on="2026-01-01", end_on="2026-11-01")],
        signature_evidence=[scope(ref="fixture:signature-evidence/sample_a",
                                  contract_ref=CONTRACT, reviewer_ref=REVIEWER,
                                  captured_on="2026-02-01")],
        renewals=[scope(ref="fixture:renewal/proposal_a", contract_ref=CONTRACT,
                        reviewer_ref=REVIEWER, proposed_end_on="2027-01-31")],
        terminations=[scope(ref="fixture:termination/draft_a", contract_ref=CONTRACT,
                            reviewer_ref=REVIEWER, effective_on="2026-11-01",
                            reason_key="TERM_REVIEW")],
        settlement_drafts=[scope(ref="fixture:settlement-draft/preview_a",
                                 termination_ref="fixture:termination/draft_a",
                                 reviewer_ref=REVIEWER)],
    )


class CoberturaCondor17Tests(unittest.TestCase):
    def test_contrato_principal(self) -> None:
        """GEST-M02 y S01-S08: ejecutar nueve contratos reales del preview."""
        template, life = run([("templates", templates()), ("lifecycle", lifecycle())])
        # GEST-M02: ninguna prueba certifica aprobación legal.
        self.assertEqual((True, "REQUIERE_REVISION"),
                         (template["valid"], template["state"]))
        self.assertEqual((True, "REQUIERE_REVISION"),
                         (life["valid"], life["state"]))
        self.assertEqual((TENANT, COMPANY), (life["tenant_ref"], life["company_ref"]))
        # GEST-M02-S01: plantilla versionada, con revisor y revisión obligatoria.
        self.assertEqual((2, REVIEWER, True),
                         (template["templates"][0]["version"],
                          template["templates"][0]["reviewer_ref"],
                          template["templates"][0]["review_required"]))
        # GEST-M02-S02: contrato en borrador, jamás firma verificada.
        self.assertEqual((CONTRACT, False),
                         (template["contracts"][0]["ref"],
                          template["contracts"][0]["signature_validated"]))
        # GEST-M02-S03: otrosí asociado, requiere revisión.
        self.assertEqual((CONTRACT, "REQUIERE_REVISION"),
                         (template["addenda"][0]["contract_ref"],
                          template["addenda"][0]["state"]))
        # GEST-M02-S04: evidencia ficticia distinta de firma válida.
        self.assertEqual((True, False),
                         (life["signature_evidence"][0]["synthetic_evidence_only"],
                          life["signature_evidence"][0]["signature_validated"]))
        # GEST-M02-S05: renovación propuesta sin aplicar ni avisar.
        self.assertEqual((False, False),
                         (life["renewals"][0]["applied"],
                          life["renewals"][0]["notification_sent"]))
        # GEST-M02-S06: fecha cercana solo propone atención.
        self.assertIs(life["contracts"][0]["renewal_attention_candidate"], True)
        # GEST-M02-S07: terminación no cierra un contrato.
        self.assertIs(life["terminations"][0]["contract_closed"], False)
        self.assertIs(life["contracts"][0]["closed"], False)
        # GEST-M02-S08: liquidación ficticia, sin monto ni efectos jurídicos.
        settlement = life["settlement_drafts"][0]
        self.assertEqual(("SIN_VALOR_LEGAL", False, False, None),
                         (settlement["notice"], settlement["legal_value"],
                          settlement["approved"], settlement["amount_cop"]))

    def test_falla_cerrado(self) -> None:
        """Tenant/entidad, versiones, referencias y datos inválidos: rechazo."""
        cases: list[tuple[str, dict]] = []

        def reject(kind: str, original: dict, edit) -> None:
            data = copy.deepcopy(original)
            edit(data)
            cases.append((kind, data))

        t, l = templates(), lifecycle()
        reject("templates", t, lambda d: d.update(tenant_ref="foreign-tenant"))
        reject("templates", t, lambda d: d["templates"][0].update(company_ref="fixture:company/foreign"))
        reject("templates", t, lambda d: d["templates"][0].update(reviewer_ref=""))
        reject("templates", t, lambda d: d["contracts"][0].update(template_version=999))
        reject("templates", t, lambda d: d["addenda"][0].update(contract_ref="fixture:contract/other"))
        reject("templates", t, lambda d: d["contracts"][0].update(state="APROBADO"))
        reject("lifecycle", l, lambda d: d["signature_evidence"][0].update(tenant_ref="tenant-test-foreign"))
        reject("lifecycle", l, lambda d: d["signature_evidence"][0].update(captured_on="2025-01-01"))
        reject("lifecycle", l, lambda d: d["renewals"][0].update(proposed_end_on="2026-10-01"))
        reject("lifecycle", l, lambda d: d["terminations"][0].update(reason_key="AUTO_CLOSE"))
        reject("lifecycle", l, lambda d: d["settlement_drafts"][0].update(termination_ref="fixture:termination/other"))
        reject("lifecycle", l, lambda d: d.update(reference_date="2026-02-30"))
        reject("templates", t, lambda d: d["templates"].append(copy.deepcopy(d["templates"][0])))
        reject("lifecycle", l, lambda d: d["terminations"][0].update(company_ref="fixture:company/foreign"))
        reject("lifecycle", l, lambda d: d["settlement_drafts"][0].update(reviewer_ref=""))
        results = run(cases)
        for i, result in enumerate(results):
            with self.subTest(case=i):
                self.assertEqual((False, "REQUIERE_REVISION", "invalid_synthetic_fixture"),
                                 (result["valid"], result["state"], result["error"]))
                self.assertEqual([], result["contracts"])
                self.assertIsNone(result["tenant_ref"])

    def test_sin_datos_reales(self) -> None:
        """Datos ficticios, sin red ni persistencia ni claves privadas."""
        self.assertTrue(TEMPLATES.is_file() and LIFECYCLE.is_file())
        self.assertTrue(TENANT.startswith("tenant-test-"))
        self.assertTrue(all(ref.startswith("fixture:") for ref in
                            (COMPANY, REVIEWER, CONTRACT)))
        source = Path(__file__).read_text(encoding="utf-8")
        self.assertNotIn("-----BEGIN PRIVATE KEY-----", source)
        self.assertNotIn("import requests", source)
        self.assertNotIn("import socket", source)
        self.assertIn('"allow_url_fopen=0"', source)
        self.assertIn('"-n"', source)
        states = run([("templates", templates()), ("lifecycle", lifecycle())])
        self.assertTrue(all(row["valid"] for row in states))
        self.assertTrue(all(row["state"] == "REQUIERE_REVISION" for row in states))

    def test_gate_validar_presente(self) -> None:
        """AC-04: existe el gate canónico; GitHub verifica success exact-HEAD."""
        workflow = CI.read_text(encoding="utf-8")
        self.assertRegex(workflow, r"(?m)^  validar:\s*$")
        self.assertRegex(workflow, r"(?m)^    name: Validar\s*$")
        self.assertIn("pull_request:", workflow)


if __name__ == "__main__":
    unittest.main()
