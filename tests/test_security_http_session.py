#!/usr/bin/env python3
"""#779 AC-01: negativas HTTP de sesiones y CSRF, ejecutadas en Symfony."""

from __future__ import annotations

import shutil
import subprocess
import unittest
from pathlib import Path

ROOT = Path(__file__).resolve().parents[1]
PHP_TEST = ROOT / "tests/php/Http/SecurityHttpSessionNegativesTest.php"
PHPUNIT = ROOT / "vendor/bin/simple-phpunit"


class SeguridadSesionHttpTests(unittest.TestCase):
    def test_acceptance(self) -> None:
        """La aceptación exige peticiones Symfony auténticas, no mocks."""
        self.assertTrue(PHP_TEST.is_file(), f"Falta test HTTP real: {PHP_TEST}")
        self.assertTrue(PHPUNIT.is_file(), f"Falta PHPUnit de Symfony: {PHPUNIT}")
        php = shutil.which("php")
        self.assertIsNotNone(php, "PHP CLI requerido por la aceptación HTTP")

        lint = subprocess.run(
            [php, "-l", str(PHP_TEST)],
            cwd=ROOT,
            capture_output=True,
            text=True,
            check=False,
            timeout=30,
        )
        self.assertEqual(0, lint.returncode, lint.stdout + lint.stderr)

        run = subprocess.run(
            [
                str(PHPUNIT),
                "--filter",
                "^testExpiredSessionsAndInvalidCsrfOnMutatingRoutes$",
                str(PHP_TEST),
            ],
            cwd=ROOT,
            capture_output=True,
            text=True,
            check=False,
            timeout=180,
        )
        output = run.stdout + run.stderr
        self.assertEqual(0, run.returncode, output)
        self.assertRegex(output, r"OK\s*\(\s*1 test,", "No se ejecutó el escenario HTTP")


if __name__ == "__main__":
    unittest.main()
