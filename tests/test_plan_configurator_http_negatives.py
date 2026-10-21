#!/usr/bin/env python3
"""#776 AC-01: negativa HTTP real ejecutada por Symfony/PHPUnit en MariaDB CI."""

from __future__ import annotations

import shutil
import subprocess
import unittest
from pathlib import Path

ROOT = Path(__file__).resolve().parents[1]
CONTROLLER = ROOT / "src/Http/Controller/PlanConfiguratorController.php"
QUOTE_SERVICE = ROOT / "src/Application/Commercial/PlanQuoteService.php"
PHP_TEST = ROOT / "tests/php/Http/PlanConfiguratorHttpNegativesTest.php"
PHPUNIT = ROOT / "vendor/bin/simple-phpunit"


class ConfiguradorPlanNegativasHttpTests(unittest.TestCase):
    def test_acceptance(self) -> None:
        """Execute the same permanent HTTP test discovered by the backend job."""
        for path in (CONTROLLER, QUOTE_SERVICE, PHP_TEST):
            self.assertTrue(path.is_file(), f"Falta el archivo requerido: {path}")

        php = shutil.which("php")
        self.assertIsNotNone(php, "PHP CLI requerido para HTTP real")
        self.assertTrue(PHPUNIT.is_file(), "PHPUnit de Symfony requerido")

        lint = subprocess.run(
            [php, "-l", str(PHP_TEST)],
            cwd=ROOT, capture_output=True, text=True, check=False, timeout=30,
        )
        self.assertEqual(0, lint.returncode, lint.stdout + lint.stderr)

        result = subprocess.run(
            [
                str(PHPUNIT),
                "--filter",
                "testRejectsInvalidCombinationsAndCalculatesTotalOnServer",
                str(PHP_TEST),
            ],
            cwd=ROOT, capture_output=True, text=True, check=False, timeout=180,
        )
        log = result.stdout + result.stderr
        self.assertEqual(0, result.returncode, log)
        self.assertRegex(log, r"OK\s*\(\s*1 test,", "Ninguna prueba HTTP ejecutada")


if __name__ == "__main__":
    unittest.main()
