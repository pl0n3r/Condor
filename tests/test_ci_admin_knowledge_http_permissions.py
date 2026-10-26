#!/usr/bin/env python3
"""#778: guard de descubrimiento de la aceptación HTTP real en CI Condor."""

from __future__ import annotations

import ast
import unittest
from pathlib import Path

ROOT = Path(__file__).resolve().parents[1]


class AdminKnowledgeHttpCiDiscoveryTests(unittest.TestCase):
    def test_backend_discovers_http_negative_contract(self) -> None:
        wrapper = ROOT / "tests/test_admin_knowledge_http_permissions.py"
        php_test = ROOT / "tests/php/Http/Knowledge/AdminKnowledgeHttpPermissionsTest.php"
        controller = ROOT / "src/Http/Controller/AdminKnowledgeController.php"
        workflow = ROOT / ".github/workflows/ci.yml"
        classifier = ROOT / "scripts/ci_change_classifier.py"

        for path in (wrapper, php_test, controller, workflow, classifier):
            self.assertTrue(path.is_file(), f"Falta el componente requerido: {path}")

        tree = ast.parse(wrapper.read_text(encoding="utf-8"), filename=str(wrapper))
        suites = {
            node.name: node for node in tree.body if isinstance(node, ast.ClassDef)
        }
        self.assertIn("ConocimientoAdminPermisosHttpTests", suites)
        self.assertTrue(
            any(
                isinstance(method, ast.FunctionDef) and method.name == "test_acceptance"
                for method in suites["ConocimientoAdminPermisosHttpTests"].body
            ),
            "El target ejecutable AC-01 no existe",
        )

        source = php_test.read_text(encoding="utf-8")
        self.assertIn("extends WebTestCase", source)
        self.assertIn(
            "function testOnlyOwnerCanReadAndNoTenantCanWriteKnowledge()", source
        )
        self.assertIn("new Tenant(", source)
        self.assertIn("new Membership(", source)
        self.assertIn("assertResponseStatusCodeSame(403)", source)
        self.assertIn("assertResponseStatusCodeSame(405)", source)
        self.assertIn("assertSame($before[", source)

        controller_source = controller.read_text(encoding="utf-8")
        self.assertIn("methods: ['GET']", controller_source)
        self.assertIn("denyAccessUnlessGranted(User::ROLE_PLATFORM_OWNER)", controller_source)

        ci_source = workflow.read_text(encoding="utf-8")
        self.assertIn("name: Backend PHP / MariaDB", ci_source)
        self.assertIn("run: vendor/bin/simple-phpunit", ci_source)
        self.assertIn("MARIADB_DATABASE: condor_test", ci_source)
        self.assertIn(
            'path.startswith("tests/php/")',
            classifier.read_text(encoding="utf-8"),
        )


if __name__ == "__main__":
    unittest.main()
