"""Executable bridge for the tenant-scoped public product taxonomy (#821)."""
from pathlib import Path
import subprocess
import unittest

ROOT = Path(__file__).resolve().parents[1]
PHPUNIT = ROOT / "vendor" / "bin" / "simple-phpunit"
PHP_TEST = "tests/php/Domain/Catalog/ProductCategoryTest.php"
MIGRATION = ROOT / "migrations" / "Version20261021230000.php"


class ProductCategoryModelTests(unittest.TestCase):
    def run_phpunit(self, filter_name=None):
        self.assertTrue(PHPUNIT.is_file(), "Se necesita vendor/bin/simple-phpunit real")
        command = [str(PHPUNIT), PHP_TEST]
        if filter_name:
            command += ["--filter", filter_name]
        result = subprocess.run(
            command, cwd=ROOT, text=True, capture_output=True, timeout=120, check=False
        )
        self.assertEqual(0, result.returncode, result.stdout + "\n" + result.stderr)

    def test_acceptance(self):
        self.run_phpunit("testAssignmentStaysInsideTenantAndCanBeRemoved")

    def test_php_contract(self):
        self.run_phpunit()

    def test_additive_migration(self):
        text = MIGRATION.read_text(encoding="utf-8")
        self.assertIn("CREATE TABLE condor_product_category", text)
        self.assertIn("UNIQUE INDEX uniq_product_category_tenant_name (tenant_id, name)", text)
        self.assertIn("UNIQUE INDEX uniq_product_category_tenant_slug (tenant_id, slug)", text)
        self.assertIn("CREATE INDEX idx_product_category_id ON condor_product (category_id)", text)
        self.assertIn("FOREIGN KEY (category_id)", text)
        self.assertIn("FOREIGN KEY (tenant_id, category_id)", text)
        self.assertIn("ADD category_id VARCHAR(26) DEFAULT NULL", text)
        self.assertIn("FOREIGN KEY (tenant_id, category_id)", text)
        up_sql = text.split("public function up", 1)[1].split("public function down", 1)[0]
        self.assertNotRegex(up_sql, r"(?i)DROP TABLE|DROP COLUMN|DELETE FROM")

    def test_legacy_null_category(self):
        self.run_phpunit("testLegacyProductCategoryIsNull")


if __name__ == "__main__":
    unittest.main()
