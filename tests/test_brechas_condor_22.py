#!/usr/bin/env python3
"""Condor #1217: cinco brechas de gestión con contratos PHP sintéticos.

Los guards locales no acreditan aislamiento de todos los endpoints/exportaciones,
cumplimiento normativo real, propagación de permisos ni decisión legal de RR. HH.
La matriz permanece NO_VERIFICABLE hasta una tríada code/test/PR fusionada.
"""
from __future__ import annotations

import ast
import json
import re
import shutil
import subprocess
import unittest
from functools import lru_cache
from pathlib import Path

ROOT = Path(__file__).resolve().parents[1]
UNKNOWN = "UNKNOWN"
LOCAL = "LOCAL_CONTRACT"
DOC = "docs/gestion-empresarial-integral.md"
MATRIX = "docs/matriz-cobertura.md"
IDS = tuple(f"GEST-AC-0{n}" for n in range(3, 8))
CLAUSES = {
    "GEST-AC-03": "Ninguna lectura, exportación, alerta o enlace revela datos de otro tenant",
    "GEST-AC-04": "La vigencia normativa y los parámetros de cálculo son datos versionados",
    "GEST-AC-05": "Los módulos existentes de comercio/operación se consumen como fuente de verdad",
    "GEST-AC-06": "Los flujos RR. HH. reales permanecen inaccesibles",
    "GEST-AC-07": "Cada entrega posterior prueba autorización, aislamiento, auditoría",
}
# Brechas no resueltas por un único guard offline; nunca certificarlas como GREEN.
GAPS = {
    "GEST-AC-03": "Faltan pruebas cross-tenant/entidad/sede para todas las lecturas, alertas y enlaces.",
    "GEST-AC-04": "No hay prueba terminal exact-SHA del catálogo normativo completo en producción.",
    "GEST-AC-05": "No se ha acreditado consumo integral de fuentes sin duplicación de modelos.",
    "GEST-AC-06": "No existe autorización sucesora a la decisión B ni prueba de acceso real.",
    "GEST-AC-07": "No se acreditaron todos los slices, auditoría ni accesibilidad es-CO.",
}
PATHS = {
    "matrix": MATRIX,
    "gestion": DOC,
    "operativos": "docs/modulos-operativos.md",
    "compliance": "src/Application/Compliance/ComplianceApiService.php",
    "normative": "src/Application/Compliance/NormativeApplicability.php",
    "reports": "src/Application/Reporting/TenantCsvReportService.php",
    "hr": "src/Http/Controller/EmployeeController.php",
    "orders": "src/Http/Controller/OrderController.php",
    "purchasing": "src/Http/Controller/PurchasingController.php",
}

def read_sources() -> dict[str, str]:
    return {name: (ROOT / path).read_text(encoding="utf-8")
            for name, path in PATHS.items()}


def matrix_rows(raw: object) -> dict[str, tuple[str, ...]]:
    if type(raw) is not str:
        raise ValueError("UNKNOWN: matriz ilegible")
    found: dict[str, tuple[str, ...]] = {}
    for line in raw.splitlines():
        match = re.match(r"^\|\s*"+chr(96)+r"(GEST-AC-0[3-7])"+chr(96)+r"\s*·", line)
        if not match:
            continue
        key = match.group(1)
        cells = tuple(c.strip() for c in line.strip().strip("|").split("|"))
        if (key in found or len(cells) != 5
                or cells[0] != f"{chr(96)}{key}{chr(96)} · {chr(96)}{DOC}{chr(96)}"
                or not cells[1].startswith(CLAUSES[key])
                or cells[2] != f"{chr(96)}NO_VERIFICABLE{chr(96)}"
                or "Falta vincular para " + key not in cells[3]
                or "tríada código+prueba+PR fusionado" not in cells[3]
                or cells[4] != "#802"):
            raise ValueError(f"UNKNOWN: fila ambigua {key}")
        found[key] = cells
    if set(found) != set(IDS):
        raise ValueError("UNKNOWN: filas faltantes")
    return found


# Ejecutar únicamente clases PHP puras, sin Symfony kernel, BD, credenciales
# ni llamadas de red. Todos los identificadores y cantidades son ficticios.
PHP_PROBE = r"""
require 'src/Application/Compliance/ComplianceApiService.php';
require 'src/Application/Compliance/NormativeApplicability.php';
require 'src/Application/Reporting/TenantCsvReportService.php';
use App\Application\Compliance\ComplianceApiService as E;
use App\Application\Compliance\NormativeApplicability as N;
use App\Application\Reporting\TenantCsvReportService as R;
function denied(callable $callback): bool {
    try {$callback(); return false;} catch (\DomainException $e) {return true;}
}
$a=hash('sha256','fixture:tenant-a|entity-a|branch-a');
$b=hash('sha256','fixture:tenant-a|entity-b|branch-b');
$c=hash('sha256','fixture:tenant-b|entity-a|branch-a');
$created=E::createRecord([], $a, [
    'evidence_ref'=>'fixture:evidence/local',
    'obligation_ref'=>'fixture:obligation/one', 'type'=>'EVIDENCIA'
]);
$store=$created['store'];
$profile=['tenant_ref'=>'tenant-a','sector'=>'comercio','ciiu'=>'4711',
    'municipio'=>'pereira','riesgo'=>'ii','tamano_empresa'=>'micro',
    'regimen'=>'ordinario'];
$source=['kind'=>'official','issuer'=>'authority-fixture',
    'article'=>'article-fixture','version'=>'v1',
    'official_url'=>'https://example.invalid/norm/fixture'];
$rule=['tenant_ref'=>'tenant-a','kind'=>'OBLIGATORIO','approved'=>true,
    'reviewer_ref'=>'reviewer-fixture','source'=>$source,
    'effective_from'=>'2026-01-01','effective_until'=>'2026-12-31',
    'verified_on'=>'2026-02-01','conditions'=>['sector'=>'comercio']];
$missing=$rule; unset($missing['source']['version']);
$expired=$rule; $expired['effective_until']='2026-02-01';
$unreviewed=$rule; $unreviewed['approved']=false;
$foreignProfile=$profile; $foreignProfile['tenant_ref']='tenant-b';
$records=[
    ['tenant_id'=>'tenant-a','occurred_at'=>'2026-10-20',
     'record_id'=>'local-record','source_id'=>'local-source',
     'variant_id'=>'local-variant','quantity'=>3,'reserved_quantity'=>0],
    ['tenant_id'=>'tenant-b','occurred_at'=>'2026-10-20',
     'record_id'=>'foreign-leak','source_id'=>'foreign-source',
     'variant_id'=>'foreign-variant','quantity'=>9,'reserved_quantity'=>0]
];
$report=new R();
$csv=$report->build('inventory','tenant-a','2026-01-01','2026-12-31',$records);
$empty=$report->build('inventory','tenant-a','2026-01-01','2026-12-31',[]);
echo json_encode([
    'own'=>E::viewRecord($store,$a,'fixture:evidence/local'),
    'other_entity'=>E::viewRecord($store,$b,'fixture:evidence/local'),
    'other_tenant'=>E::viewRecord($store,$c,'fixture:evidence/local'),
    'other_list'=>count(E::listRecords($store,$c)),
    'foreign_edit_denied'=>denied(fn()=>E::changeState(
        $store,$b,'fixture:evidence/local','EN_REVISION')),
    'auto_approve_denied'=>denied(fn()=>E::changeState(
        $store,$a,'fixture:evidence/local','CUMPLE')),
    'norm_valid'=>N::evaluate($rule,$profile,'2026-10-20'),
    'norm_missing'=>N::evaluate($missing,$profile,'2026-10-20'),
    'norm_expired'=>N::evaluate($expired,$profile,'2026-10-20'),
    'norm_unreviewed'=>N::evaluate($unreviewed,$profile,'2026-10-20'),
    'norm_foreign'=>N::evaluate($rule,$foreignProfile,'2026-10-20'),
    'csv'=>$csv['body'],
    'csv_empty'=>$empty['body'],
    'csv_invalid_denied'=>denied(fn()=>$report->build(
        'inventory','tenant-a','2026-01-01','2026-12-31',
        [['occurred_at'=>'2026-10-20','record_id'=>'invalid']]))
],JSON_THROW_ON_ERROR);
"""


@lru_cache(maxsize=1)
def exercise_php() -> dict[str, object]:
    php = shutil.which("php")
    if php is None:
        raise AssertionError("UNKNOWN: intérprete PHP no disponible")
    completed = subprocess.run(
        [php, "-n", "-d", "allow_url_fopen=0", "-r", PHP_PROBE],
        cwd=ROOT, text=True, capture_output=True, timeout=15, check=False,
        env={"PATH": "/usr/local/bin:/usr/bin:/bin"},
    )
    if completed.returncode:
        raise AssertionError("UNKNOWN: PHP offline falló: " + completed.stderr[-320:])
    try:
        value = json.loads(completed.stdout)
    except ValueError as exc:
        raise AssertionError("UNKNOWN: respuesta PHP inválida") from exc
    if type(value) is not dict:
        raise AssertionError("UNKNOWN: respuesta PHP ambigua")
    return value


def local_contracts(files: object, observation: object) -> dict[str, bool]:
    if (type(files) is not dict or set(files) != set(PATHS)
            or any(type(s) is not str or not s.strip() for s in files.values())
            or type(observation) is not dict):
        return dict.fromkeys(IDS, False)
    o = observation
    doc, hr, normative = files["gestion"], files["hr"], files["normative"]
    review = lambda x: type(x) is dict and x.get("kind") == "REQUIERE_REVISION" and x.get("source") is None
    return {
        "GEST-AC-03": (
            o.get("own", {}).get("evidence_ref") == "fixture:evidence/local"
            and o.get("other_entity") is None and o.get("other_tenant") is None
            and o.get("other_list") == 0
            and o.get("foreign_edit_denied") is True
            and "local-record" in o.get("csv", "")
            and "foreign-leak" not in o.get("csv", "")
            and "tenant → entidad legal → sede/municipio" in doc
        ),
        "GEST-AC-04": (
            o.get("norm_valid", {}).get("kind") == "OBLIGATORIO"
            and o.get("norm_valid", {}).get("source", {}).get("version") == "v1"
            and all(review(o.get(key)) for key in
                    ("norm_missing", "norm_expired", "norm_unreviewed", "norm_foreign"))
            and "effective_from" in normative and "effective_until" in normative
            and "version" in normative
        ),
        "GEST-AC-05": (
            "Módulos operativos" in doc
            and "src/Http/Controller/OrderController.php" in files["operativos"]
            and "src/Http/Controller/PurchasingController.php" in files["operativos"]
            and "class OrderController" in files["orders"]
            and "class PurchasingController" in files["purchasing"]
        ),
        "GEST-AC-06": (
            "La decisión B" in doc and "#742" in doc
            and "private readonly ?EmployeeHttpStore $employees = null" in hr
            and "ServiceUnavailableHttpException" in hr
            and "datos reales" in doc
        ),
        "GEST-AC-07": (
            o.get("foreign_edit_denied") is True
            and o.get("auto_approve_denied") is True
            and o.get("csv_invalid_denied") is True
            and "local-record" not in o.get("csv_empty", "")
            and "es-CO" in doc and "auditoría" in doc
        ),
    }


def verdict(key: object, matrix: object, files: object, observation: object) -> str:
    if key not in IDS:
        return UNKNOWN
    try:
        rows = matrix_rows(matrix)
    except ValueError:
        return UNKNOWN
    if key not in rows or not local_contracts(files, observation).get(key, False):
        return UNKNOWN
    return LOCAL


def full_evidence(key: object, local: object, proof: object = None) -> str:
    """Sin pruebas externas exact-SHA no se acredita la cobertura integral."""
    return UNKNOWN


class BrechasCondor22Tests(unittest.TestCase):
    @classmethod
    def setUpClass(cls) -> None:
        cls.files = read_sources()
        cls.matrix = cls.files["matrix"]
        cls.obs = exercise_php()

    def test_contrato_principal(self) -> None:
        rows = matrix_rows(self.matrix)
        self.assertEqual(set(rows), set(IDS))
        local = local_contracts(self.files, self.obs)
        self.assertEqual(set(local), set(IDS))
        for key in IDS:
            with self.subTest(brecha=key):
                self.assertTrue(local[key], f"Guard sintético roto: {key}")
                self.assertEqual(verdict(key, self.matrix, self.files, self.obs), LOCAL)
                self.assertEqual(full_evidence(key, LOCAL), UNKNOWN)
                self.assertTrue(GAPS[key])

    def test_falla_cerrado(self) -> None:
        row = next(s for s in self.matrix.splitlines()
                   if s.startswith("| " + chr(96) + IDS[0] + chr(96)))
        for broken in (
            None, "", self.matrix.replace(row, "", 1),
            self.matrix + "\n" + row,
            self.matrix.replace(row, row.replace("NO_VERIFICABLE", "CUBIERTO", 1)),
        ):
            with self.subTest(sample=str(broken)[:35]):
                with self.assertRaisesRegex(ValueError, UNKNOWN):
                    matrix_rows(broken)
                self.assertEqual(
                    verdict(IDS[0], broken, self.files, self.obs), UNKNOWN,
                )
        self.assertEqual(local_contracts({}, self.obs), dict.fromkeys(IDS, False))
        self.assertEqual(verdict(IDS[0], self.matrix, {}, self.obs), UNKNOWN)
        for key, field, corrupt in (
            ("GEST-AC-03", "other_tenant", {"evidence_ref": "fixture:evidence/local"}),
            ("GEST-AC-04", "norm_missing", {"kind": "OBLIGATORIO", "source": None}),
            ("GEST-AC-05", None, None),
            ("GEST-AC-06", None, None),
            ("GEST-AC-07", "csv_invalid_denied", False),
        ):
            with self.subTest(key=key):
                files, observed = dict(self.files), dict(self.obs)
                if key == "GEST-AC-05":
                    files["operativos"] = ""
                elif key == "GEST-AC-06":
                    files["hr"] = ""
                else:
                    observed[field] = corrupt
                self.assertEqual(verdict(key, self.matrix, files, observed), UNKNOWN)

    def test_sin_datos_reales(self) -> None:
        source = (ROOT / "tests/test_brechas_condor_22.py").read_text(encoding="utf-8")
        parsed = ast.parse(source)
        imports = {n.module or "" for n in ast.walk(parsed)
                   if isinstance(n, ast.ImportFrom)}
        self.assertFalse(any(name.startswith(("requests", "urllib", "httpx"))
                             for name in imports))
        self.assertIn("fixture:evidence/local", PHP_PROBE)
        self.assertIn("https://example.invalid", PHP_PROBE)
        self.assertNotIn("DATABASE_URL", PHP_PROBE)
        self.assertNotIn("APP_SECRET", PHP_PROBE)
        self.assertNotIn("@example.com", PHP_PROBE)
        self.assertEqual(full_evidence("GEST-AC-06", self.obs), UNKNOWN)

    def test_estados_unknown_explicitos(self) -> None:
        self.assertEqual(len(GAPS), 5)
        for key in IDS:
            with self.subTest(brecha=key):
                self.assertEqual(
                    full_evidence(key, verdict(key, self.matrix, self.files, self.obs)),
                    UNKNOWN,
                )
        self.assertIsNone(self.obs["other_tenant"])
        self.assertIsNone(self.obs["other_entity"])
        self.assertTrue(self.obs["auto_approve_denied"])
        self.assertEqual(self.obs["norm_missing"]["kind"], "REQUIERE_REVISION")


if __name__ == "__main__":
    unittest.main()
