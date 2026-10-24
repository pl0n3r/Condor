#!/usr/bin/env python3
"""Acceptance #851: actual PHP tenant-bound order filtering and pagination."""
from __future__ import annotations

import shutil
import subprocess
import unittest
from pathlib import Path

ROOT = Path(__file__).resolve().parents[1]
SERVICE = ROOT / "src/Application/Orders/OrderListQuery.php"
CONTROLLER = ROOT / "src/Http/Controller/OrderController.php"
PHP_TEST = ROOT / "tests/php/Application/Orders/OrderListQueryTest.php"


class PedidosFiltrosTests(unittest.TestCase):
    def test_acceptance(self) -> None:
        php = shutil.which("php")
        self.assertIsNotNone(php, "PHP CLI es obligatorio")
        for filename in (SERVICE, CONTROLLER, PHP_TEST):
            self.assertTrue(filename.is_file(), str(filename))
            lint = subprocess.run([php, "-l", str(filename)], cwd=ROOT,
                                  text=True, capture_output=True, check=False)
            self.assertEqual(0, lint.returncode, lint.stdout + lint.stderr)

        controller = CONTROLLER.read_text(encoding="utf-8")
        for required in (
            "OrderListQuery $query", "Request $request", "'orders.view'",
            "authorizedBranchScope(", "accessibleSources(", "$query->fetch(",
            "'pagination' => $listed['pagination']",
        ):
            with self.subTest(required=required):
                self.assertIn(required, controller)

        service = SERVICE.read_text(encoding="utf-8")
        for required in (
            "'o.tenant = :tenant'", "'o.legalEntity = :legalEntity'",
            "'o.inventorySource IN (:sources)'", "COUNT(o.id)",
            "addOrderBy('o.id', \\SortDirection::Descending)", "setMaxResults($perPage)",
        ):
            with self.subTest(required=required):
                self.assertIn(required, service)
        runner = ROOT / "vendor/bin/simple-phpunit"
        self.assertTrue(runner.is_file(), "PHPUnit debe estar instalado")
        result = subprocess.run(
            [str(runner), "--filter", "testTenantFiltersAndStablePagination", str(PHP_TEST)],
            cwd=ROOT, capture_output=True, text=True, check=False, timeout=180,
        )
        self.assertEqual(0, result.returncode, result.stdout + result.stderr)


if __name__ == "__main__":
    unittest.main()
