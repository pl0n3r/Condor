#!/usr/bin/env python3
"""#1637: aceptación negativa SQL/HTTP real sobre MariaDB efímera.

El fixture PHPUnit está bajo tests/php/Http para ejecución automática en CI.
Este wrapper es un segundo punto de entrada explícito: nunca simula el resultado.
"""
from __future__ import annotations

from pathlib import Path
import re
import shutil
import subprocess
import unittest

ROOT = Path(__file__).resolve().parents[2]
PHP_TEST = ROOT / "tests/php/Http/GestScopeNegativeSqlTest.php"
REPORT = ROOT / "docs/verificacion/gest-scope-negative-sql.md"
CONFIG = ROOT / "phpunit.xml.dist"
PHPUNIT = ROOT / "vendor/bin/simple-phpunit"
METHOD = "testNegativeTenantLegalEntityBranchRoleAndSqlRollback"


class GestScopeNegativeSqlTests(unittest.TestCase):
    def test_mariadb_scope_and_rollback(self) -> None:
        """AC-01/02: PHPUnit real, sin mock ni skip cuando falta MariaDB."""
        self.assertIsNotNone(shutil.which("php"), "PHP CLI requerido")
        self.assertTrue(PHP_TEST.is_file(), "Falta fixture PHPUnit")
        self.assertTrue(CONFIG.is_file(), "Falta configuración PHPUnit")
        self.assertTrue(PHPUNIT.is_file(), "Falta Composer/PHPUnit; no simular PASS")
        source = PHP_TEST.read_text(encoding="utf-8")
        for required in (
            "WebTestCase", "EntityManagerInterface",
            "beginTransaction", "rollBack", "fetchOne",
            "assertResponseStatusCodeSame", "TenantCsvReportService",
            "testNegativeTenantLegalEntityBranchRoleAndSqlRollback",
        ):
            self.assertIn(required, source, f"Falta evidencia en PHP: {required}")
        result = subprocess.run(
            [
                str(PHPUNIT), "--configuration", str(CONFIG),
                "--filter", METHOD, str(PHP_TEST),
            ],
            cwd=ROOT, capture_output=True, text=True,
            check=False, timeout=240,
        )
        output = result.stdout + result.stderr
        if output:
            print(output, end="")
        self.assertEqual(0, result.returncode, output[-5000:])
        self.assertRegex(output, r"OK\s*\(\s*1 test\s*,", output[-5000:])

    def test_report_explicit_limits(self) -> None:
        """No promocionar GEST integral ni módulos denegados."""
        self.assertTrue(REPORT.is_file())
        body = REPORT.read_text(encoding="utf-8")
        for required in (
            "#1637", "MariaDB 11.4", "NO_VERIFICABLE", "F3/F4",
            "D-059", "D-063", "rollback", "2 tenants",
            "tests/php/Http/GestScopeNegativeSqlTest.php",
            "tests/e2e/test_gest_scope_negative_sql.py",
        ):
            self.assertIn(required, body)
        self.assertNotIn("VALIDADO_EN_PRODUCCION", body)
        self.assertNotIn("GEST_INTEGRAL_CUMPLE", body)
        self.assertLess(len(body), 28000)


if __name__ == "__main__":
    unittest.main()
