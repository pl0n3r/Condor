"""AC-01 #690: metadatos reales de catálogo, aislamiento y proyección HTTP pública."""
from pathlib import Path
import shutil
import subprocess
import unittest

ROOT = Path(__file__).resolve().parents[1]

class BancoFotosProductoTests(unittest.TestCase):
    def test_acceptance(self):
        php = shutil.which("php")
        self.assertIsNotNone(php, "PHP es obligatorio")
        targets = (
            "src/Domain/Catalog/Entity/ProductMedia.php",
            "src/Application/Catalog/ProductMediaService.php",
            "src/Application/Storefront/PublicCatalogPresentation.php",
            "migrations/Version20261019170000.php",
        )
        for path in targets:
            with self.subTest(path=path):
                result = subprocess.run([php, "-l", str(ROOT / path)],
                    text=True, capture_output=True, check=False)
                self.assertEqual(result.returncode, 0, result.stdout + result.stderr)
        self.assertIn("product.primary_image.url",
            (ROOT / "templates/tenant/index.html.twig").read_text())
        migration = (ROOT / targets[3]).read_text()
        self.assertIn("FOREIGN KEY (tenant_id, product_id)", migration)
        self.assertNotIn("DELETE FROM", migration.split("public function up")[1].split("public function down")[0])
        runner = ROOT / "vendor/bin/simple-phpunit"
        self.assertTrue(runner.exists(), "Se requiere PHPUnit real")
        result = subprocess.run(
            [str(runner), "--filter",
             "testPrimaryPhotoSwitchReflectsPublicCatalogAndRejectsOtherTenant|testCrossTenantPhotoCannotBeRegistered|testMalformedMediaMetadataFailsClosed",
             "tests/php/Application/Storefront/PublicCatalogPresentationTest.php"],
            cwd=ROOT, text=True, capture_output=True, check=False, timeout=120,
        )
        self.assertEqual(result.returncode, 0, result.stdout + result.stderr)

if __name__ == "__main__":
    unittest.main()
