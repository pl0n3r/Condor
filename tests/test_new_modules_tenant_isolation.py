#!/usr/bin/env python3
"""AC-01 #721: rechazo cross-tenant comprobado ejecutando PHPUnit real.

Dos tenants sintéticos; ninguna BD, migración, red o identidad de producción.
El contrato cubre las fronteras actualmente implementadas en los siete módulos.
Los endpoints futuros de #717/#718/#719 tienen aceptación independiente.
"""

from __future__ import annotations

import re
import shutil
import subprocess
import unittest
from pathlib import Path

ROOT = Path(__file__).resolve().parents[1]
PHP_TEST = ROOT / "tests/php/Security/NewModulesTenantIsolationTest.php"
RUNNER = ROOT / "vendor/bin/simple-phpunit"

# Una comprobación dinámica por cada servicio: compras, empleados, asistencia,
# banco de fotos, identificadores escaneables, alertas y costo de producción.
CASES = (
    "testPurchasingRejectsForeignSupplierAndPurchaseAssociations",
    "testEmployeeReadMutationAndForeignMembershipAreDenied",
    "testAttendanceRejectsForeignEmployeeAndPoisonedStore",
    "testPhotoBankRejectsCrossTenantReadAndWriteBeforePersistence",
    "testScansCannotResolveOrAssociateForeignVariant",
    "testAlertInboxDeniesCrossTenantWithSharedUser",
    "testProductionCostRejectsForeignOrderAndMaterialBeforeRead",
)


class AislamientoModulosNuevosTests(unittest.TestCase):
    def test_acceptance(self) -> None:
        self.assertTrue(PHP_TEST.is_file(), str(PHP_TEST))
        php = shutil.which("php")
        self.assertIsNotNone(php, "PHP real es obligatorio")
        self.assertTrue(RUNNER.is_file(), "PHPUnit real (vendor/bin/simple-phpunit) es obligatorio")

        source = PHP_TEST.read_text(encoding="utf-8")
        self.assertTrue(source.startswith("<?php\n"))
        for case in CASES:
            with self.subTest(case=case):
                self.assertEqual(
                    1, len(re.findall(r"\bfunction\s+" + re.escape(case) + r"\s*\(", source)),
                    "Cada escenario tiene que estar presente exactamente una vez",
                )

        lint = subprocess.run(
            [php, "-l", str(PHP_TEST)],
            cwd=ROOT, text=True, capture_output=True, check=False, timeout=25,
        )
        self.assertEqual(0, lint.returncode, lint.stdout + lint.stderr)

        executed = subprocess.run(
            [str(RUNNER), "--no-progress", str(PHP_TEST)],
            cwd=ROOT, text=True, capture_output=True, check=False, timeout=180,
        )
        output = executed.stdout + executed.stderr
        self.assertEqual(0, executed.returncode, output)
        self.assertRegex(output, r"OK\s*\(\s*7 tests?,\s*\d+ assertions?\s*\)")


if __name__ == "__main__":
    unittest.main()
