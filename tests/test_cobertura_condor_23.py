#!/usr/bin/env python3
"""Condor #1342: ejecutar PHP REAL en cinco contratos locales parciales.

El resto se clasifica B/C/D; ninguna fila llega a CUBIERTO por este test.
No se prueban copias, continuidad, poderes legales, datos reales ni go-live.
"""
from __future__ import annotations

import json
import re
import shutil
import subprocess
import unittest
from pathlib import Path

ROOT = Path(__file__).resolve().parents[1]
MATRIX = ROOT / "docs/matriz-cobertura.md"
SPEC = "docs/gestion-empresarial-integral.md"
BT = chr(96)
TITLES = {
    "GEST-M14-S03": "Seguridad de la información / revisiones",
    "GEST-M14-S04": "Seguridad de la información / auditoría",
    "GEST-M14-S05": "Seguridad de la información / copias",
    "GEST-M14-S06": "Seguridad de la información / incidentes",
    "GEST-M14-S07": "Seguridad de la información / continuidad",
    "GEST-M14-S08": "Seguridad de la información / privacidad",
    "GEST-M15-S01": "Gobierno corporativo / socios",
    "GEST-M15-S04": "Gobierno corporativo / facultades",
    "GEST-M15-S07": "Gobierno corporativo / autorizaciones",
    "GEST-M15-S08": "Gobierno corporativo / decisiones",
    "GEST-NORM-01": "Límites no negociables",
    "GEST-NORM-02": "Modelo transversal: una sola fuente por concepto",
}
LOCAL = ("GEST-M14-S04", "GEST-M14-S08", "GEST-M15-S01",
         "GEST-M15-S08", "GEST-NORM-02")
NOT_OFFLINE = {
    "GEST-M14-S03": ("D", "No hay recertificación integral de accesos"),
    "GEST-M14-S05": ("B", "Copia y restauración exigen backup real"),
    "GEST-M14-S06": ("D", "No hay gestión íntegra de incidentes de seguridad"),
    "GEST-M14-S07": ("B", "Continuidad requiere recuperación de entorno real"),
    "GEST-M15-S04": ("D", "No hay facultades legales delegadas completas"),
    "GEST-M15-S07": ("D", "No hay aprobación societaria legal operativa"),
    "GEST-NORM-01": ("C", "Política general, sin función única ejecutable"),
}
PHP_FILES = (
    "src/Application/Governance/GovernanceApiService.php",
    "src/Application/Documents/DocumentApiService.php",
    "src/Application/Commercial/BillingAuditEnvelope.php",
    "src/Application/Compliance/NormativeApplicability.php",
    "src/Application/Compliance/NormativeMatrix.php",
)
PROBE = r'''
require_once 'src/Application/Governance/GovernanceApiService.php';
require_once 'src/Application/Documents/DocumentApiService.php';
require_once 'src/Application/Commercial/BillingAuditEnvelope.php';
require_once 'src/Application/Compliance/NormativeApplicability.php';
require_once 'src/Application/Compliance/NormativeMatrix.php';
use App\Application\Governance\GovernanceApiService as G;
use App\Application\Documents\DocumentApiService as D;
use App\Application\Commercial\BillingAuditEnvelope as A;
use App\Application\Compliance\NormativeMatrix as N;
function denied(callable $action): bool {
    try { $action(); } catch (\DomainException $error) { return true; }
    return false;
}
$a = hash('sha256', 'tenant-fixture-a|branch-fixture-a');
$b = hash('sha256', 'tenant-fixture-b|branch-fixture-a');
$body = G::createRecord([], $a,
    ['kind'=>'body', 'ref'=>'fixture:body/shareholders']);
$minute = G::createRecord($body['store'], $a,
    ['kind'=>'minute', 'ref'=>'fixture:minute/decision-a']);
$bodyOK = count(G::listRecords($minute['store'], $a)) === 2
    && G::viewRecord($minute['store'], $a, 'fixture:body/shareholders')['state']
       === 'REQUIERE_REVISION';
$bodyDenied = G::listRecords($minute['store'], $b) === []
    && G::viewRecord($minute['store'], $b, 'fixture:body/shareholders') === null
    && denied(fn() => G::createRecord($minute['store'], $a,
        ['kind'=>'body','ref'=>'fixture:body/shareholders']));
$progress = G::changeState($minute['store'], $a,
    'fixture:minute/decision-a', 'EN_REVISION');
$decisionOK = $progress['record']['state'] === 'EN_REVISION';
$decisionDenied = denied(fn() => G::changeState($progress['store'], $a,
    'fixture:minute/decision-a', 'APROBADO'))
    && denied(fn() => G::changeState($progress['store'], $a,
    'fixture:minute/decision-a', 'CERRADO'));
$envelope = [
    'tenant_ref'=>'tenant:fixture-a', 'idempotency_key'=>'billing:fixture-001',
    'request_fingerprint'=>str_repeat('a',64), 'outcome'=>'unknown',
    'provider_ref'=>null, 'evidence_ref'=>'billing:evidence:fixture-a',
    'observed_at'=>'2026-10-01T00:00:00.000000Z',
];
$auditOK = A::fromArray($envelope)->toArray() === $envelope;
$auditDenied = denied(fn() => A::fromArray([...$envelope,'password'=>'fixture']))
    && denied(fn() => A::fromArray([...$envelope,'provider_ref'=>'token:fixture']))
    && denied(fn() => A::fromArray([...$envelope,'request_fingerprint'=>'invalid']));
$document = new D();
$doc = $document->create([], 'tenant-a|entity-a|branch-a',
    'fixture:doc/privacy-a', 'RESERVADA');
$privacyOK = $document->detail($doc['state'],
    'tenant-a|entity-a|branch-a', 'fixture:doc/privacy-a') === $doc['document']
    && array_keys($doc['document']) === ['reference','classification','status'];
$privacyDenied = denied(fn() => $document->detail($doc['state'],
    'tenant-b|entity-a|branch-a', 'fixture:doc/privacy-a'))
    && denied(fn() => $document->create($doc['state'],
    'tenant-a|entity-a|branch-a', 'clinical-record', 'RESERVADA'));
$context = [
    'tenant_ref'=>'tenant-a', 'sector'=>'comercio', 'ciiu'=>'4711',
    'municipio'=>'bogota', 'riesgo'=>'ii', 'tamano_empresa'=>'micro',
    'regimen'=>'regimen-a',
];
$rule = [
    'tenant_ref'=>'tenant-a', 'kind'=>'OBLIGATORIO', 'approved'=>true,
    'reviewer_ref'=>'reviewer-fixture',
    'source'=>[
        'kind'=>'official', 'issuer'=>'fixture-authority',
        'article'=>'fixture-article', 'version'=>'v1',
        'official_url'=>'https://normas.example.invalid/fixture',
    ],
    'effective_from'=>'2026-01-01', 'effective_until'=>'2026-12-31',
    'verified_on'=>'2026-02-01',
    'conditions'=>['sector'=>'comercio','municipio'=>'bogota'],
];
$rows = N::evaluate([$rule], $context, '2026-10-29')['rows'];
$normOK = count($rows) === 1
    && $rows[0]['kind'] === 'OBLIGATORIO'
    && $rows[0]['applicability'] === 'APLICA';
$foreign = [...$rule,'tenant_ref'=>'tenant-b'];
$unapproved = [...$rule,'approved'=>false];
$normDenied = N::evaluate([$foreign], $context, '2026-10-29')['rows'] === []
    && N::evaluate([$unapproved],$context,
        '2026-10-29')['rows'][0]['kind'] === 'REQUIERE_REVISION'
    && N::evaluate([$rule],['tenant_ref'=>'tenant-a'],
        '2026-10-29')['rows'][0]['kind'] === 'REQUIERE_REVISION';
echo json_encode([
    'GEST-M14-S04'=>$auditOK,
    'GEST-M14-S04-denied'=>$auditDenied,
    'GEST-M14-S08'=>$privacyOK,
    'GEST-M14-S08-denied'=>$privacyDenied,
    'GEST-M15-S01'=>$bodyOK,
    'GEST-M15-S01-denied'=>$bodyDenied,
    'GEST-M15-S08'=>$decisionOK,
    'GEST-M15-S08-denied'=>$decisionDenied,
    'GEST-NORM-02'=>$normOK,
    'GEST-NORM-02-denied'=>$normDenied,
], JSON_THROW_ON_ERROR);
'''


def matrix_rows(raw: object) -> dict[str, str]:
    if type(raw) is not str:
        raise ValueError("UNKNOWN: matrix missing")
    rows: dict[str, str] = {}
    for line in raw.splitlines():
        for key, title in TITLES.items():
            prefix = "| " + BT + key + BT + " · " + BT + SPEC + BT + " |"
            if not line.startswith(prefix):
                continue
            cells = [cell.strip() for cell in line.strip().strip("|").split("|")]
            if (key in rows or len(cells) != 5
                    or cells[0] != BT + key + BT + " · " + BT + SPEC + BT
                    or cells[1] != title or cells[2] != BT + "NO_VERIFICABLE" + BT
                    or cells[4] != "#802" or key not in cells[3]):
                raise ValueError("UNKNOWN: malformed, duplicate or promoted " + key)
            rows[key] = "NO_VERIFICABLE"
    if set(rows) != set(TITLES):
        raise ValueError("UNKNOWN: incomplete coverage matrix")
    return rows


def php_evidence() -> dict[str, bool]:
    executable = shutil.which("php")
    if not executable:
        raise AssertionError("UNKNOWN: PHP CLI unavailable")
    for path in PHP_FILES:
        if not (ROOT / path).is_file():
            raise AssertionError("UNKNOWN: PHP source missing " + path)
    result = subprocess.run(
        [executable, "-n", "-d", "allow_url_fopen=0", "-r", PROBE],
        cwd=ROOT, text=True, capture_output=True, check=False, timeout=30,
    )
    if result.returncode or result.stderr.strip():
        raise AssertionError("UNKNOWN: PHP error " + result.stderr[-650:])
    try:
        output = json.loads(result.stdout)
    except json.JSONDecodeError as error:
        raise AssertionError("UNKNOWN: invalid PHP output") from error
    expected = {key for name in LOCAL for key in (name, name + "-denied")}
    if (type(output) is not dict or set(output) != expected
            or any(type(value) is not bool for value in output.values())):
        raise AssertionError("UNKNOWN: partial PHP evidence")
    return output


class CoberturaCondor23Tests(unittest.TestCase):
    @classmethod
    def setUpClass(cls) -> None:
        cls.raw = MATRIX.read_text(encoding="utf-8")
        cls.rows = matrix_rows(cls.raw)

    def test_contrato_principal(self) -> None:
        self.assertEqual(set(self.rows), set(TITLES))
        self.assertEqual(set(LOCAL) | set(NOT_OFFLINE), set(TITLES))
        evidence = php_evidence()
        for key in LOCAL:
            with self.subTest(decision=key):
                self.assertIs(evidence[key], True, key)

    def test_falla_cerrado(self) -> None:
        evidence = php_evidence()
        for key in LOCAL:
            with self.subTest(decision=key):
                self.assertIs(evidence[key + "-denied"], True, key)
        for key in TITLES:
            prefix = "| " + BT + key + BT + " · " + BT + SPEC + BT + " |"
            line = next(x for x in self.raw.splitlines() if x.startswith(prefix))
            for corrupt in (
                self.raw.replace(line + "\n", "", 1),
                self.raw + "\n" + line,
                self.raw.replace(line, line.replace("NO_VERIFICABLE", "CUBIERTO"), 1),
            ):
                with self.subTest(code=key):
                    with self.assertRaisesRegex(ValueError, "UNKNOWN"):
                        matrix_rows(corrupt)
        for invalid in (None, "", "incomplete"):
            with self.assertRaisesRegex(ValueError, "UNKNOWN"):
                matrix_rows(invalid)

    def test_sin_datos_reales(self) -> None:
        self.assertEqual(set(LOCAL) | set(NOT_OFFLINE), set(TITLES))
        self.assertIn("tenant-fixture-a", PROBE)
        self.assertIn("fixture:minute/decision-a", PROBE)
        for forbidden in ("curl_", "file_get_contents(", "getenv(",
                          "PDO(", "mysqli_", "Bearer ", "example.com"):
            self.assertNotIn(forbidden, PROBE)
        for key, (category, reason) in NOT_OFFLINE.items():
            self.assertIn(category, ("B", "C", "D"))
            self.assertTrue(reason)
            self.assertEqual(self.rows[key], "NO_VERIFICABLE")

    def test_ci_gate_contract(self) -> None:
        self.assertEqual(len(self.rows), 12)
        self.assertEqual(len(LOCAL), 5)
        self.assertTrue(all(php_evidence().values()))


if __name__ == "__main__":
    unittest.main()
