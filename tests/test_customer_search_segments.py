#!/usr/bin/env python3
"""Condor #852: contrato reproducible de búsqueda y segmentos por tenant.

No consulta servicios remotos ni usa datos reales. La ejecución PHPUnit
se acredita en el job PHP; si el runner está presente, también se ejecuta aquí.
"""
from __future__ import annotations

import re
import shutil
import subprocess
import unittest
from pathlib import Path

ROOT = Path(__file__).resolve().parents[1]
SERVICE = ROOT / "src/Application/Commerce/CustomerSearch.php"
PHP_TEST = ROOT / "tests/php/Application/Commerce/CustomerSearchTest.php"


class ClientesBusquedaSegmentosTests(unittest.TestCase):
    def test_acceptance(self) -> None:
        self.assertTrue(SERVICE.is_file(), "Falta la consulta tenant-scoped")
        self.assertTrue(PHP_TEST.is_file(), "Faltan pruebas PHPUnit efectivas")
        source = SERVICE.read_text(encoding="utf-8")
        cases = PHP_TEST.read_text(encoding="utf-8")

        for required in (
            "final readonly class CustomerSearch",
            "'tenant' => $tenant",
            "'active' => true",
            "->tenant()->id() === $tenant->id()",
            "SEGMENT_NEW",
            "SEGMENT_INACTIVE",
            "SEGMENT_TOP",
            "STATUS_CANCELLED",
            "createdAt()",
            "mb_strtolower",
            "str_contains",
        ):
            self.assertIn(required, source, f"Falta control: {required}")

        for required in (
            "testSearchIgnoresAccentsAndNeverReturnsAnotherTenantsCustomer",
            "testNewAndWithoutRecentPurchasesAreDeterministicAndScoped",
            "testTopPurchasersAreCountedWithoutCancelledOrForeignOrders",
            "testInvalidFiltersFailClosedWithoutReadingRepositories",
        ):
            self.assertIn(f"function {required}(", cases)

        php = shutil.which("php")
        self.assertIsNotNone(php, "Se requiere PHP real para validar sintaxis")
        for path in (SERVICE, PHP_TEST):
            lint = subprocess.run(
                [php, "-l", str(path)],
                cwd=ROOT,
                capture_output=True,
                text=True,
                check=False,
                timeout=20,
            )
            self.assertEqual(0, lint.returncode, (lint.stdout + lint.stderr)[-1000:])
            self.assertIn("No syntax errors detected", lint.stdout)

        # El CI backend-php ejecuta toda la suite tests/php sobre DB descartable.
        # Si esta superficie cuenta además con Composer, verificar aquí el slice.
        runner = ROOT / "vendor/bin/simple-phpunit"
        if not runner.is_file():
            runner = ROOT / "vendor/bin/phpunit"
        if runner.is_file():
            run = subprocess.run(
                [php, str(runner), "-c", "phpunit.xml.dist",
                 "tests/php/Application/Commerce/CustomerSearchTest.php",
                 "--no-coverage"],
                cwd=ROOT,
                capture_output=True,
                text=True,
                check=False,
                timeout=180,
            )
            self.assertEqual(0, run.returncode, (run.stdout + run.stderr)[-3600:])


if __name__ == "__main__":
    unittest.main()
