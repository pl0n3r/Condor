#!/usr/bin/env python3
"""Contratos offline D-015/016/024/026. No certifican producción ni go-live.

Ejercitan la selección de CI y dos métodos PHP reales, y conservan UNKNOWN
cuando faltan recibos de ejecución exact-SHA. Ninguna prueba llama a red,
modifica datos de clientes ni ejecuta workflows/deploy.
"""
from __future__ import annotations

import json
import re
import shutil
import subprocess
import unittest
from pathlib import Path

from scripts.ci_change_classifier import classify

ROOT = Path(__file__).resolve().parents[1]
SHA = "a" * 40
UNKNOWN = "UNKNOWN"
CODE_ONLY = "CODE_ONLY"


def contents(path: str) -> str:
    target = ROOT / path
    if not target.is_file():
        raise ValueError("Ruta de contrato inexistente: " + path)
    return target.read_text(encoding="utf-8")


def e2e_job(workflow: str) -> str:
    """Extrae exclusivamente el job E2E, no coincidencias de otros jobs."""
    match = re.search(
        r"^  e2e:\s*\n(.*?)(?=^  [a-z][a-z0-9_-]*:\s*\n|\Z)",
        workflow, flags=re.M | re.S,
    )
    if match is None:
        raise ValueError("Job E2E ausente")
    return match.group(1)


def access_contract(source: str) -> bool:
    """Un fragmento incompleto o que abre admin/API falla cerrado."""
    needed = (
        "enable_csrf: true",
        "roles: ROLE_PLATFORM_OWNER",
        "roles: ROLE_USER",
        "user_checker:",
        "login_throttling:",
    )
    if any(item not in source for item in needed):
        return False
    # Verificar la regla asociada a su ruta, no encontrar un rol en otro bloque.
    return all(re.search(
        rf"- path: [^\n]*{re.escape(path)}[^\n]*\n\s+roles: {role}\b",
        source,
    ) for path, role in (
        ("adminpl0n3r", "ROLE_PLATFORM_OWNER"),
        ("api/v1", "ROLE_USER"),
    ))


def receipt_status(receipt: object, expected_sha: str, *, authorized: bool) -> str:
    """Evalúa un recibo sintético; CODE_ONLY nunca representa un deploy real."""
    if not authorized or not isinstance(receipt, dict):
        return UNKNOWN
    if not re.fullmatch(r"[0-9a-f]{40}", expected_sha):
        return UNKNOWN
    if set(receipt) != {"head_sha", "status", "conclusion", "event", "pending"}:
        return UNKNOWN
    if (receipt["head_sha"] != expected_sha
            or receipt["status"] != "completed"
            or receipt["conclusion"] != "success"
            or receipt["event"] != "pull_request"
            or receipt["pending"] != []):
        return UNKNOWN
    return CODE_ONLY


def php_evaluate(expression: str, path: str) -> str:
    """Ejecuta el método real con PHP local, sin Composer, BD o red."""
    executable = shutil.which("php")
    if executable is None:
        raise AssertionError("PHP local ausente; no afirmar comportamiento verificado")
    result = subprocess.run(
        [executable, "-r", f'require "{path}"; {expression}'],
        cwd=ROOT, capture_output=True, text=True, timeout=12, check=False,
    )
    if result.returncode:
        raise AssertionError("Contrato PHP no ejecutable: " + result.stderr[:600])
    return result.stdout


class ContrasteDL4Tests(unittest.TestCase):
    def test_contrato_principal(self) -> None:
        # D-015: ejecución real del clasificador. Las pruebas y el E2E
        # seleccionan sus gates; un cambio de documentación no simula E2E.
        test_sel = classify(["tests/test_contraste_d_l4.py"], "pull_request")
        browser_sel = classify(["tests/e2e/harness.spec.mjs"], "pull_request")
        docs_sel = classify(["docs/contraste-cd_d1.md"], "pull_request")
        self.assertTrue(test_sel.pruebas_base)
        self.assertTrue(browser_sel.e2e)
        self.assertTrue(browser_sel.pruebas_base)
        self.assertFalse(docs_sel.e2e)

        harness = contents("tests/e2e/harness.spec.mjs")
        config = contents("playwright.config.mjs")
        workflow = e2e_job(contents(".github/workflows/ci.yml"))
        package = json.loads(contents("package.json"))
        self.assertIn("getByRole(", harness)
        self.assertIn("getByTestId(", harness)
        self.assertIn("role=\"status\"", harness)
        self.assertIn("trace: 'retain-on-failure'", config)
        self.assertIn("video: 'retain-on-failure'", config)
        self.assertIn("if: failure()", workflow)
        self.assertIn("retention-days: 14", workflow)
        self.assertEqual(
            package["scripts"]["test:e2e"], "playwright test --project=chromium",
        )
        self.assertTrue((ROOT / "package-lock.json").is_file())
        self.assertTrue((ROOT / "tests/contract/test_tooling_contract.py").is_file())
        self.assertTrue((ROOT / "tests/integration/test_tooling_integration.py").is_file())

        # D-016: configuración exacta del runner, no telemetría inventada.
        self.assertRegex(workflow, r"(?m)^\s+runs-on: ubuntu-24\.04\s*$")
        self.assertIn("google-chrome --version", workflow)
        self.assertIn("playwright install ffmpeg", workflow)
        self.assertNotRegex(workflow, r"playwright install (?:chromium|chrome)\b")
        self.assertIn("channel: 'chrome'", config)

        # D-024: se ejecuta el método de resolución real (sin EntityManager).
        response = php_evaluate(
            r'echo json_encode(['
            r'\App\Infrastructure\Tenancy\TenantResolver::isPlatformHost("CONDORAPP.COM.CO."),'
            r'\App\Infrastructure\Tenancy\TenantResolver::isPlatformHost("otro.example.test")'
            r']);',
            "src/Infrastructure/Tenancy/TenantResolver.php",
        )
        self.assertEqual(json.loads(response), [True, False])
        self.assertTrue(access_contract(contents("config/packages/security.yaml")))
        composer = json.loads(contents("composer.json"))
        self.assertIn("doctrine/orm", composer["require"])
        self.assertIn("doctrine/doctrine-migrations-bundle", composer["require"])
        self.assertIn("migrations_paths:", contents("config/packages/doctrine_migrations.yaml"))
        self.assertIn("build:admin", package["scripts"])
        self.assertIn("React", contents("ESPECIFICACIONES.md"))

        # D-026: comportamiento real de add-ons opcionales vs escala derivada.
        response = php_evaluate(
            r'echo json_encode(['
            r'\App\Application\Commercial\PlanConfigurationRules::isSelectableAddOn("production-lite"),'
            r'\App\Application\Commercial\PlanConfigurationRules::isSelectableAddOn("extra-user")'
            r']);',
            "src/Application/Commercial/PlanConfigurationRules.php",
        )
        self.assertEqual(json.loads(response), [True, False])
        quote = contents("src/Application/Commercial/PlanQuoteService.php")
        self.assertIn("unset($clientSuppliedTotal)", quote)
        self.assertIn("throw new DomainException(", quote)
        self.assertIn("!is_int($base)", quote)
        self.assertTrue((ROOT / "tests/php/Application/Commercial/PlanQuoteTest.php").is_file())

    def test_falla_cerrado(self) -> None:
        # D-015: sin diff o ruta desconocida => CI completo, no skip seguro.
        for paths in ([], ["extra/archivo.no-conocido"]):
            with self.subTest(paths=paths):
                chosen = classify(paths, "pull_request")
                self.assertTrue(chosen.validacion_completa)
                self.assertTrue(chosen.e2e)
        self.assertRaises(ValueError, e2e_job, "jobs: {}\n")
        security = contents("config/packages/security.yaml")
        self.assertTrue(access_contract(security))
        for change in ("enable_csrf: true", "roles: ROLE_PLATFORM_OWNER",
                       "roles: ROLE_USER", "user_checker:"):
            with self.subTest(change=change):
                self.assertFalse(access_contract(security.replace(change, "", 1)))
        # D-026: un add-on reservado a cantidades no se puede comprar a mano.
        response = php_evaluate(
            r'echo json_encode(['
            r'\App\Application\Commercial\PlanConfigurationRules::isSelectableAddOn("extra-location"),'
            r'\App\Application\Commercial\PlanConfigurationRules::isSelectableAddOn("extra-company")'
            r']);',
            "src/Application/Commercial/PlanConfigurationRules.php",
        )
        self.assertEqual(json.loads(response), [False, False])

    def test_sin_datos_reales(self) -> None:
        # Los datos de prueba son exclusivamente identificadores inventados;
        # el evaluador devuelve un estado, nunca refleja claves ni payloads.
        marker = "MARCADOR_SINTETICO_NUNCA_PUBLICAR"
        synthetic = {
            "head_sha": SHA, "status": "completed", "conclusion": "success",
            "event": "pull_request", "pending": [],
        }
        self.assertEqual(receipt_status(synthetic, SHA, authorized=True), CODE_ONLY)
        leaked = {**synthetic, "credential": marker}
        result = receipt_status(leaked, SHA, authorized=True)
        self.assertEqual(result, UNKNOWN)
        self.assertNotIn(marker, result)
        self.assertNotIn(marker, repr(receipt_status(None, SHA, authorized=False)))

    def test_estados_unknown_explicitos(self) -> None:
        # D-016/D-026: el workflow configurado y los números históricos de
        # ESPECIFICACIONES.md NO son una medición exact-SHA actual.
        self.assertEqual(receipt_status(None, SHA, authorized=True), UNKNOWN)
        good = {
            "head_sha": SHA, "status": "completed", "conclusion": "success",
            "event": "pull_request", "pending": [],
        }
        wrong = [
            ({**good, "head_sha": "b" * 40}, True),
            ({**good, "status": "in_progress"}, True),
            ({**good, "conclusion": "skipped"}, True),
            ({**good, "event": "push"}, True),
            ({**good, "pending": ["test_e2e"]}, True),
            (good, False),
            (good, True),
        ]
        for data, permission in wrong[:-1]:
            with self.subTest(data=data, permission=permission):
                self.assertEqual(receipt_status(data, SHA, authorized=permission), UNKNOWN)
        self.assertEqual(receipt_status(good, "sha-no-valido", authorized=True), UNKNOWN)
        self.assertEqual(receipt_status(good, SHA, authorized=True), CODE_ONLY)
        # Una evidencia sintética valida exclusivamente el parser, nunca
        # autoriza marcar CUBIERTO, comparar tiempos de CI ni activar live.
        self.assertNotEqual(CODE_ONLY, "CUBIERTO")
        self.assertNotEqual(CODE_ONLY, "VALIDATED_IN_PRODUCTION")


if __name__ == "__main__":
    unittest.main()
