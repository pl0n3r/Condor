#!/usr/bin/env python3
"""Condor #1224: five GEST-LIM/NORM gaps, synthetic contracts and UNKNOWN.

These local assertions never certify medical isolation, legal validity, prices,
all integrations, a deployed system, or any production access.
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
APPLICABILITY = "src/Application/Compliance/NormativeApplicability.php"
NORM_MATRIX = "src/Application/Compliance/NormativeMatrix.php"
REVIEW = "src/Application/Compliance/NormativeReviewProjection.php"
DOCUMENT = "src/Application/Documents/DocumentApiService.php"
SOURCES = (MATRIX, SPEC, APPLICABILITY, NORM_MATRIX, REVIEW, DOCUMENT)
BT = chr(96)
UNKNOWN = "UNKNOWN"
LOCAL = "LOCAL_CONTRACT"
CLAUSES = {
    "GEST-LIM-04": (
        "No se guardan historias clínicas ocupacionales en el expediente administrativo. "
        "Los flujos médicos se limitan a conceptos de aptitud, recomendaciones y el mínimo "
        "metadato permitido, en un compartimento separado.",
        "regresión de denegación cross-tenant/entidad/sede y prueba de privilegio mínimo"),
    "GEST-LIM-05": (
        "No se crean cifras, fechas, tarifas, plazos ni obligaciones universales en código. "
        "Se versionan con fuente, vigencia, aplicabilidad y revisión responsable.",
        "prueba de montos, precedencias, moneda COP y rechazo de transacción inválida"),
    "GEST-NORM-01": (
        "Límites no negociables",
        "evidencia de ejecución sobre SHA exacto, resultado terminal y prueba de fail-closed"),
    "GEST-NORM-02": (
        "Modelo transversal: una sola fuente por concepto",
        "aserción de aceptación que ejercite el comportamiento exacto"),
    "GEST-NORM-03": (
        "Integraciones y fronteras",
        "evidencia de ejecución sobre SHA exacto, resultado terminal y prueba de fail-closed"),
}

# Execute existing pure PHP application classes; no framework bootstrap, database,
# HTTP calls, sessions, files from customers, or secrets.
PHP_PROBE = r'''
require 'src/Application/Compliance/NormativeApplicability.php';
require 'src/Application/Compliance/NormativeMatrix.php';
require 'src/Application/Compliance/NormativeReviewProjection.php';
require 'src/Application/Documents/DocumentApiService.php';
use App\Application\Compliance\NormativeApplicability as A;
use App\Application\Compliance\NormativeMatrix as M;
use App\Application\Compliance\NormativeReviewProjection as R;
use App\Application\Documents\DocumentApiService as D;
$ctx=[
 'tenant_ref'=>'tenant-a','sector'=>'comercio','ciiu'=>'4711',
 'municipio'=>'pereira','riesgo'=>'ii','tamano_empresa'=>'micro',
 'regimen'=>'regimen-a'
];
$rule=[
 'tenant_ref'=>'tenant-a','kind'=>'OBLIGATORIO','approved'=>true,
 'reviewer_ref'=>'reviewer-fixture',
 'source'=>[
  'kind'=>'official','issuer'=>'synthetic-authority','article'=>'synthetic-article',
  'version'=>'v1','official_url'=>'https://normas.example.invalid/synthetic/1'
 ],
 'effective_from'=>'2026-01-01','effective_until'=>'2026-12-31',
 'verified_on'=>'2026-02-01',
 'conditions'=>['sector'=>'comercio','municipio'=>'pereira']
];
$foreign=$rule; $foreign['tenant_ref']='tenant-b';
$badSource=$rule; $badSource['source']['version']='';
$badApproval=$rule; $badApproval['approved']=false;
$badMoney=$rule; $badMoney['amount_cop']=250000;
$badDate=$rule; $badDate['effective_until']='2026-02-30';
$rules=[$rule,$foreign];
$goodReview=[
 'tenant_ref'=>'tenant-a','municipio'=>'pereira','row_index'=>0,
 'reviewer_ref'=>'reviewer-fixture','reviewed_on'=>'2026-10-20',
 'evidence_ref'=>'fixture:evidence/synthetic','state'=>'EN_REVISION'
];
$badReview=$goodReview; $badReview['evidence_ref']='not-a-fixture';
$d=new D();
$a=$d->create([],'tenant-a|entity-a|branch-a',
 'fixture:doc/synthetic','RESERVADA');
$state=$a['state'];
function denied(callable $f): bool {
 try {$f();return false;} catch (\DomainException $e) {return true;}
}
echo json_encode([
 'applicable'=>A::evaluate($rule,$ctx,'2026-10-27'),
 'matrix'=>M::evaluate($rules,$ctx,'2026-10-27'),
 'review'=>R::evaluate($rules,$ctx,'2026-10-27',[$goodReview]),
 'unreviewed'=>R::evaluate($rules,$ctx,'2026-10-27',[]),
 'invalid_review'=>R::evaluate($rules,$ctx,'2026-10-27',[$badReview]),
 'duplicate_review'=>R::evaluate($rules,$ctx,'2026-10-27',[$goodReview,$goodReview]),
 'foreign_matrix'=>M::evaluate([$foreign],$ctx,'2026-10-27'),
 'unknown_context'=>M::evaluate([$rule],['tenant_ref'=>'tenant-a'],'2026-10-27'),
 'bad_source'=>A::evaluate($badSource,$ctx,'2026-10-27'),
 'bad_approval'=>A::evaluate($badApproval,$ctx,'2026-10-27'),
 'bad_amount'=>A::evaluate($badMoney,$ctx,'2026-10-27'),
 'bad_date'=>A::evaluate($badDate,$ctx,'2026-10-27'),
 'foreign_rule'=>A::evaluate($foreign,$ctx,'2026-10-27'),
 'document'=>$a['document'],
 'document_detail'=>$d->detail($state,'tenant-a|entity-a|branch-a',
   'fixture:doc/synthetic'),
 'denied_tenant'=>denied(fn()=>$d->detail($state,
   'tenant-b|entity-a|branch-a','fixture:doc/synthetic')),
 'denied_entity'=>denied(fn()=>$d->detail($state,
   'tenant-a|entity-b|branch-a','fixture:doc/synthetic')),
 'denied_branch'=>denied(fn()=>$d->detail($state,
   'tenant-a|entity-a|branch-b','fixture:doc/synthetic')),
 'denied_nonfixture'=>denied(fn()=>$d->create($state,
   'tenant-a|entity-a|branch-a','clinical-record','RESERVADA'))
],JSON_THROW_ON_ERROR);
'''


def sources() -> dict[str, str]:
    return {path: (ROOT / path).read_text(encoding="utf-8") for path in SOURCES}


def matrix_rows(raw: object) -> dict[str, tuple[str, ...]]:
    if type(raw) is not str:
        raise ValueError("UNKNOWN: missing matrix")
    found: dict[str, tuple[str, ...]] = {}
    for line in raw.splitlines():
        if not line.startswith("| " + BT + "GEST-"):
            continue
        cells = tuple(s.strip() for s in line.strip("|").split("|"))
        header = cells[0] if cells else ""
        match = re.match(r"^" + re.escape(BT) + r"(GEST-(?:LIM|NORM)-\d\d)"
                         + re.escape(BT) + r" · ", header)
        if match is None or match.group(1) not in CLAUSES:
            continue
        key = match.group(1)
        if (key in found or len(cells) != 5
                or header != f"{BT}{key}{BT} · {BT}{SPEC}{BT}"
                or cells[1] != CLAUSES[key][0]
                or cells[2] != f"{BT}NO_VERIFICABLE{BT}"
                or f"Falta vincular para {key} " not in cells[3]
                or CLAUSES[key][1] not in cells[3]
                or "no se identificó tríada código+prueba+PR fusionado" not in cells[3]
                or cells[4] != "#802"):
            raise ValueError("UNKNOWN: malformed/duplicate " + key)
        found[key] = cells
    if set(found) != set(CLAUSES):
        raise ValueError("UNKNOWN: missing coverage rows")
    return found


def normative_spec(raw: object) -> bool:
    if type(raw) is not str:
        return False
    lines = raw.splitlines()
    required = (
        "## Límites no negociables",
        "## Modelo transversal: una sola fuente por concepto",
        "## Integraciones y fronteras",
        *("- " + CLAUSES[k][0] for k in ("GEST-LIM-04", "GEST-LIM-05")),
    )
    return (all(lines.count(line) == 1 for line in required)
            and "solo se admite diseño y fixtures sintéticos" in raw
            and "Un documento cargado no demuestra cumplimiento" in raw
            and "Entitlements vs permisos" in raw
            and "filtro tenant + entidad legal + permiso en servidor" in raw.lower())


@lru_cache(maxsize=1)
def exercise() -> dict[str, object]:
    php = shutil.which("php")
    if php is None:
        raise AssertionError("UNKNOWN: PHP CLI missing")
    run = subprocess.run(
        [php, "-n", "-d", "allow_url_fopen=0", "-r", PHP_PROBE],
        cwd=ROOT, text=True, capture_output=True, check=False, timeout=20)
    if run.returncode or run.stderr.strip():
        raise AssertionError("UNKNOWN: offline PHP failed: " + run.stderr[-600:])
    result = json.loads(run.stdout)
    if type(result) is not dict:
        raise AssertionError("UNKNOWN: invalid PHP result")
    return result


def verdict(key: object, data: object, probe: object) -> dict[str, str]:
    """Local evidence is never a legal/medical/operational attestation."""
    result = {"local": UNKNOWN, "full_scope": UNKNOWN,
              "ci": UNKNOWN, "production": UNKNOWN}
    if key not in CLAUSES or type(data) is not dict or type(probe) is not dict:
        return result
    if any(type(data.get(path)) is not str or not data[path] for path in SOURCES):
        return result
    try:
        matrix_rows(data[MATRIX])
    except ValueError:
        return result
    if not normative_spec(data[SPEC]):
        return result
    if any(symbol not in data[file] for file, symbol in (
        (APPLICABILITY, "final class NormativeApplicability"),
        (NORM_MATRIX, "final class NormativeMatrix"),
        (REVIEW, "final class NormativeReviewProjection"),
        (DOCUMENT, "final class DocumentApiService"),
    )):
        return result
    try:
        valid = (
            probe["applicable"]["kind"] == "OBLIGATORIO"
            and probe["applicable"]["applicability"] == "APLICA"
            and probe["applicable"]["source"]["version"] == "v1"
            and len(probe["matrix"]["rows"]) == 1
            and probe["matrix"]["rows"][0]["source"]["issuer"] == "synthetic-authority"
            and probe["review"]["rows"][0]["state"] == "EN_REVISION"
            and probe["unreviewed"]["rows"][0]["state"] == "PENDIENTE"
            and probe["invalid_review"]["rows"][0]["state"] == "REQUIERE_REVISION"
            and probe["duplicate_review"]["rows"][0]["state"] == "REQUIERE_REVISION"
            and probe["foreign_matrix"]["rows"] == []
            and probe["unknown_context"]["rows"][0]["kind"] == "REQUIERE_REVISION"
            and all(probe[field]["kind"] == "REQUIERE_REVISION" for field in (
                "bad_source", "bad_approval", "bad_amount", "bad_date", "foreign_rule"
            ))
            and all(probe[field] is True for field in (
                "denied_tenant", "denied_entity", "denied_branch", "denied_nonfixture"
            ))
            and probe["document"] == probe["document_detail"]
            and set(probe["document"]) == {"reference", "classification", "status"}
        )
    except (KeyError, TypeError, IndexError, AttributeError):
        valid = False
    if valid and key == "GEST-NORM-02":
        result["local"] = LOCAL
    # LIM-04: synthetic document guard != segregated medical records.
    # LIM-05: reviewed norm provenance != COP amounts/transactions.
    # NORM-01: partial legal guards != all non-negotiable restrictions.
    # NORM-03: no real integration, entitlement or RBAC implementation tested.
    return result


class BrechasCondor29Tests(unittest.TestCase):
    def test_contrato_principal(self) -> None:
        data, probe = sources(), exercise()
        self.assertEqual(set(CLAUSES), set(matrix_rows(data[MATRIX])))
        self.assertTrue(normative_spec(data[SPEC]))
        for key in CLAUSES:
            with self.subTest(row=key):
                result = verdict(key, data, probe)
                self.assertEqual(LOCAL if key == "GEST-NORM-02" else UNKNOWN,
                                 result["local"])
                self.assertEqual(UNKNOWN, result["full_scope"])
        self.assertEqual("OBLIGATORIO", probe["applicable"]["kind"])
        self.assertEqual("EN_REVISION", probe["review"]["rows"][0]["state"])
        self.assertTrue(probe["denied_entity"])
        self.assertTrue(probe["denied_branch"])

    def test_falla_cerrado(self) -> None:
        data, probe = sources(), exercise()
        original = next(s for s in data[MATRIX].splitlines()
                        if s.startswith("| " + BT + "GEST-LIM-04" + BT))
        with self.assertRaisesRegex(ValueError, "duplicate"):
            matrix_rows(data[MATRIX] + "\n" + original)
        with self.assertRaisesRegex(ValueError, "missing"):
            matrix_rows("\n".join(s for s in data[MATRIX].splitlines()
                                   if not s.startswith("| " + BT + "GEST-NORM-03" + BT)))
        with self.assertRaisesRegex(ValueError, "malformed"):
            matrix_rows(data[MATRIX].replace(
                original, original.replace(BT + "NO_VERIFICABLE" + BT,
                                           BT + "CUBIERTO" + BT)))
        for key in CLAUSES:
            with self.subTest(row=key):
                for absent in (None, {}, {"ci": "success"}):
                    self.assertEqual(UNKNOWN, verdict(key, absent, probe)["local"])
                    self.assertEqual(UNKNOWN, verdict(key, data, absent)["local"])
                removed = dict(data)
                removed.pop(APPLICABILITY)
                self.assertEqual(UNKNOWN, verdict(key, removed, probe)["local"])
        self.assertEqual("REQUIERE_REVISION", probe["bad_source"]["kind"])
        self.assertEqual("REQUIERE_REVISION", probe["bad_amount"]["kind"])
        self.assertEqual([], probe["foreign_matrix"]["rows"])

    def test_sin_datos_reales(self) -> None:
        probe = exercise()
        own = Path(__file__).read_text(encoding="utf-8")
        tree = ast.parse(own)
        imported = {name.name.split(".")[0] for node in ast.walk(tree)
                    if isinstance(node, ast.Import) for name in node.names}
        imported |= {node.module.split(".")[0] for node in ast.walk(tree)
                     if isinstance(node, ast.ImportFrom) and node.module}
        self.assertFalse(imported & {"requests", "urllib", "httpx", "socket",
                                     "pymysql", "mysql", "playwright"})
        for forbidden in ("curl_", "PDO", "file_get_contents", "shell_exec",
                          "file_put_contents", "HttpClient", "EntityManager"):
            self.assertNotIn(forbidden, PHP_PROBE)
        self.assertEqual("fixture:doc/synthetic", probe["document"]["reference"])
        self.assertNotIn("clinical", json.dumps(probe["document"]))
        self.assertTrue(probe["denied_nonfixture"])

    def test_estados_unknown_explicitos(self) -> None:
        data, probe = sources(), exercise()
        self.assertEqual("REQUIERE_REVISION",
                         probe["unknown_context"]["rows"][0]["kind"])
        self.assertIsNone(probe["bad_source"]["source"])
        self.assertIsNone(probe["bad_amount"]["source"])
        self.assertEqual("REQUIERE_REVISION",
                         probe["invalid_review"]["rows"][0]["state"])
        self.assertEqual("REQUIERE_REVISION",
                         probe["duplicate_review"]["rows"][0]["state"])
        for key in CLAUSES:
            result = verdict(key, data, probe)
            self.assertEqual(UNKNOWN, result["ci"])
            self.assertEqual(UNKNOWN, result["production"])
            self.assertEqual(UNKNOWN, result["full_scope"])
            self.assertEqual(UNKNOWN, verdict(key, data, {})["local"])
        self.assertEqual(UNKNOWN, verdict("GEST-NORM-99", data, probe)["local"])


if __name__ == "__main__":
    unittest.main()
