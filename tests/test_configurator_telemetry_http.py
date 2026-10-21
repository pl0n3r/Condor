#!/usr/bin/env python3
"""Condor #780: acceptance test HTTP contra Symfony y MariaDB efímera.

El mismo WebTestCase se descubre en Backend PHP del CI; este runner conserva
el target de aceptación Python para ejecuciones selectivas fuera del suite.
No usa datos personales, credenciales, producción ni endpoints externos.
"""

from __future__ import annotations

import shutil
import subprocess
import unittest
from pathlib import Path

ROOT = Path(__file__).resolve().parents[1]
CONTROLLER = ROOT / "src/Http/Controller/PlanConfiguratorTelemetryController.php"
RECORDER = ROOT / "src/Infrastructure/Observability/FunctionalSignalRecorder.php"
PHP_FIXTURE = ROOT / "tests/php/Http/ConfiguratorTelemetryHttpSmokeTest.php"
PHPUNIT = ROOT / "vendor/bin/simple-phpunit"


class TelemetriaConfiguradorHttpTests(unittest.TestCase):
    def test_acceptance(self) -> None:
        """Ejecutar el controlador auténtico por HTTP con PHPUnit/Symfony."""
        for path in (CONTROLLER, RECORDER, PHP_FIXTURE):
            self.assertTrue(path.is_file(), f"Falta fuente HTTP: {path}")

        php = shutil.which("php")
        self.assertIsNotNone(php, "PHP CLI obligatorio")
        self.assertTrue(PHPUNIT.is_file(), "Symfony PHPUnit obligatorio")

        for stage, cmd, timeout in (
            ("lint", [php, "-l", str(PHP_FIXTURE)], 30),
            ("HTTP real", [str(PHPUNIT), str(PHP_FIXTURE)], 180),
        ):
            result = subprocess.run(
                cmd, cwd=ROOT, capture_output=True, text=True,
                check=False, timeout=timeout,
            )
            output = result.stdout + result.stderr
            self.assertEqual(0, result.returncode, f"{stage}: {output}")
            if stage == "HTTP real":
                self.assertRegex(output, r"OK\s*\(\s*1 test,")


if __name__ == "__main__":
    unittest.main()
