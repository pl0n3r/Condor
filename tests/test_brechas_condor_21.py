#!/usr/bin/env python3
"""Condor #1216: evidencia local D-052..D-054 y GEST-AC-01/02.

Contratos ejecutables con fixtures, no declaración de despliegue, backup,
cumplimiento legal real ni reconciliación de matriz.
"""
from __future__ import annotations

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
GAPS = {
    "D-052": ("ESPECIFICACIONES.md", "Titularidad jurídica por entidad legal e intercompany explícito"),
    "D-053": ("ESPECIFICACIONES.md", "Producción operable durante etapa de construcción"),
    "D-054": ("ESPECIFICACIONES.md", "Auto-migración aditiva con backup durante pre-lanzamiento"),
    "GEST-AC-01": ("docs/gestion-empresarial-integral.md", "Dos empresas con perfil distinto reciben obligaciones distintas"),
    "GEST-AC-02": ("docs/gestion-empresarial-integral.md", "La carga de evidencia no modifica por sí misma el estado a 'CUMPLE'."),
}
PATHS = {
    "matrix": "docs/matriz-cobertura.md",
    "spec": "ESPECIFICACIONES.md",
    "gestion": "docs/gestion-empresarial-integral.md",
    "legal": "src/Domain/Organization/Entity/LegalEntity.php",
    "transfer": "src/Application/Inventory/StockTransferService.php",
    "post": "scripts/post-deploy.sh",
    "backup": "scripts/backup-database.sh",
    "normative": "src/Application/Compliance/NormativeApplicability.php",
    "evidence": "src/Application/Compliance/ComplianceApiService.php",
    "record": "src/Domain/Compliance/Entity/ComplianceRecord.php",
    "posttests": "tests/test_post_deploy.py",
}


def sources() -> dict[str, str]:
    return {name: (ROOT / path).read_text(encoding="utf-8")
            for name, path in PATHS.items()}


def matrix_rows(text: object) -> dict[str, tuple[str, ...]]:
    if not isinstance(text, str):
        raise ValueError("UNKNOWN: matriz ausente")
    rows: dict[str, tuple[str, ...]] = {}
    for line in text.splitlines():
        m = re.match(r"^\| \x60(D-05[234]|GEST-AC-0[12])\x60 · ", line)
        if m is None:
            continue
        key = m.group(1)
        cells = tuple(part.strip() for part in line.strip("|").split("|"))
        explanation = ("Falta evidencia integral" if key.startswith("D-")
                       else "Falta vincular")
        if (key in rows or len(cells) != 5
                or cells[0] != f"\x60{key}\x60 · \x60{GAPS[key][0]}\x60"
                or not cells[1].startswith(GAPS[key][1])
                or cells[2] != "\x60NO_VERIFICABLE\x60"
                or key not in cells[3] or explanation not in cells[3]
                or cells[4] != ("#1" if key.startswith("D-") else "#802")):
            raise ValueError("UNKNOWN: fila ambigua " + key)
        rows[key] = cells
    if set(rows) != set(GAPS):
        raise ValueError("UNKNOWN: faltan filas")
    return rows


# Ejecuta las clases PHP reales en memoria; solo IDs y fuentes ficticias.
PHP_PROBE = r"""
require 'src/Application/Compliance/NormativeApplicability.php';
require 'src/Application/Compliance/ComplianceApiService.php';
use App\Application\Compliance\NormativeApplicability as N;
use App\Application\Compliance\ComplianceApiService as E;
$source = ['kind'=>'official','issuer'=>'authority-fixture',
 'article'=>'article-fixture','version'=>'v1',
 'official_url'=>'https://example.invalid/norma/fixture'];
$profile = ['tenant_ref'=>'tenant-fixture','sector'=>'comercio',
 'ciiu'=>'4711','municipio'=>'pereira','riesgo'=>'ii',
 'tamano_empresa'=>'micro','regimen'=>'regimen-a'];
$other = array_replace($profile,['sector'=>'tecnologia','ciiu'=>'6201']);
$rule = ['tenant_ref'=>'tenant-fixture','kind'=>'OBLIGATORIO',
 'approved'=>true,'reviewer_ref'=>'reviewer-fixture','source'=>$source,
 'effective_from'=>'2026-01-01','effective_until'=>'2026-12-31',
 'verified_on'=>'2026-02-01','conditions'=>['sector'=>'comercio','ciiu'=>'4711']];
$otherRule = array_replace($rule,['kind'=>'PROHIBIDO',
 'conditions'=>['sector'=>'tecnologia','ciiu'=>'6201']]);
$eval = fn($r,$p) => N::evaluate($r,$p,'2026-10-21');
$missing=$profile;unset($missing['riesgo']);
$scopeA=hash('sha256','tenant-fixture|branch-a');
$scopeB=hash('sha256','tenant-fixture|branch-b');
$created=E::createRecord([],$scopeA,
 ['evidence_ref'=>'fixture:evidence/sample',
  'obligation_ref'=>'fixture:obligation/one','type'=>'EVIDENCIA']);
$denied=false;
try{ E::changeState($created['store'],$scopeA,'fixture:evidence/sample','CUMPLE'); }
catch(\DomainException $e){$denied=true;}
$review=E::changeState($created['store'],$scopeA,
 'fixture:evidence/sample','EN_REVISION');
echo json_encode([
 'a'=>[$eval($rule,$profile),$eval($otherRule,$profile)],
 'b'=>[$eval($rule,$other),$eval($otherRule,$other)],
 'missing'=>$eval($rule,$missing),
 'unapproved'=>$eval(array_replace($rule,['approved'=>false]),$profile),
 'created'=>$created['record'],'review'=>$review['record'],
 'denied'=>$denied,'foreign'=>E::viewRecord($created['store'],$scopeB,'fixture:evidence/sample'),
 'own_count'=>count(E::listRecords($created['store'],$scopeA)),
 'foreign_count'=>count(E::listRecords($created['store'],$scopeB))
],JSON_THROW_ON_ERROR);
"""


@lru_cache(maxsize=1)
def observe() -> dict[str, object]:
    php = shutil.which("php")
    if not php:
        raise AssertionError("UNKNOWN: PHP no disponible")
    completed = subprocess.run(
        [php, "-n", "-d", "allow_url_fopen=0", "-r", PHP_PROBE],
        cwd=ROOT, text=True, capture_output=True, timeout=15, check=False,
        env={"PATH": "/usr/local/bin:/usr/bin:/bin"},
    )
    if completed.returncode:
        raise AssertionError("UNKNOWN: ejecución sintética: " + completed.stderr[-350:])
    data = json.loads(completed.stdout)
    if not isinstance(data, dict):
        raise AssertionError("UNKNOWN: respuesta sintética ambigua")
    return data


def verdict(key: object, rows: object, files: object, obs: object) -> str:
    if (not isinstance(key, str) or key not in GAPS
            or not isinstance(rows, dict) or key not in rows
            or not isinstance(rows[key], tuple) or len(rows[key]) != 5
            or rows[key][2] != "\x60NO_VERIFICABLE\x60"
            or not isinstance(files, dict) or set(files) != set(PATHS)
            or any(not isinstance(v, str) or not v.strip() for v in files.values())
            or not isinstance(obs, dict)):
        return UNKNOWN
    t, p = files["transfer"], files["post"]
    start = p.find("run_construction_migrations() {")
    valid_order = False
    if start >= 0:
        tokens = ("validate_construction_migrations",
                  "run_pre_migration_backup", "run_migration_command apply",
                  'set_post_deploy_phase "recheck"')
        pos = [p.find(x, start) for x in tokens]
        valid_order = all(i >= start for i in pos) and pos == sorted(pos)
    checks = {
        "D-052": (
            "class LegalEntity" in files["legal"]
            and "uniq_legal_tenant_id" in files["legal"]
            and "wrapInTransaction(" in t
            and "$sourceFrom->legalEntity()->id() !== $sourceTo->legalEntity()->id()" in t
            and "### D-052" in files["spec"]
        ),
        "D-053": (
            "CONDOR_PRODUCTION_STAGE:-construction" in p
            and "CONDOR_AUTO_MIGRATE=0" in p
            and "esquema pendiente en live" in p
            and "### D-053" in files["spec"]
        ),
        "D-054": (
            valid_order and "scripts/backup-database.sh" in p
            and "DATABASE_URL" in files["backup"]
            and "test_construction_reconciles_pending_schema_before_cache" in files["posttests"]
            and "### D-054" in files["spec"]
        ),
        "GEST-AC-01": (
            "'REQUIERE_REVISION'" in files["normative"]
            and [x.get("kind") for x in obs.get("a", [])] == ["OBLIGATORIO", "NO_APLICA"]
            and [x.get("kind") for x in obs.get("b", [])] == ["NO_APLICA", "PROHIBIDO"]
            and obs.get("missing", {}).get("kind") == "REQUIERE_REVISION"
            and obs.get("unapproved", {}).get("kind") == "REQUIERE_REVISION"
            and all(x.get("source") for x in obs.get("a", []) + obs.get("b", []))
        ),
        "GEST-AC-02": (
            "'state' => 'PENDIENTE'" in files["evidence"]
            and "['EN_REVISION', 'REQUIERE_REVISION']" in files["evidence"]
            and "STATUS_PENDIENTE" in files["record"]
            and obs.get("created", {}).get("state") == "PENDIENTE"
            and obs.get("review", {}).get("state") == "EN_REVISION"
            and obs.get("denied") is True and obs.get("foreign") is None
            and obs.get("own_count") == 1 and obs.get("foreign_count") == 0
        ),
    }
    return LOCAL if checks[key] else UNKNOWN


def full_evidence(_key: object, _observation: object) -> str:
    return UNKNOWN


class BrechasCondor21Tests(unittest.TestCase):
    @classmethod
    def setUpClass(cls) -> None:
        cls.files = sources()
        cls.rows = matrix_rows(cls.files["matrix"])
        cls.observed = observe()

    def test_contrato_principal(self) -> None:
        self.assertEqual(set(GAPS), set(self.rows))
        for key in GAPS:
            with self.subTest(brecha=key):
                self.assertEqual(verdict(key, self.rows, self.files, self.observed), LOCAL)
                self.assertEqual(full_evidence(key, LOCAL), UNKNOWN)

    def test_falla_cerrado(self) -> None:
        row = next(x for x in self.files["matrix"].splitlines()
                   if x.startswith("| \x60D-052\x60 ·"))
        for text in (
            self.files["matrix"].replace(row, "", 1),
            self.files["matrix"] + "\n" + row,
            self.files["matrix"].replace(
                row, row.replace("\x60NO_VERIFICABLE\x60", "\x60CUBIERTO\x60", 1), 1),
        ):
            with self.assertRaisesRegex(ValueError, UNKNOWN):
                matrix_rows(text)
        for key, src, needle in (
            ("D-052", "transfer", "$sourceFrom->legalEntity()->id() !== $sourceTo->legalEntity()->id()"),
            ("D-053", "post", "esquema pendiente en live"),
            ("D-054", "post", "if run_pre_migration_backup; then"),
            ("GEST-AC-01", "normative", "'REQUIERE_REVISION'"),
            ("GEST-AC-02", "evidence", "'state' => 'PENDIENTE'"),
        ):
            with self.subTest(brecha=key):
                self.assertIn(needle, self.files[src])
                altered = dict(self.files)
                altered[src] = altered[src].replace(needle, "", 1)
                self.assertEqual(verdict(key, self.rows, altered, self.observed), UNKNOWN)
        for key in GAPS:
            self.assertEqual(verdict(key, {}, self.files, self.observed), UNKNOWN)
            self.assertEqual(verdict(key, self.rows, {}, self.observed), UNKNOWN)
            self.assertEqual(verdict(key, self.rows, self.files, {}), UNKNOWN)

    def test_sin_datos_reales(self) -> None:
        for relative in PATHS.values():
            with self.subTest(path=relative):
                self.assertTrue((ROOT / relative).is_file())
                self.assertTrue((ROOT / relative).resolve().is_relative_to(ROOT.resolve()))
        self.assertEqual(self.observed["created"]["evidence_ref"], "fixture:evidence/sample")
        self.assertNotIn("@", json.dumps(self.observed))
        self.assertEqual(full_evidence("D-054", self.observed), UNKNOWN)

    def test_estados_unknown_explicitos(self) -> None:
        for key in GAPS:
            self.assertEqual(self.rows[key][2], "\x60NO_VERIFICABLE\x60")
            self.assertEqual(full_evidence(
                key, verdict(key, self.rows, self.files, self.observed)), UNKNOWN)
        self.assertEqual(self.observed["missing"]["kind"], "REQUIERE_REVISION")
        self.assertTrue(self.observed["denied"])
        self.assertNotEqual(self.observed["review"]["state"], "CUMPLE")


if __name__ == "__main__":
    unittest.main()
