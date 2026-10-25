#!/usr/bin/env python3
"""AC-01 #902: contrato HTTP respaldado por pruebas funcionales Symfony reales."""
from __future__ import annotations

import shutil
import subprocess
import unittest
from pathlib import Path

ROOT = Path(__file__).resolve().parents[1]
CONTROLLER = ROOT / "src/Http/Controller/CustomerSearchController.php"
HTTP_TEST = ROOT / "tests/php/Http/Controller/CustomerSearchControllerTest.php"
SERVICE = ROOT / "src/Application/Commerce/CustomerSearch.php"


class CustomerSearchHttpTests(unittest.TestCase):
    def test_acceptance(self) -> None:
        for path in (CONTROLLER, HTTP_TEST, SERVICE):
            self.assertTrue(path.is_file(), f"Falta {path}")
        controller = CONTROLLER.read_text(encoding="utf-8")
        php_test = HTTP_TEST.read_text(encoding="utf-8")
        for expected in (
            "'/api/v1/branches/{branchId}/customers/search'",
            "methods: ['GET']", "authorizedBranchScope($branchId, 'customers.view')",
            "CustomerSearch $customerSearch", "->search($tenant, $term, $segment)",
            "!is_string($value)", "mb_check_encoding", "mb_strlen($term, 'UTF-8') > 120",
            "CustomerSearch::SEGMENT_TOP", "BadRequestHttpException",
            "'commercial_category_id'", "'Cache-Control' => 'private, no-store'",
        ):
            self.assertIn(expected, controller)
        for expected in (
            "extends WebTestCase", "static::createClient()", "testAcceptance()",
            "assertResponseStatusCodeSame(200)", "assertResponseStatusCodeSame(400)",
            "assertResponseStatusCodeSame(403)", "assertResponseStatusCodeSame(404)",
            "assertResponseStatusCodeSame(401)", "'?term[]=x'",
            "'without_purchases_90_days'", "'?segment=top_20_purchases'",
            "'Cache-Control'", "assertStringNotContainsString($foreign->id()",
        ):
            self.assertIn(expected, php_test)
        php = shutil.which("php")
        self.assertIsNotNone(php, "PHP real requerido")
        for path in (CONTROLLER, HTTP_TEST):
            result = subprocess.run([php, "-l", str(path)], cwd=ROOT,
                                    capture_output=True, text=True, timeout=30,
                                    check=False)
            self.assertEqual(0, result.returncode, result.stdout + result.stderr)
            self.assertIn("No syntax errors detected", result.stdout)
        # La suite de backend PHP/MariaDB descubre y ejecuta WebTestCase real.
        # Este contrato no sustituye esa ejecución mediante mocks de respuestas.

    def test_ci_security_contract(self) -> None:
        controller = CONTROLLER.read_text(encoding="utf-8")
        php_test = HTTP_TEST.read_text(encoding="utf-8")
        self.assertIn("authorizedBranchScope($branchId, 'customers.view')", controller)
        self.assertIn("!is_string($value)", controller)
        self.assertIn("mb_strlen($term, 'UTF-8') > 120", controller)
        self.assertIn("CustomerSearch::SEGMENT_INACTIVE", controller)
        for code in (200, 400, 401, 403, 404):
            self.assertIn(f"assertResponseStatusCodeSame({code})", php_test)
        self.assertIn("extends WebTestCase", php_test)

    def test_no_sensitive_side_effects(self) -> None:
        controller = CONTROLLER.read_text(encoding="utf-8")
        self.assertIn("'Cache-Control' => 'private, no-store'", controller)
        self.assertIn("'commercial_category_id'", controller)
        self.assertNotIn("documentNumber()", controller)
        for forbidden in ("LoggerInterface", "->persist(", "->flush(", "file_put_contents("):
            self.assertNotIn(forbidden, controller)


if __name__ == "__main__":
    unittest.main()
