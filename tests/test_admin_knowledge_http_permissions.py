#!/usr/bin/env python3
"""#778 AC-01: ejecutar las negativas HTTP reales de conocimiento administrativo."""

from __future__ import annotations

import shutil
import subprocess
import unittest
from pathlib import Path

ROOT = Path(__file__).resolve().parents[1]
CONTROLLER = ROOT / "src/Http/Controller/AdminKnowledgeController.php"
PHP_TEST = ROOT / "tests/php/Http/Knowledge/AdminKnowledgeHttpPermissionsTest.php"
PHPUNIT = ROOT / "vendor/bin/simple-phpunit"


class ConocimientoAdminPermisosHttpTests(unittest.TestCase):
    def test_acceptance(self) -> None:
        """Ejecutar el WebTestCase real; no sustituir HTTP con inspección estática."""
        for path in (CONTROLLER, PHP_TEST, PHPUNIT):
            self.assertTrue(path.is_file(), f"Falta componente HTTP requerido: {path}")

        php = shutil.which("php")
        self.assertIsNotNone(php, "Se necesita PHP CLI para la aceptación HTTP")

        lint = subprocess.run(
            [php, "-l", str(PHP_TEST)],
            cwd=ROOT,
            capture_output=True,
            text=True,
            check=False,
            timeout=30,
        )
        self.assertEqual(0, lint.returncode, lint.stdout + lint.stderr)

        result = subprocess.run(
            [
                str(PHPUNIT),
                "--filter",
                "testOnlyOwnerCanReadAndNoTenantCanWriteKnowledge",
                str(PHP_TEST),
            ],
            cwd=ROOT,
            capture_output=True,
            text=True,
            check=False,
            timeout=180,
        )
        log = result.stdout + result.stderr
        self.assertEqual(0, result.returncode, log)
        self.assertRegex(log, r"OK\s*\(\s*1 test,", "No se ejecutó el test HTTP")


if __name__ == "__main__":
    unittest.main()
