#!/usr/bin/env python3
"""Condor #1220: cinco brechas GEST-FLUJO, contratos sintéticos y UNKNOWN.

No acredita retención legal, aprobación, auditoría real, E2E ni go-live.
"""
from __future__ import annotations

import ast
from functools import lru_cache
import json
from pathlib import Path
import shutil
import subprocess
import unittest

ROOT = Path(__file__).resolve().parents[1]
MATRIX = "docs/matriz-cobertura.md"
SPEC = "docs/gestion-empresarial-integral.md"
SERVICE = "src/Application/Documents/DocumentApiService.php"
CONTROLLER = "src/Http/Controller/DocumentsController.php"
REVIEW = "src/Application/Compliance/NormativeReviewProjection.php"
UNKNOWN = "UNKNOWN"
GAP = "BRECHA_DOCUMENTADA"
CLAUSES = {
    "GEST-FLUJO-1-P05": (
        "Alertas, hallazgos y auditoría muestran qué falta y por qué; nunca bloquean un acto legalmente indispensable.",
        "5. Alertas, hallazgos y auditoría muestran qué falta y por qué; nunca bloquean un acto legalmente indispensable.",
    ),
    "GEST-FLUJO-2": ("Documento controlado", "### 2. Documento controlado"),
    "GEST-FLUJO-2-P01": (
        "Se clasifica el documento y se aplica su política de acceso/retención.",
        "1. Se clasifica el documento y se aplica su política de acceso/retención.",
    ),
    "GEST-FLUJO-2-P02": (
        "Se carga una nueva versión con integridad, autor, vigencia y relación al proceso.",
        "2. Se carga una nueva versión con integridad, autor, vigencia y relación al proceso.",
    ),
    "GEST-FLUJO-2-P03": (
        "El aprobador competente acepta, rechaza o solicita cambio; la versión anterior permanece trazable.",
        "3. El aprobador competente acepta, rechaza o solicita cambio; la versión anterior permanece trazable.",
    ),
}
SOURCES = (MATRIX, SPEC, SERVICE, CONTROLLER, REVIEW)

# Ejercicio de la clase PHP REAL. Todo estado reside en arrays de este proceso.
# No hay red, base de datos, autenticación real, firmas ni archivos de clientes.
PHP_PROBE = r'''
require 'src/Application/Documents/DocumentApiService.php';
use App\Application\Documents\DocumentApiService as D;
$d = new D();
$state = [];
$a = $d->create($state, 'tenant-a|entity-a|branch-a', 'fixture:doc/synthetic_a', 'INTERNA');
$state = $a['state'];
$b = $d->create($state, 'tenant-b|entity-b|branch-b', 'fixture:doc/synthetic_b', 'RESERVADA');
$state = $b['state'];
function denied(callable $f): bool {
  try { $f(); return false; } catch (\DomainException $e) { return true; }
}
$list_a = $d->listing($state, 'tenant-a|entity-a|branch-a');
$list_b = $d->listing($state, 'tenant-b|entity-b|branch-b');
$foreign = denied(fn()=>$d->detail($state, 'tenant-b|entity-b|branch-b', 'fixture:doc/synthetic_a'));
$entity = denied(fn()=>$d->detail($state, 'tenant-a|entity-b|branch-a', 'fixture:doc/synthetic_a'));
$branch = denied(fn()=>$d->detail($state, 'tenant-a|entity-a|branch-b', 'fixture:doc/synthetic_a'));
$bad_class = denied(fn()=>$d->create($state, 'tenant-a|entity-a|branch-a', 'fixture:doc/invalid', 'SECRETA'));
$bad_ref = denied(fn()=>$d->create($state, 'tenant-a|entity-a|branch-a', 'https://invalid.test/private', 'INTERNA'));
$review = $d->requestReview($state, 'tenant-a|entity-a|branch-a', 'fixture:doc/synthetic_a');
$state = $review['state'];
$again = $d->requestReview($state, 'tenant-a|entity-a|branch-a', 'fixture:doc/synthetic_a');
echo json_encode([
  'first'=>$a['document'], 'second'=>$b['document'],
  'list_a'=>$list_a, 'list_b'=>$list_b,
  'foreign_denied'=>$foreign, 'entity_denied'=>$entity, 'branch_denied'=>$branch,
  'bad_class_denied'=>$bad_class, 'bad_ref_denied'=>$bad_ref,
  'requested'=>$review['document'], 'changed'=>$review['changed'],
  'again_changed'=>$again['changed'],
  'after'=>$d->detail($state, 'tenant-a|entity-a|branch-a', 'fixture:doc/synthetic_a'),
], JSON_THROW_ON_ERROR);
'''


def sources() -> dict[str, str]:
    return {path: (ROOT / path).read_text(encoding="utf-8") for path in SOURCES}


def matrix_rows(raw: object) -> dict[str, tuple[str, ...]]:
    """Rechaza filas ausentes, duplicadas, incompletas o promovidas sin PR."""
    if not isinstance(raw, str):
        raise ValueError("UNKNOWN: matriz ilegible")
    found: dict[str, tuple[str, ...]] = {}
    for line in raw.splitlines():
        if not line.startswith("| `GEST-FLUJO-"):
            continue
        cells = tuple(v.strip() for v in line.strip("|").split("|"))
        key = cells[0].split(" · ", 1)[0].strip(chr(96))
        if key not in CLAUSES:
            continue
        expected = f"`{key}` · `{SPEC}`"
        if (key in found or len(cells) != 5 or cells[0] != expected
                or cells[1] != CLAUSES[key][0]
                or cells[2] != "`NO_VERIFICABLE`"
                or f"Falta vincular para {key} " not in cells[3]
                or "no se identificó tríada código+prueba+PR fusionado" not in cells[3]
                or cells[4] != "#802"):
            raise ValueError("UNKNOWN: fila ambigua " + key)
        found[key] = cells
    if set(found) != set(CLAUSES):
        raise ValueError("UNKNOWN: falta fila de cobertura")
    return found


def normative_document(raw: object) -> bool:
    if not isinstance(raw, str):
        return False
    lines = raw.splitlines()
    return (
        all(lines.count(line) == 1 for _, line in CLAUSES.values())
        and "La decisión B de" in raw
        and "solo se admite diseño y fixtures sintéticos" in raw
        and "Un documento cargado no demuestra cumplimiento" in raw
    )


def classify(key: object, data: object) -> str:
    """Acredita únicamente que el límite y el código parcial están contrastados."""
    if key not in CLAUSES or type(data) is not dict:
        return UNKNOWN
    if any(not isinstance(data.get(path), str) or not data[path] for path in SOURCES):
        return UNKNOWN
    try:
        matrix_rows(data[MATRIX])
    except ValueError:
        return UNKNOWN
    if not normative_document(data[SPEC]):
        return UNKNOWN
    if not all(needle in data[SERVICE] for needle in (
        "final class DocumentApiService", "CLASSIFICATIONS",
        "PENDIENTE_REVISION", "requireReference", "public function listing",
        "public function requestReview",
    )):
        return UNKNOWN
    if not all(needle in data[CONTROLLER] for needle in (
        "authorizedBranchScope", "requireCsrf", "settings.view",
    )):
        return UNKNOWN
    if not all(needle in data[REVIEW] for needle in (
        "REQUIERE_REVISION", "PENDIENTE", "reviewer_ref", "effective_from",
        "effective_until", "evidence_ref",
    )):
        return UNKNOWN
    return GAP


def operational_state(_key: object, _evidence: object = None) -> str:
    """Ningún test local certifica acto legal, retención ni aprobación."""
    return UNKNOWN


@lru_cache(maxsize=1)
def exercise() -> dict:
    php = shutil.which("php")
    if php is None:
        raise AssertionError("UNKNOWN: falta PHP CLI para fixture local")
    run = subprocess.run(
        [php, "-r", PHP_PROBE], cwd=ROOT,
        text=True, capture_output=True, timeout=20, check=False,
    )
    if run.returncode or run.stderr.strip():
        raise AssertionError("UNKNOWN: fixture PHP falló: " + run.stderr[-500:])
    value = json.loads(run.stdout)
    if not isinstance(value, dict):
        raise AssertionError("UNKNOWN: resultado no es objeto")
    return value


class BrechasCondor25Tests(unittest.TestCase):
    def test_contrato_principal(self) -> None:
        """AC-01: cinco IDs, comportamiento real parcial y brecha explícita."""
        data = sources()
        self.assertEqual(len(CLAUSES), 5)
        self.assertEqual(set(matrix_rows(data[MATRIX])), set(CLAUSES))
        self.assertTrue(normative_document(data[SPEC]))
        for key in CLAUSES:
            with self.subTest(key=key):
                self.assertEqual(GAP, classify(key, data))
                self.assertEqual(UNKNOWN, operational_state(key, data))

        proof = exercise()
        self.assertEqual(proof["first"]["classification"], "INTERNA")
        self.assertEqual(proof["second"]["classification"], "RESERVADA")
        self.assertEqual(len(proof["list_a"]), 1)
        self.assertEqual(len(proof["list_b"]), 1)
        self.assertEqual(proof["list_a"][0]["reference"], "fixture:doc/synthetic_a")
        self.assertEqual(proof["list_b"][0]["reference"], "fixture:doc/synthetic_b")
        for key in ("foreign_denied", "entity_denied", "branch_denied",
                    "bad_class_denied", "bad_ref_denied"):
            self.assertIs(proof[key], True, key)
        self.assertEqual(proof["first"]["status"], "BORRADOR")
        self.assertEqual(proof["requested"]["status"], "PENDIENTE_REVISION")
        self.assertEqual(proof["after"]["status"], "PENDIENTE_REVISION")
        self.assertIs(proof["changed"], True)
        self.assertIs(proof["again_changed"], False)
        # P02/P03: metadata de versión, firma, retención y decisión legal AUSENTE.
        self.assertEqual(set(proof["after"]), {"reference", "classification", "status"})
        self.assertNotIn("APROBADO", json.dumps(proof))
        # P05: la proyección normativa lleva fuentes y revisión humana; no autoriza.
        self.assertIn("REQUIERE_REVISION", data[REVIEW])
        self.assertIn("nunca bloquean un acto legalmente indispensable", data[SPEC])

    def test_falla_cerrado(self) -> None:
        """AC-02: ninguna ambigüedad o manipulación puede virar a cobertura."""
        data = sources()
        original = data[MATRIX]
        for key in CLAUSES:
            line = next(s for s in original.splitlines()
                        if s.startswith(f"| `{key}` ·"))
            for mutant in (
                original.replace(line, "", 1),
                original + "\n" + line,
                original.replace(line, line.replace("NO_VERIFICABLE", "CUBIERTO"), 1),
            ):
                with self.subTest(key=key):
                    with self.assertRaisesRegex(ValueError, "UNKNOWN"):
                        matrix_rows(mutant)
                    changed = dict(data, **{MATRIX: mutant})
                    self.assertEqual(UNKNOWN, classify(key, changed))
            wrong_doc = dict(data)
            wrong_doc[SPEC] = data[SPEC].replace(CLAUSES[key][1], "", 1)
            self.assertEqual(UNKNOWN, classify(key, wrong_doc))
        for key in CLAUSES:
            self.assertEqual(UNKNOWN, classify(key, {}))
            for path in (SERVICE, CONTROLLER, REVIEW):
                damaged = dict(data)
                damaged[path] = ""
                self.assertEqual(UNKNOWN, classify(key, damaged))
        self.assertEqual(UNKNOWN, classify("GEST-FLUJO-9", data))
        self.assertEqual(UNKNOWN, operational_state("GEST-FLUJO-2", {
            "ci": "success", "sha": "a" * 40, "approval": "APPROVED",
        }))

    def test_sin_datos_reales(self) -> None:
        """AC-03: solo clases PHP puras y referencias de fixture; sin red."""
        source = (ROOT / "tests/test_brechas_condor_25.py").read_text(encoding="utf-8")
        tree = ast.parse(source)
        imports = {name.name.split(".")[0] for item in ast.walk(tree)
                   if isinstance(item, ast.Import) for name in item.names}
        imports |= {item.module.split(".")[0] for item in ast.walk(tree)
                    if isinstance(item, ast.ImportFrom) and item.module}
        self.assertFalse(imports & {
            "requests", "urllib", "httpx", "socket", "pymysql", "mysql", "playwright",
        })
        self.assertEqual(set(SOURCES), {MATRIX, SPEC, SERVICE, CONTROLLER, REVIEW})
        for forbidden in ("curl_", "PDO", "file_get_contents", "shell_exec",
                          "https://", "http://", "file_put_contents"):
            self.assertNotIn(forbidden, PHP_PROBE)
        self.assertNotIn("token", PHP_PROBE.lower())
        self.assertEqual(exercise()["first"]["reference"], "fixture:doc/synthetic_a")

    def test_estados_unknown_explicitos(self) -> None:
        """AC-04: ni evidencia fabricada ni código local son operación real."""
        data = sources()
        for key in CLAUSES:
            self.assertEqual(GAP, classify(key, data))
            for evidence in (
                None, {}, True, {"ci": "success"},
                {"state": "VALIDATED_IN_PRODUCTION", "sha": "f" * 40},
                {"document": "APROBADO", "actor": "admin"},
                {"version": "v2", "hash": "synthetic"},
                {"retention": "applied"}, {"legal_review": "verified"},
            ):
                with self.subTest(key=key, evidence=str(evidence)):
                    self.assertEqual(UNKNOWN, operational_state(key, evidence))
        self.assertEqual("PENDIENTE_REVISION", exercise()["after"]["status"])
        self.assertNotIn("APROBADO", json.dumps(exercise()))


if __name__ == "__main__":
    unittest.main()
