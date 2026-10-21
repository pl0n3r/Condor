#!/usr/bin/env python3
"""#776: guard de descubrimiento del AC HTTP en el CI real de Condor.

Este test ligero no suplanta el test HTTP: la ejecución real la realiza
Backend PHP / MariaDB mediante vendor/bin/simple-phpunit.
"""

from __future__ import annotations

import ast
import unittest
from pathlib import Path

ROOT = Path(__file__).resolve().parents[1]


class PlanConfiguratorHttpCiDiscoveryTests(unittest.TestCase):
    def test_backend_discovers_acceptance_scenario(self) -> None:
        wrapper = ROOT / "tests/test_plan_configurator_http_negatives.py"
        php_test = ROOT / "tests/php/Http/PlanConfiguratorHttpNegativesTest.php"
        workflow = ROOT / ".github/workflows/ci.yml"
        classifier = ROOT / "scripts/ci_change_classifier.py"

        for path in (wrapper, php_test, workflow, classifier):
            self.assertTrue(path.is_file(), f"Ruta requerida inexistente: {path}")

        tree = ast.parse(wrapper.read_text(encoding="utf-8"), filename=str(wrapper))
        suites = {
            node.name: node for node in tree.body if isinstance(node, ast.ClassDef)
        }
        self.assertIn("ConfiguradorPlanNegativasHttpTests", suites)
        acceptance = suites["ConfiguradorPlanNegativasHttpTests"]
        self.assertTrue(
            any(
                isinstance(node, ast.FunctionDef) and node.name == "test_acceptance"
                for node in acceptance.body
            ),
            "El target nominativo AC-01 no existe",
        )

        source = php_test.read_text(encoding="utf-8")
        self.assertIn("extends WebTestCase", source)
        self.assertIn(
            "function testRejectsInvalidCombinationsAndCalculatesTotalOnServer()",
            source,
        )
        self.assertIn("assertResponseStatusCodeSame(422)", source)
        self.assertIn("assertSame(229700,", source)
        self.assertIn("assertSame($before,", source)

        # El job Python ligero no simula una base de datos inexistente.
        ci = workflow.read_text(encoding="utf-8")
        self.assertIn("name: Backend PHP / MariaDB", ci)
        self.assertIn("run: vendor/bin/simple-phpunit", ci)
        self.assertIn("MARIADB_DATABASE: condor_test", ci)
        self.assertIn('path.startswith("tests/php/")', classifier.read_text(encoding="utf-8"))


if __name__ == "__main__":
    unittest.main()
