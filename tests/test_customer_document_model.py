#!/usr/bin/env python3
"""Contrato offline para documento opcional de Customer; sin PII ni DB real."""
from __future__ import annotations

import json
import shutil
import subprocess
import unittest
from pathlib import Path

ROOT = Path(__file__).resolve().parents[1]
CUSTOMER = ROOT / "src/Domain/Commerce/Entity/Customer.php"
MIGRATION = ROOT / "migrations/Version20261024234000.php"
PHP_TEST = ROOT / "tests/php/Domain/Commerce/CustomerDocumentTest.php"
REGISTRY = ROOT / "datos.yml"


class CustomerDocumentModelTests(unittest.TestCase):
    def test_acceptance(self) -> None:
        for path in (CUSTOMER, MIGRATION, PHP_TEST, REGISTRY):
            self.assertTrue(path.is_file(), str(path))
        customer = CUSTOMER.read_text(encoding="utf-8")
        migration = MIGRATION.read_text(encoding="utf-8")
        self.assertIn("private ?string $documentNumber;", customer)
        self.assertIn("function documentNumber(): ?string", customer)
        self.assertIn("function changeDocumentNumber(?string $documentNumber)", customer)
        self.assertIn("function normalizeDocumentNumber(?string $documentNumber)", customer)
        self.assertIn("name: 'document_number'", customer)
        self.assertIn("ALTER TABLE condor_customer ADD document_number VARCHAR(40) DEFAULT NULL", migration)
        treatment = self._treatment()
        self.assertIn("document_number", treatment["fields"])

        php = shutil.which("php")
        self.assertIsNotNone(php, "PHP real es requerido para lint")
        for path in (CUSTOMER, MIGRATION, PHP_TEST):
            result = subprocess.run([php, "-l", str(path)], cwd=ROOT,
                                    capture_output=True, text=True, timeout=20)
            self.assertEqual(0, result.returncode, (result.stdout + result.stderr)[-1500:])

        runner = ROOT / "vendor/bin/simple-phpunit"
        if not runner.is_file():
            runner = ROOT / "vendor/bin/phpunit"
        if runner.is_file():
            result = subprocess.run(
                [php, str(runner), "-c", "phpunit.xml.dist",
                 "tests/php/Domain/Commerce/CustomerDocumentTest.php", "--no-coverage"],
                cwd=ROOT, capture_output=True, text=True, timeout=180,
            )
            self.assertEqual(0, result.returncode, (result.stdout + result.stderr)[-2500:])

    def test_privacy_and_scope(self) -> None:
        treatment = self._treatment()
        self.assertEqual("customer_management", treatment["purpose"])
        self.assertEqual("contact", treatment["category"])
        self.assertEqual([], treatment["providers"])
        self.assertEqual("review_required", treatment["basis"])
        self.assertEqual("review_required", treatment["consent"])
        migration = MIGRATION.read_text(encoding="utf-8")
        self.assertIn("throw new IrreversibleMigration", migration)
        self.assertNotIn("UPDATE condor_customer", migration)
        source = CUSTOMER.read_text(encoding="utf-8")
        self.assertNotIn("LoggerInterface", source)
        self.assertNotIn("error_log(", source)

    @staticmethod
    def _treatment() -> dict:
        registry = json.loads(REGISTRY.read_text(encoding="utf-8"))
        return next(t for t in registry["treatments"] if t["id"] == "customer_contact")


if __name__ == "__main__":
    unittest.main()
