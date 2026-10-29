#!/usr/bin/env python3
"""#1218: GEST-F0/F1/F2/F5/F6, contratos sintéticos fail-closed.

No acredita revisión humana, fase live, autorización ni tríada de PR fusionado.
"""
from __future__ import annotations
import json
import shutil
import subprocess
import unittest
from functools import lru_cache
from pathlib import Path

ROOT = Path(__file__).resolve().parents[1]
MATRIX = "docs/matriz-cobertura.md"
SPEC = "docs/gestion-empresarial-integral.md"
UNKNOWN = "UNKNOWN"
LOCAL = "CONTRATO_SINTETICO"
Q = chr(96)
GAPS = {
    "GEST-F0": ("Fase F0: Taxonomía normativa", ("autorización", "revisión humana", "vigencia normativa")),
    "GEST-F1": ("Fase F1: Motor de cumplimiento", ("cross-tenant/entidad/sede", "privilegio mínimo")),
    "GEST-F2": ("Fase F2: Calendario", ("SHA exacto", "resultado terminal", "fail-closed")),
    "GEST-F5": ("Fase F5: Gobierno", ("consistencia entre controlador, persistencia y permisos",)),
    "GEST-F6": ("Fase F6: Hardening", ("montos", "moneda COP", "transacción inválida")),
}
SOURCES = (MATRIX, SPEC, "src/Application/Compliance/NormativeApplicability.php",
           "src/Application/Compliance/ComplianceApiService.php",
           "src/Application/Commercial/BillingRequest.php")


def sources() -> dict[str, str]:
    return {path: (ROOT / path).read_text(encoding="utf-8") for path in SOURCES}


def rows(document: object) -> dict[str, tuple[str, ...]]:
    """No aceptar fila ausente, duplicada o declarada cubierta por inferencia."""
    if not isinstance(document, str):
        raise ValueError("UNKNOWN: matriz ausente")
    result = {}
    for key, (title, gaps) in GAPS.items():
        prefix = f"| {Q}{key}{Q} · "
        matches = [line for line in document.splitlines() if line.startswith(prefix)]
        if len(matches) != 1:
            raise ValueError("UNKNOWN: fila ausente o duplicada: " + key)
        cells = tuple(part.strip() for part in matches[0].strip("|").split("|"))
        if (len(cells) != 5 or cells[0] != f"{Q}{key}{Q} · {Q}{SPEC}{Q}"
                or not cells[1].startswith(title) or cells[2] != f"{Q}NO_VERIFICABLE{Q}"
                or not cells[3].startswith(f"Falta vincular para {key}")
                or not all(gap in cells[3] for gap in gaps)
                or "tríada código+prueba+PR fusionado" not in cells[3]
                or cells[4] != "#802"):
            raise ValueError("UNKNOWN: fila ambigua " + key)
        result[key] = cells
    return result


def documented(key: str, spec: str) -> bool:
    if key not in GAPS or not isinstance(spec, str):
        return False
    phase = key.removeprefix("GEST-")
    matches = [line for line in spec.splitlines() if line.startswith(f"| {phase} |")]
    return len(matches) == 1 and len(matches[0].split("|")) >= 6


def local_evidence(key: str, content: dict, observed: dict) -> str:
    """F0/F1 tienen pruebas parciales; F6 es sólo un DTO comercial."""
    try:
        if key not in GAPS or any(not content.get(p) for p in SOURCES):
            return UNKNOWN
        if key not in rows(content[MATRIX]) or not documented(key, content[SPEC]):
            return UNKNOWN
        expected = {
            "GEST-F0": {"rule": "OBLIGATORIO", "unreviewed": "REQUIERE_REVISION",
                        "expired": "REQUIERE_REVISION", "foreign_tenant": "REQUIERE_REVISION"},
            "GEST-F1": {"created": "PENDIENTE", "review": "EN_REVISION",
                        "foreign_count": 0, "cross_denied": True, "autocert_denied": True},
            "GEST-F6": {"currency": "COP", "minor": 12500,
                        "zero_denied": True, "lowercase_denied": True},
        }.get(key)
        if expected and all(type(observed.get(k)) is type(v) and observed.get(k) == v
                            for k, v in expected.items()):
            return LOCAL
    except (TypeError, ValueError):
        return UNKNOWN
    # F2 carece de prueba terminal exact-SHA; F5, de controlador/DB/permisos.
    return UNKNOWN


def operational(_key: object, _proof: object = None) -> str:
    """Ningún fixture certifica auditoría legal o piloto real."""
    return UNKNOWN


PHP_PROBE = r"""
require 'src/Application/Compliance/NormativeApplicability.php';
require 'src/Application/Compliance/ComplianceApiService.php';
require 'src/Application/Commercial/BillingRequest.php';
use App\Application\Compliance\NormativeApplicability as N;
use App\Application\Compliance\ComplianceApiService as E;
use App\Application\Commercial\BillingRequest as B;
$src=['kind'=>'official','issuer'=>'authority-fixture','article'=>'article-fixture',
 'version'=>'v1','official_url'=>'https://example.invalid/norma/fixture'];
$p=['tenant_ref'=>'tenant-fixture','sector'=>'comercio','ciiu'=>'4711',
 'municipio'=>'pereira','riesgo'=>'ii','tamano_empresa'=>'micro','regimen'=>'regimen-a'];
$r=['tenant_ref'=>'tenant-fixture','kind'=>'OBLIGATORIO','approved'=>true,
 'reviewer_ref'=>'reviewer-fixture','source'=>$src,
 'effective_from'=>'2026-01-01','effective_until'=>'2026-12-31',
 'verified_on'=>'2026-02-01','conditions'=>['sector'=>'comercio']];
$eval=fn($a,$b)=>N::evaluate($a,$b,'2026-10-21')['kind'];
$a=hash('sha256','tenant-fixture|entity-a|site-a');
$b=hash('sha256','tenant-other|entity-b|site-b');
$created=E::createRecord([],$a,['evidence_ref'=>'fixture:evidence/a',
 'obligation_ref'=>'fixture:obligation/a','type'=>'EVIDENCIA']);
$store=$created['store'];
$review=E::changeState($store,$a,'fixture:evidence/a','EN_REVISION');
$denies=function(callable $fn): bool {
 try {$fn(); return false;} catch(\DomainException $e) {return true;}
};
$bill=['tenant_ref'=>'tenant:fixture-a','subscription_ref'=>'subscription:fixture-a',
 'quote_ref'=>'quote:fixture-a','amount_minor'=>12500,'currency'=>'COP',
 'idempotency_key'=>'billing:fixture-a'];
$valid=B::fromArray($bill)->toArray();
echo json_encode([
 'rule'=>$eval($r,$p),'unreviewed'=>$eval(array_replace($r,['reviewer_ref'=>'']),$p),
 'expired'=>$eval(array_replace($r,['effective_until'=>'2026-01-31']),$p),
 'foreign_tenant'=>$eval($r,array_replace($p,['tenant_ref'=>'tenant-other'])),
 'created'=>$created['record']['state'],'review'=>$review['record']['state'],
 'foreign_count'=>count(E::listRecords($store,$b)),
 'cross_denied'=>$denies(fn()=>E::changeState($store,$b,'fixture:evidence/a','EN_REVISION')),
 'autocert_denied'=>$denies(fn()=>E::changeState($store,$a,'fixture:evidence/a','CUMPLE')),
 'currency'=>$valid['currency'],'minor'=>$valid['amount_minor'],
 'zero_denied'=>$denies(fn()=>B::fromArray(array_replace($bill,['amount_minor'=>0]))),
 'lowercase_denied'=>$denies(fn()=>B::fromArray(array_replace($bill,['currency'=>'cop'])))
],JSON_THROW_ON_ERROR);
"""


@lru_cache(maxsize=1)
def probe() -> dict:
    php = shutil.which("php")
    if not php:
        raise AssertionError("UNKNOWN: PHP no disponible")
    result = subprocess.run(
        [php, "-n", "-d", "allow_url_fopen=0", "-r", PHP_PROBE],
        cwd=ROOT, capture_output=True, text=True, timeout=20, check=False,
        env={"PATH": "/usr/local/bin:/usr/bin:/bin"},
    )
    if result.returncode or result.stderr.strip():
        raise AssertionError("UNKNOWN: probe PHP: " + result.stderr[-450:])
    output = json.loads(result.stdout)
    if not isinstance(output, dict):
        raise AssertionError("UNKNOWN: resultado no estructurado")
    return output


class BrechasCondor23Tests(unittest.TestCase):
    @classmethod
    def setUpClass(cls) -> None:
        cls.content = sources()
        cls.matrix_rows = rows(cls.content[MATRIX])
        cls.observed = probe()

    def test_contrato_principal(self) -> None:
        """AC-01: todos los IDs con evidencia parcial o brecha explícita."""
        self.assertEqual(set(self.matrix_rows), set(GAPS))
        for key in GAPS:
            with self.subTest(key=key):
                self.assertTrue(documented(key, self.content[SPEC]))
                self.assertEqual(self.matrix_rows[key][2], f"{Q}NO_VERIFICABLE{Q}")
                expectation = LOCAL if key in {"GEST-F0", "GEST-F1", "GEST-F6"} else UNKNOWN
                self.assertEqual(local_evidence(key, self.content, self.observed), expectation)
                self.assertEqual(operational(key, self.observed), UNKNOWN)

    def test_falla_cerrado(self) -> None:
        """AC-02: rechazar ausencia, duplicación y falsa aprobación."""
        matrix = self.content[MATRIX]
        for key in GAPS:
            row = next(x for x in matrix.splitlines() if x.startswith(f"| {Q}{key}{Q} · "))
            for changed in (
                matrix.replace(row, "", 1),
                matrix + "\n" + row,
                matrix.replace(row, row.replace("NO_VERIFICABLE", "CUBIERTO", 1), 1),
                matrix.replace(row, row.replace("Falta vincular", "Cumplido", 1), 1),
            ):
                with self.subTest(key=key):
                    with self.assertRaisesRegex(ValueError, UNKNOWN):
                        rows(changed)
            modified = dict(self.content)
            modified[SPEC] = modified[SPEC].replace(
                "| " + key.removeprefix("GEST-") + " |", "| INEXISTENTE |", 1)
            self.assertEqual(local_evidence(key, modified, self.observed), UNKNOWN)
            self.assertEqual(local_evidence(key, self.content, {}), UNKNOWN)
            self.assertEqual(operational(key, {"status": "success"}), UNKNOWN)
        self.assertEqual(local_evidence("GEST-F3", self.content, self.observed), UNKNOWN)

    def test_sin_datos_reales(self) -> None:
        """AC-03: solo pruebas puras PHP y fixtures, sin red ni PII."""
        for path in SOURCES:
            with self.subTest(path=path):
                self.assertTrue((ROOT / path).is_file())
                self.assertTrue((ROOT / path).resolve().is_relative_to(ROOT.resolve()))
        self.assertIn("example.invalid", PHP_PROBE)
        self.assertNotIn("file_get_contents(", PHP_PROBE)
        self.assertNotIn("curl_", PHP_PROBE)
        self.assertIn("fixture:evidence/a", PHP_PROBE)
        self.assertNotIn("@", json.dumps(self.observed))

    def test_estados_unknown_explicitos(self) -> None:
        """AC-04: prueba local no se convierte en producción certificada."""
        for key in GAPS:
            self.assertEqual(self.matrix_rows[key][2], f"{Q}NO_VERIFICABLE{Q}")
            self.assertEqual(operational(key, self.observed), UNKNOWN)
        self.assertEqual(local_evidence("GEST-F2", self.content, self.observed), UNKNOWN)
        self.assertEqual(local_evidence("GEST-F5", self.content, self.observed), UNKNOWN)
        self.assertEqual(self.observed["unreviewed"], "REQUIERE_REVISION")
        self.assertTrue(self.observed["cross_denied"])
        self.assertTrue(self.observed["autocert_denied"])
        self.assertTrue(self.observed["zero_denied"])


if __name__ == "__main__":
    unittest.main()
