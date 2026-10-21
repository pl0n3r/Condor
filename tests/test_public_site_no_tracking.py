#!/usr/bin/env python3
"""AC-01 #787: aceptación HTTP real de cookies y recursos públicos."""

from __future__ import annotations

import shutil
import subprocess
import unittest
from pathlib import Path

ROOT = Path(__file__).resolve().parents[1]
PHP_TEST = ROOT / "tests/php/Http/PublicSiteNoTrackingTest.php"
PHPUNIT = ROOT / "vendor/bin/simple-phpunit"
POLICY = ROOT / "docs/legal/politica-cookies.md"


class SitioPublicoSinRastreoTests(unittest.TestCase):
    def test_acceptance(self) -> None:
        self.assertTrue(POLICY.is_file(), "Falta la política de cookies")
        policy = POLICY.read_text(encoding="utf-8")
        for text in (
            "BORRADOR NO APROBADO JURÍDICAMENTE",
            "Set-Cookie",
            "/producto",
            "/soluciones",
            "/seguridad",
            "ninguna",
            "sesión",
            "POR DEFINIR POR EL DUEÑO",
        ):
            with self.subTest(legal_statement=text):
                self.assertIn(text.lower(), policy.lower())

        self.assertTrue(PHP_TEST.is_file(), f"Falta prueba HTTP: {PHP_TEST}")
        self.assertTrue(PHPUNIT.is_file(), f"Falta PHPUnit: {PHPUNIT}")
        php = shutil.which("php")
        self.assertIsNotNone(php, "PHP CLI requerido para la aceptación HTTP")

        lint = subprocess.run(
            [php, "-l", str(PHP_TEST)],
            cwd=ROOT, capture_output=True, text=True, timeout=30,
            check=False,
        )
        self.assertEqual(0, lint.returncode, lint.stdout + lint.stderr)

        result = subprocess.run(
            [
                str(PHPUNIT),
                "--filter",
                "^testPublicPagesDoNotTrackOrLoadThirdPartyResources$",
                str(PHP_TEST),
            ],
            cwd=ROOT, capture_output=True, text=True, timeout=180,
            check=False,
        )
        output = result.stdout + result.stderr
        self.assertEqual(0, result.returncode, output)
        self.assertRegex(output, r"OK\s*\(\s*1 test,", "No se ejecutó el test HTTP real")


if __name__ == "__main__":
    unittest.main()
