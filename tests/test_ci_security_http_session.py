#!/usr/bin/env python3
"""#779: el CI descubre y ejecuta la prueba HTTP real de seguridad."""

from __future__ import annotations

import ast
import unittest
from pathlib import Path

ROOT = Path(__file__).resolve().parents[1]


class SecurityHttpSessionDiscoveryTests(unittest.TestCase):
    def test_session_csrf_acceptance_has_phpunit_backend(self) -> None:
        wrapper = ROOT / "tests/test_security_http_session.py"
        php_test = ROOT / "tests/php/Http/SecurityHttpSessionNegativesTest.php"
        ci = ROOT / ".github/workflows/ci.yml"
        classifier = ROOT / "scripts/ci_change_classifier.py"

        for path in (wrapper, php_test, ci, classifier):
            self.assertTrue(path.is_file(), f"Falta archivo de aceptación: {path}")

        ast_tree = ast.parse(wrapper.read_text(encoding="utf-8"), filename=str(wrapper))
        classes = {
            node.name: node for node in ast_tree.body if isinstance(node, ast.ClassDef)
        }
        self.assertIn("SeguridadSesionHttpTests", classes)
        methods = {
            node.name
            for node in classes["SeguridadSesionHttpTests"].body
            if isinstance(node, ast.FunctionDef)
        }
        self.assertIn("test_acceptance", methods)

        php = php_test.read_text(encoding="utf-8")
        self.assertIn("extends WebTestCase", php)
        self.assertIn("function testExpiredSessionsAndInvalidCsrfOnMutatingRoutes()", php)
        self.assertIn("new Tenant(", php)
        self.assertIn("new Membership(", php)
        self.assertIn("->getSession()->invalidate()", php)
        self.assertIn("assertResponseStatusCodeSame(403)", php)
        self.assertIn("assertResponseRedirects('/admin/login')", php)

        workflow = ci.read_text(encoding="utf-8")
        self.assertIn("name: Backend PHP / MariaDB", workflow)
        self.assertIn("run: vendor/bin/simple-phpunit", workflow)
        self.assertIn("MARIADB_DATABASE: condor_test", workflow)
        self.assertIn(
            'path.startswith("tests/php/")',
            classifier.read_text(encoding="utf-8"),
        )


if __name__ == "__main__":
    unittest.main()
