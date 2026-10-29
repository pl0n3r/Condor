#!/usr/bin/env python3
"""Condor #1326: ejecutar codigo real con fixtures sinteticos, sin atestar live."""
from __future__ import annotations

import importlib.util
import json
import os
import re
import shutil
import subprocess
import sys
import unittest
from pathlib import Path

ROOT = Path(__file__).resolve().parents[1]
MATRIX = ROOT / "docs/matriz-cobertura.md"
IDS = (
    "D-040-S13", "D-040-S14", "D-040-S15", "D-040-S16",
    "D-040-S17", "D-040-S18", "D-040-S19", "D-040-S20",
    "D-041", "D-041-S02", "D-041-S03", "D-041-S04",
)
# A = ejecutable offline en codigo real. La cobertura integral permanece UNKNOWN.
A = {
    "D-040-S13": "scripts/verify_symfony_lts_dependency_guard.py",
    "D-040-S14": "scripts/live-schema-policy.php",
    "D-040-S19": "tests/test_billing_idempotency.py",
    "D-040-S20": "scripts/ci_change_classifier.py",
}
# B = requiere integracion/navegador/entorno externo; C = baseline normativa.
# El motivo de cada una se replica en el PR; NO son pruebas acreditadas.
REST = {
    "D-040-S15": ("B", "IDOR transversal exige HTTP, autenticacion y organizaciones distintas"),
    "D-040-S16": ("B", "XSS transversal exige render Twig y React en navegador"),
    "D-040-S17": ("B", "MIME y descarga requieren HTTP, parser y almacenamiento aislado"),
    "D-040-S18": ("B", "DNS rebinding exige resolucion y red controlada"),
    "D-041": ("C", "baseline global de diseno, no comportamiento autonomo"),
    "D-041-S02": ("B", "tokens requieren computed-style de navegador"),
    "D-041-S03": ("B", "densidad y superficies requieren layout renderizado"),
    "D-041-S04": ("B", "componentes React requieren arbol renderizado y estados"),
}


def matrix_rows(source: object) -> dict[str, tuple[str, ...]]:
    if not isinstance(source, str):
        raise ValueError("UNKNOWN: matriz ausente")
    result: dict[str, tuple[str, ...]] = {}
    pattern = re.compile(r"^\|\s*" + chr(96) + r"(D-040-S\d+|D-041(?:-S\d+)?)" + chr(96))
    for line in source.splitlines():
        match = pattern.match(line)
        if match is None or match.group(1) not in IDS:
            continue
        key = match.group(1)
        fields = tuple(part.strip() for part in line.strip().strip("|").split("|"))
        first = chr(96) + key + chr(96) + " · " + chr(96) + "ESPECIFICACIONES.md" + chr(96)
        if (key in result or len(fields) != 5 or not all(fields)
                or fields[0] != first or fields[2] != chr(96) + "NO_VERIFICABLE" + chr(96)
                or "Falta evidencia integral" not in fields[3] or fields[4] != "#1"):
            raise ValueError("UNKNOWN: fila duplicada o invalida " + key)
        result[key] = fields
    if set(result) != set(IDS):
        raise ValueError("UNKNOWN: inventario incompleto")
    return result


def module(path: str):
    file = ROOT / path
    if not file.is_file() or file.is_symlink():
        raise AssertionError("UNKNOWN: fuente ausente " + path)
    spec = importlib.util.spec_from_file_location("condor_1326_" + file.stem, file)
    if spec is None or spec.loader is None:
        raise AssertionError("UNKNOWN: import imposible")
    obj = importlib.util.module_from_spec(spec)
    sys.modules[spec.name] = obj
    try:
        spec.loader.exec_module(obj)
    except BaseException:
        sys.modules.pop(spec.name, None)
        raise
    return obj


def isolated_env() -> dict[str, str]:
    return {key: os.environ[key] for key in ("PATH", "LANG", "LC_ALL") if key in os.environ}


def schema_fixture(**changes: object) -> dict[str, object]:
    data: dict[str, object] = {
        "classification": "additive", "dry_run_valid": True,
        "allowlist_complete": True, "backup_receipt_verified": True,
        "post_checks": {"service_readiness": True, "schema": True, "smoke": True},
    }
    data.update(changes)
    return data


def schema_policy(payload: object) -> dict[str, object]:
    path = ROOT / A["D-040-S14"]
    php = shutil.which("php")
    if not php or not path.is_file():
        raise AssertionError("UNKNOWN: PHP o politica no disponible")
    process = subprocess.run([php, str(path)], input=json.dumps(payload),
                             text=True, capture_output=True, env=isolated_env(),
                             cwd=ROOT, timeout=15, check=False)
    if process.returncode != 0:
        raise AssertionError("UNKNOWN: politica invalida")
    result = json.loads(process.stdout)
    if not isinstance(result, dict) or result.get("auto_execute") is not False:
        raise AssertionError("UNKNOWN: salida politica inesperada")
    return result


def billing_regression() -> unittest.TestResult:
    """Ejecuta las dos regresiones PHP existentes contra seis clases REALES.

    Bootstrap PHP puro sin vendor/autoload y sin conectar proveedores.
    """
    m = module(A["D-040-S19"])
    classes = ("BillingRequest.php", "BillingResult.php", "BillingAdapter.php",
               "BillingIdempotencyRecord.php", "BillingIdempotencyStore.php",
               "BillingSubmissionCoordinator.php")
    prefix = "\n".join("require 'src/Application/Commercial/" + name + "';"
                       for name in classes)

    def run_php(script: str) -> dict[str, object]:
        if script.count("require 'vendor/autoload.php';") != 1:
            raise AssertionError("UNKNOWN: script de facturacion alterado")
        if any(not (ROOT / "src/Application/Commercial" / name).is_file()
               for name in classes):
            raise AssertionError("UNKNOWN: clases de facturacion ausentes")
        process = subprocess.run(
            [shutil.which("php") or "php", "-r",
             script.replace("require 'vendor/autoload.php';", prefix)],
            capture_output=True, text=True, cwd=ROOT, env=isolated_env(),
            timeout=20, check=False,
        )
        if process.returncode != 0:
            raise AssertionError("UNKNOWN: regresion de PHP fallo")
        return json.loads(process.stdout)

    m.run_php = run_php
    suite = unittest.defaultTestLoader.loadTestsFromTestCase(m.BillingIdempotencyTests)
    result = unittest.TestResult()
    suite.run(result)
    return result


class CoberturaCondor07Tests(unittest.TestCase):
    @classmethod
    def setUpClass(cls) -> None:
        cls.rows = matrix_rows(MATRIX.read_text(encoding="utf-8"))

    def test_contrato_principal(self) -> None:
        """AC-01: cuatro casos A ejercen codigo real; ocho casos B/C son explicitos."""
        self.assertEqual(set(IDS), set(A) | set(REST))
        self.assertEqual(set(A) & set(REST), set())
        self.assertEqual(set(self.rows), set(IDS))
        for key, (category, reason) in REST.items():
            self.assertIn(category, ("B", "C", "D"), key)
            self.assertGreater(len(reason), 20)
            self.assertEqual(self.rows[key][2], chr(96) + "NO_VERIFICABLE" + chr(96))

        # Dependencias: el validador real rechaza Symfony 8 frente a 7.4 LTS.
        guard = module(A["D-040-S13"])
        composer = {"require": {"symfony/http-kernel": "7.4.*"}, "conflict": {}}
        lock = {"packages": [{"name": "symfony/http-kernel", "version": "v7.4.4"}],
                "packages-dev": [], "content-hash": guard.composer_content_hash(composer)}
        self.assertEqual([], guard.static_errors(composer, lock))
        incompatible = {"require": {"symfony/http-kernel": "8.0.*"}, "conflict": {}}
        bad_lock = {**lock, "content-hash": guard.composer_content_hash(incompatible)}
        self.assertTrue(any("7.4.*" in x for x in guard.static_errors(incompatible, bad_lock)))

        # Destructivo: politica PHP REAL siempre pide gate humano.
        eligible = schema_policy(schema_fixture())
        self.assertEqual("eligible_after_owner_gate", eligible["reason"])
        self.assertTrue(eligible["eligible_after_owner_gate"])
        self.assertEqual("owner_gate_required", eligible["authority"])
        denied = schema_policy(schema_fixture(classification="destructive"))
        self.assertEqual("destructive_blocked", denied["reason"])
        self.assertFalse(denied["eligible_after_owner_gate"])

        # Idempotencia: BillingSubmissionCoordinator y sus dependencias reales.
        result = billing_regression()
        self.assertEqual(result.testsRun, 2)
        self.assertTrue(result.wasSuccessful(), result.errors + result.failures)

        # CI: selector real falla cerrado para cambios de seguridad y desconocidos.
        ci = module(A["D-040-S20"])
        sensitive = ci.classify(["config/packages/security.yaml"], "pull_request")
        self.assertTrue(sensitive.categoria_seguridad)
        self.assertTrue(sensitive.validacion_completa)
        self.assertTrue(sensitive.pruebas_base)
        unknown = ci.classify(["ruta-imprevista.xyz"], "pull_request")
        self.assertTrue(unknown.validacion_completa)
        self.assertEqual("ruta-desconocida-fail-safe", unknown.motivo)

    def test_falla_cerrado(self) -> None:
        """AC-02: duplicados, filas ausentes y permisos incompletos no pasan."""
        source = MATRIX.read_text(encoding="utf-8")
        for key in IDS:
            row = next(line for line in source.splitlines()
                       if line.startswith("| " + chr(96) + key + chr(96) + " ·"))
            for bad in (source.replace(row + "\n", "", 1),
                        source + "\n" + row,
                        source.replace(row, row.replace("NO_VERIFICABLE", "CUBIERTO"), 1)):
                with self.subTest(clause=key):
                    with self.assertRaises(ValueError):
                        matrix_rows(bad)
        guard = module(A["D-040-S13"])
        with self.assertRaises(guard.GuardError):
            guard.static_errors({}, {"packages": ["invalid"]})
        for payload in (
            schema_fixture(backup_receipt_verified=False),
            schema_fixture(allowlist_complete="true"),
            schema_fixture(classification="unknown"),
            schema_fixture(post_checks={"service_readiness": True}),
        ):
            with self.subTest(payload=str(payload)):
                answer = schema_policy(payload)
                self.assertFalse(answer["eligible_after_owner_gate"])
                self.assertFalse(answer["auto_execute"])
        self.assertTrue(module(A["D-040-S20"]).classify([], "pull_request").validacion_completa)

    def test_sin_datos_reales(self) -> None:
        """AC-03: sin secretos ni datos reales ni proveedor; no se declara go-live."""
        for path in (MATRIX, *(ROOT / p for p in A.values())):
            self.assertTrue(path.is_file())
            self.assertTrue(path.resolve().is_relative_to(ROOT))
        self.assertTrue(all(x in ("B", "C", "D") for x, _ in REST.values()))
        self.assertFalse(schema_policy(schema_fixture())["auto_execute"])
        own = Path(__file__).read_text(encoding="utf-8")
        self.assertNotIn("-----BEGIN PRIVATE KEY-----", own)
        self.assertNotIn("https://api.github.com", own)


if __name__ == "__main__":
    unittest.main()
