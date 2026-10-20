#!/usr/bin/env python3
"""Condor #728: run the real tenant-scoped Symfony order HTTP regression."""

from __future__ import annotations

import shutil
import subprocess
import unittest
from pathlib import Path

ROOT = Path(__file__).resolve().parents[1]
CONTROLLER = ROOT / "src/Http/Controller/OrderController.php"
PHP_TEST = ROOT / "tests/php/Http/OrderIsolationControllerTest.php"


class PedidosAislamientoHttpTests(unittest.TestCase):
    def test_acceptance(self) -> None:
        self.assertTrue(CONTROLLER.is_file(), str(CONTROLLER))
        self.assertTrue(PHP_TEST.is_file(), str(PHP_TEST))
        controller = CONTROLLER.read_text(encoding="utf-8")
        for required in (
            "use BranchApiSupport;",
            "'orders.view'",
            "'orders.create'",
            "'orders.update'",
            "'tenant' => $tenant",
            "'legalEntity' => $legalEntity",
            "'inventorySource' => $sources",
            "'id' => $id",
            "$this->requireCsrf($request)",
        ):
            with self.subTest(required=required):
                self.assertIn(required, controller)

        php = shutil.which("php")
        self.assertIsNotNone(php, "PHP CLI requerido para comprobar el contrato HTTP")
        syntax = subprocess.run(
            [php, "-l", str(PHP_TEST)], capture_output=True, text=True,
            check=False,
        )
        self.assertEqual(0, syntax.returncode, syntax.stdout + syntax.stderr)
        runner = ROOT / "vendor/bin/simple-phpunit"
        self.assertTrue(runner.is_file(), "PHPUnit instalado requerido")
        result = subprocess.run(
            [str(runner), "--filter", "testTenantOrdersAreHiddenAndMutationsDenied", str(PHP_TEST)],
            cwd=ROOT, text=True, capture_output=True, check=False, timeout=180,
        )
        self.assertEqual(0, result.returncode, result.stdout + result.stderr)


if __name__ == "__main__":
    unittest.main()
