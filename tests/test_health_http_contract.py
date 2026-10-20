#!/usr/bin/env python3
"""Condor #732 — contrato HTTP real de /health (AC-01).

El test exige PHP/Symfony/PHPUnit y evidencia ejecutable, no una comparación
de cadenas contra el controlador. Fixtures y SHA son sintéticos, sin red externa
ni sesiones o datos de clientes.
"""

from __future__ import annotations

import re
import shutil
import subprocess
import unittest
from pathlib import Path

ROOT = Path(__file__).resolve().parents[1]
CONTROLLER = ROOT / "src/Http/Controller/HealthController.php"
PHP_TEST = ROOT / "tests/php/Http/HealthContractControllerTest.php"
TEST_METHOD = "testAnonymousHealthContractDoesNotLeakSecretsOrTenantContext"


class SaludContratoHttpTests(unittest.TestCase):
    def test_acceptance(self) -> None:
        self.assertTrue(CONTROLLER.is_file(), str(CONTROLLER))
        self.assertTrue(PHP_TEST.is_file(), "Falta test HTTP nativo con Symfony/PHPUnit")
        php = shutil.which("php")
        self.assertIsNotNone(php, "PHP CLI real es requisito del criterio AC-01")
        runner = ROOT / "vendor/bin/simple-phpunit"
        self.assertTrue(runner.is_file(), "Composer/PHPUnit debe estar instalado")

        fixture = PHP_TEST.read_text(encoding="utf-8")
        self.assertIn("extends WebTestCase", fixture)
        self.assertIn("createClient()", fixture)
        self.assertEqual(
            1,
            len(re.findall(r"\bfunction\s+" + TEST_METHOD + r"\s*\(", fixture)),
            "AC-01 debe ejecutar exactamente un escenario de integración identificable",
        )
        lint = subprocess.run(
            [php, "-l", str(PHP_TEST)],
            cwd=ROOT, text=True, capture_output=True, check=False, timeout=25,
        )
        self.assertEqual(0, lint.returncode, lint.stdout + lint.stderr)
        result = subprocess.run(
            [str(runner), "--filter", TEST_METHOD, str(PHP_TEST)],
            cwd=ROOT, text=True, capture_output=True, check=False, timeout=180,
        )
        output = result.stdout + result.stderr
        self.assertEqual(0, result.returncode, output)
        self.assertRegex(output, r"OK\s*\(\s*1 test,\s*\d+ assertions?\s*\)")


if __name__ == "__main__":
    unittest.main()
