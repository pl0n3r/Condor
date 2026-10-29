#!/usr/bin/env python3
"""Condor #1221: five GEST-FLUJO gaps, exercised with synthetic PHP contracts.

A local guard is not evidence of legal hold, risk/audit operations, human
approval, retention, external integrations or production. Such claims are UNKNOWN.
"""
from __future__ import annotations

import ast
from functools import lru_cache
import json
from pathlib import Path
import re
import shutil
import subprocess
import unittest

ROOT = Path(__file__).resolve().parents[1]
MATRIX = "docs/matriz-cobertura.md"
SPEC = "docs/gestion-empresarial-integral.md"
DOC = "src/Application/Documents/DocumentApiService.php"
RISK = "src/Application/Risk/RiskRegisterControls.php"
INCIDENT = "src/Application/Risk/RiskIncidentTreatment.php"
QUALITY = "src/Application/Quality/QualityNonconformityActions.php"
EFFICACY = "src/Application/Quality/QualityIndicatorsEfficacy.php"
SOURCES = (MATRIX, SPEC, DOC, RISK, INCIDENT, QUALITY, EFFICACY)
UNKNOWN = "UNKNOWN"
GAP = "BRECHA_DOCUMENTADA"
CLAUSES = {
    "GEST-FLUJO-2-P04": (
        "Vencimiento, retención, exportación o eliminación siguen política y legal hold; no existe borrado silencioso.",
        "4. Vencimiento, retención, exportación o eliminación siguen política y legal hold; no existe borrado silencioso.",
        "prueba de integridad, exportación autorizada y retención/reversión",
    ),
    "GEST-FLUJO-3": (
        "Riesgo, calidad y mejora",
        "### 3. Riesgo, calidad y mejora",
        "prueba de umbral negativo, escalamiento, auditoría e idempotencia",
    ),
    "GEST-FLUJO-3-P01": (
        "Se registra riesgo, incidente o no conformidad con contexto y severidad.",
        "1. Se registra riesgo, incidente o no conformidad con contexto y severidad.",
        "evidencia de ejecución sobre SHA exacto, resultado terminal y prueba de fail-closed",
    ),
    "GEST-FLUJO-3-P02": (
        "Se asignan controles y un responsable; la evaluación registra evidencia, no solo una marca.",
        "2. Se asignan controles y un responsable; la evaluación registra evidencia, no solo una marca.",
        "regresión de denegación cross-tenant/entidad/sede y prueba de privilegio mínimo",
    ),
    "GEST-FLUJO-3-P03": (
        "Una acción correctiva tiene plazo, verificación de eficacia y cierre aprobado.",
        "3. Una acción correctiva tiene plazo, verificación de eficacia y cierre aprobado.",
        "evidencia de ejecución sobre SHA exacto, resultado terminal y prueba de fail-closed",
    ),
}
# No green assertion is permitted for these externally unverified obligations.
UNPROVEN = {
    "GEST-FLUJO-2-P04": "No retention, legal-hold or authorized export/delete implementation evidenced.",
    "GEST-FLUJO-3": "No end-to-end audit trail, escalation or idempotent workflow evidenced.",
    "GEST-FLUJO-3-P01": "No real incident registration, approval, persistence or exact-SHA production evidence.",
    "GEST-FLUJO-3-P02": "No cross-entity/branch proof of responsible controls or real evaluations.",
    "GEST-FLUJO-3-P03": "No approved closure or verified efficacy; synthetic actions remain pending.",
}


def sources() -> dict[str, str]:
    return {p: (ROOT / p).read_text(encoding="utf-8") for p in SOURCES}


def matrix_rows(raw: object) -> dict[str, tuple[str, ...]]:
    """Malformed or duplicate IDs fail closed, including damaged separators."""
    if type(raw) is not str:
        raise ValueError("UNKNOWN: unreadable matrix")
    found: dict[str, tuple[str, ...]] = {}
    for line in raw.splitlines():
        if not line.startswith("| " + chr(96) + "GEST-FLUJO-"):
            continue
        cells = tuple(c.strip() for c in line.strip("|").split("|"))
        match = re.fullmatch(r"^" + re.escape(chr(96)) +
                             r"(GEST-FLUJO-(?:2-P04|3(?:-P0[123])?))" +
                             re.escape(chr(96)) + r"(?: .*)?$", cells[0])
        if not match:
            # Other rows are legitimate, but a damaged target header is not.
            for key in CLAUSES:
                if cells[0].startswith(chr(96) + key + chr(96)) or cells[0].startswith(chr(96) + key + " "):
                    raise ValueError("UNKNOWN: malformed target ID")
            continue
        key = match.group(1)
        clause, _line, evidence = CLAUSES[key]
        if (key in found or len(cells) != 5
                or cells[0] != f"{chr(96)}{key}{chr(96)} · {chr(96)}{SPEC}{chr(96)}"
                or cells[1] != clause
                or cells[2] != f"{chr(96)}NO_VERIFICABLE{chr(96)}"
                or f"Falta vincular para {key} " not in cells[3]
                or evidence not in cells[3]
                or "no se identificó tríada código+prueba+PR fusionado" not in cells[3]
                or cells[4] != "#802"):
            raise ValueError("UNKNOWN: invalid or duplicate coverage row " + key)
        found[key] = cells
    if set(found) != set(CLAUSES):
        raise ValueError("UNKNOWN: missing rows")
    return found


def normative_spec(raw: object) -> bool:
    return type(raw) is str and all(
        raw.splitlines().count(section_line) == 1
        for _clause, section_line, _evidence in CLAUSES.values()
    ) and all(phrase in raw for phrase in (
        "legal hold", "no existe borrado silencioso",
        "verificación de eficacia", "cierre aprobado",
        "Un documento cargado no demuestra cumplimiento",
    ))


# Execute audited PHP classes with in-memory fixtures only; no framework, SQL,
# network, providers, secrets, tenant credentials, or destructive operations.
PHP_PROBE = r'''
require 'src/Application/Documents/DocumentApiService.php';
require 'src/Application/Risk/RiskRegisterControls.php';
require 'src/Application/Risk/RiskIncidentTreatment.php';
require 'src/Application/Quality/QualityNonconformityActions.php';
require 'src/Application/Quality/QualityIndicatorsEfficacy.php';
use App\Application\Documents\DocumentApiService as D;
use App\Application\Risk\RiskRegisterControls as R;
use App\Application\Risk\RiskIncidentTreatment as I;
use App\Application\Quality\QualityNonconformityActions as Q;
use App\Application\Quality\QualityIndicatorsEfficacy as E;
function denied(callable $f): bool {
  try {$f(); return false;} catch (\DomainException $e) {return true;}
}
$tenant='fixture:tenant/company-a';
$other='fixture:tenant/company-b';
$scope='tenant-a|entity-a|branch-a';
$d=new D();
$created=$d->create([],$scope,'fixture:doc/local','INTERNA');
$state=$created['state'];
$review=$d->requestReview($state,$scope,'fixture:doc/local');
$again=$d->requestReview($review['state'],$scope,'fixture:doc/local');
$doc=$d->detail($review['state'],$scope,'fixture:doc/local');
$risk=[
 'tenant_ref'=>$tenant,
 'risks'=>[[
  'tenant_ref'=>$tenant,'risk_ref'=>'fixture:risk/operational',
  'category'=>'operativo','probability'=>4,'impact'=>5,'state'=>'PENDIENTE'
 ]],
 'controls'=>[[
  'tenant_ref'=>$tenant,'control_ref'=>'fixture:risk-control/procedure',
  'risk_ref'=>'fixture:risk/operational',
  'effectiveness'=>50,'state'=>'PENDIENTE'
 ]]
];
$foreign=$risk; $foreign['controls'][0]['tenant_ref']=$other;
$badScore=$risk; $badScore['risks'][0]['impact']=0;
$dupe=$risk; $dupe['controls'][]=$dupe['controls'][0];
$incident=[
 'tenant_ref'=>$tenant,
 'incidents'=>[[
  'tenant_ref'=>$tenant,'incident_ref'=>'fixture:risk-incident/event',
  'risk_ref'=>'fixture:risk/operational','state'=>'PENDIENTE'
 ]],
 'plans'=>[[
  'tenant_ref'=>$tenant,'plan_ref'=>'fixture:risk-plan/followup',
  'incident_ref'=>'fixture:risk-incident/event',
  'owner_ref'=>'fixture:responsible/team-a',
  'due_on'=>'2026-10-01','state'=>'PENDIENTE'
 ]]
];
$incidentForeign=$incident; $incidentForeign['plans'][0]['tenant_ref']=$other;
$quality=[
 'tenant_ref'=>$tenant,
 'nonconformities'=>[[
  'tenant_ref'=>$tenant,'nonconformity_ref'=>'fixture:quality-nc/nc',
  'finding_ref'=>'fixture:quality-finding/finding',
  'cause_ref'=>'fixture:quality-cause/root',
  'cause_state'=>'PENDIENTE','state'=>'PENDIENTE'
 ]],
 'actions'=>[[
  'tenant_ref'=>$tenant,'action_ref'=>'fixture:quality-action/fix',
  'nonconformity_ref'=>'fixture:quality-nc/nc','kind'=>'CORRECTIVA',
  'owner_ref'=>'fixture:responsible/team-a','due_on'=>'2026-10-02',
  'state'=>'PENDIENTE','verification_state'=>'PENDIENTE'
 ]]
];
$qualityForeign=$quality; $qualityForeign['actions'][0]['tenant_ref']=$other;
$qualityFake=$quality; $qualityFake['actions'][0]['verification_state']='APROBADO';
$efficacy=[
 'tenant_ref'=>$tenant,
 'indicators'=>[[
  'tenant_ref'=>$tenant,'indicator_ref'=>'fixture:quality-indicator/sla',
  'goal'=>90,'previous'=>85,'current'=>80,'state'=>'PENDIENTE'
 ]],
 'audits'=>[[
  'tenant_ref'=>$tenant,'audit_ref'=>'fixture:quality-audit/check',
  'auditor_ref'=>'fixture:quality-auditor/lead',
  'scheduled_on'=>'2026-10-01','state'=>'PENDIENTE'
 ]],
 'actions'=>[[
  'tenant_ref'=>$tenant,'action_ref'=>'fixture:quality-action/fix',
  'source_closed'=>true,
  'closure_evidence_ref'=>'fixture:quality-evidence/external'
 ]],
 'verifications'=>[[
  'tenant_ref'=>$tenant,'verification_ref'=>'fixture:quality-verification/verify',
  'action_ref'=>'fixture:quality-action/fix',
  'evidence_ref'=>'fixture:quality-evidence/proof',
  'reviewer_ref'=>'fixture:quality-reviewer/lead','state'=>'PENDIENTE'
 ]]
];
$efficacyForeign=$efficacy; $efficacyForeign['verifications'][0]['tenant_ref']=$other;
echo json_encode([
 'document'=>$doc,
 'document_foreign_denied'=>denied(fn()=>$d->detail($review['state'],'tenant-b|entity-b|branch-b','fixture:doc/local')),
 'document_entity_denied'=>denied(fn()=>$d->detail($review['state'],'tenant-a|entity-b|branch-a','fixture:doc/local')),
 'document_branch_denied'=>denied(fn()=>$d->detail($review['state'],'tenant-a|entity-a|branch-b','fixture:doc/local')),
 'document_review_changed'=>$review['changed'],
 'document_again_changed'=>$again['changed'],
 'risk'=>R::project($risk,$tenant),
 'risk_again'=>R::project($risk,$tenant),
 'risk_foreign'=>R::project($foreign,$tenant),
 'risk_bad_score'=>R::project($badScore,$tenant),
 'risk_duplicate'=>R::project($dupe,$tenant),
 'incident'=>I::project($incident,$tenant,'2026-10-27'),
 'incident_foreign'=>I::project($incidentForeign,$tenant,'2026-10-27'),
 'quality'=>Q::project($quality,$tenant,'2026-10-27'),
 'quality_foreign'=>Q::project($qualityForeign,$tenant,'2026-10-27'),
 'quality_fake_approved'=>Q::project($qualityFake,$tenant,'2026-10-27'),
 'efficacy'=>E::project($efficacy,$tenant,'2026-10-27'),
 'efficacy_foreign'=>E::project($efficacyForeign,$tenant,'2026-10-27')
],JSON_THROW_ON_ERROR);
'''


@lru_cache(maxsize=1)
def exercise_php() -> dict[str, object]:
    php = shutil.which("php")
    if php is None:
        raise AssertionError("UNKNOWN: PHP CLI absent")
    result = subprocess.run(
        [php, "-n", "-d", "allow_url_fopen=0", "-r", PHP_PROBE],
        cwd=ROOT, text=True, capture_output=True, check=False, timeout=20,
    )
    if result.returncode or result.stderr.strip():
        raise AssertionError("UNKNOWN: offline PHP failed: " + result.stderr[-350:])
    try:
        parsed = json.loads(result.stdout)
    except ValueError as exc:
        raise AssertionError("UNKNOWN: malformed PHP result") from exc
    if type(parsed) is not dict:
        raise AssertionError("UNKNOWN: non-object PHP result")
    return parsed


def local_check(key: object, data: object, probe: object) -> str:
    """Document the verified local guard, never operational/legal acceptance."""
    if key not in CLAUSES or type(data) is not dict or type(probe) is not dict:
        return UNKNOWN
    if (set(data) != set(SOURCES)
            or any(type(data[p]) is not str or not data[p] for p in SOURCES)):
        return UNKNOWN
    try:
        matrix_rows(data[MATRIX])
    except ValueError:
        return UNKNOWN
    if not normative_spec(data[SPEC]):
        return UNKNOWN
    for path, symbol in (
        (DOC, "final class DocumentApiService"),
        (RISK, "final class RiskRegisterControls"),
        (INCIDENT, "final class RiskIncidentTreatment"),
        (QUALITY, "final class QualityNonconformityActions"),
        (EFFICACY, "final class QualityIndicatorsEfficacy"),
    ):
        if symbol not in data[path]:
            return UNKNOWN
    try:
        if key == "GEST-FLUJO-2-P04":
            doc = probe["document"]
            ok = (type(doc) is dict
                and set(doc) == {"reference", "classification", "status"}
                and doc["status"] == "PENDIENTE_REVISION"
                and probe["document_foreign_denied"] is True
                and probe["document_entity_denied"] is True
                and probe["document_branch_denied"] is True
                and probe["document_review_changed"] is True
                and probe["document_again_changed"] is False)
        elif key == "GEST-FLUJO-3":
            risk, indicator = probe["risk"], probe["efficacy"]
            ok = (risk == probe["risk_again"]
                and risk["structurally_reviewable"] is True
                and risk["risks"][0]["inherent_level"] == "ALTO"
                and indicator["indicators"][0]["target_observation"] == "BAJO_META_NUMERICA"
                and indicator["audits"][0]["attention_overdue"] is True
                and indicator["requires_professional_review"] is True)
        elif key == "GEST-FLUJO-3-P01":
            risk, incident = probe["risk"], probe["incident"]
            ok = (risk["risk_count"] == 1
                and risk["risks"][0]["inherent_score"] == 20
                and incident["incident_count"] == 1
                and incident["state"] == "PENDIENTE"
                and probe["risk_bad_score"]["structurally_reviewable"] is False
                and probe["risk_duplicate"]["structurally_reviewable"] is False)
        elif key == "GEST-FLUJO-3-P02":
            risk, incident, quality = probe["risk"], probe["incident"], probe["quality"]
            ok = (risk["risks"][0]["controls"][0]["effectiveness"] == 50
                and risk["risks"][0]["residual_score"] == 10
                and incident["plans"][0]["owner_ref"] == "fixture:responsible/team-a"
                and incident["plans"][0]["follow_up_overdue"] is True
                and quality["actions"][0]["owner_ref"] == "fixture:responsible/team-a"
                and all(probe[name]["structurally_reviewable"] is False for name in (
                    "risk_foreign", "incident_foreign", "quality_foreign", "efficacy_foreign"
                )))
        else:
            quality, indicator = probe["quality"], probe["efficacy"]
            action = quality["actions"][0]
            ok = (action["kind"] == "CORRECTIVA"
                and action["follow_up_overdue"] is True
                and action["verification_state"] == "PENDIENTE"
                and action["verified"] is False
                and indicator["verifications"][0]["efficacy_state"] == "REQUIERE_REVISION"
                and probe["quality_fake_approved"]["structurally_reviewable"] is False)
    except (KeyError, IndexError, TypeError, AttributeError):
        return UNKNOWN
    return GAP if ok else UNKNOWN


def operational_state(_key: object, _evidence: object = None) -> str:
    """No synthetic result can authorize legal retention or approved closure."""
    return UNKNOWN


class BrechasCondor26Tests(unittest.TestCase):
    def test_contrato_principal(self) -> None:
        data, proof = sources(), exercise_php()
        self.assertEqual(len(CLAUSES), 5)
        self.assertEqual(set(matrix_rows(data[MATRIX])), set(CLAUSES))
        self.assertTrue(normative_spec(data[SPEC]))
        for key in CLAUSES:
            with self.subTest(clause=key):
                self.assertEqual(GAP, local_check(key, data, proof))
                self.assertEqual(UNKNOWN, operational_state(key, proof))
                self.assertTrue(UNPROVEN[key])

    def test_falla_cerrado(self) -> None:
        data, proof = sources(), exercise_php()
        for key in CLAUSES:
            row = next(x for x in data[MATRIX].splitlines()
                       if x.startswith("| " + chr(96) + key + chr(96) + " · "))
            damaged = (
                data[MATRIX].replace(row, "", 1),
                data[MATRIX] + "\n" + row,
                data[MATRIX] + "\n| " + chr(96) + key + chr(96) + " | malformed |",
                data[MATRIX].replace(row, row.replace("NO_VERIFICABLE", "CUBIERTO"), 1),
            )
            for altered in damaged:
                with self.subTest(key=key):
                    with self.assertRaisesRegex(ValueError, UNKNOWN):
                        matrix_rows(altered)
                    self.assertEqual(UNKNOWN, local_check(key, dict(data, **{MATRIX: altered}), proof))
            wrong = dict(data)
            wrong[SPEC] = wrong[SPEC].replace(CLAUSES[key][1], "", 1)
            self.assertEqual(UNKNOWN, local_check(key, wrong, proof))
            self.assertEqual(UNKNOWN, local_check(key, {}, proof))
        for key, field in (
            ("GEST-FLUJO-2-P04", "document"),
            ("GEST-FLUJO-3", "efficacy"),
            ("GEST-FLUJO-3-P01", "risk"),
            ("GEST-FLUJO-3-P02", "incident"),
            ("GEST-FLUJO-3-P03", "quality"),
        ):
            with self.subTest(probe=key):
                malformed = dict(proof)
                malformed[field] = None
                self.assertEqual(UNKNOWN, local_check(key, data, malformed))
        self.assertEqual(UNKNOWN, local_check("GEST-FLUJO-8", data, proof))
        for key in CLAUSES:
            self.assertEqual(UNKNOWN, operational_state(key, {"ci": "success"}))

    def test_sin_datos_reales(self) -> None:
        source = (ROOT / "tests/test_brechas_condor_26.py").read_text(encoding="utf-8")
        tree = ast.parse(source)
        imports = {alias.name.split(".")[0] for node in ast.walk(tree)
                   if isinstance(node, ast.Import) for alias in node.names}
        imports.update(node.module.split(".")[0] for node in ast.walk(tree)
                       if isinstance(node, ast.ImportFrom) and node.module)
        self.assertFalse(imports & {"requests", "urllib", "socket", "httpx", "pymysql", "mysql"})
        self.assertEqual(set(SOURCES), {MATRIX, SPEC, DOC, RISK, INCIDENT, QUALITY, EFFICACY})
        for token in ("curl_", "PDO", "file_get_contents", "file_put_contents",
                      "http://", "https://", "shell_exec", "APP_SECRET", "DATABASE_URL"):
            self.assertNotIn(token, PHP_PROBE)
        self.assertTrue(exercise_php()["risk"]["synthetic_only"])

    def test_estados_unknown_explicitos(self) -> None:
        data, proof = sources(), exercise_php()
        self.assertEqual(len(UNPROVEN), 5)
        for key in CLAUSES:
            self.assertEqual(GAP, local_check(key, data, proof))
            for evidence in (
                None, {}, True, {"sha": "f" * 40, "ci": "success"},
                {"approval": "APPROVED"}, {"retention": "applied"},
                {"state": "VALIDATED_IN_PRODUCTION", "sha": "a" * 40},
            ):
                with self.subTest(key=key, evidence=str(evidence)):
                    self.assertEqual(UNKNOWN, operational_state(key, evidence))
        self.assertFalse(proof["document_again_changed"])
        self.assertFalse(proof["quality"]["actions"][0]["verified"])
        self.assertEqual("REQUIERE_REVISION",
                         proof["efficacy"]["verifications"][0]["efficacy_state"])


if __name__ == "__main__":
    unittest.main()
