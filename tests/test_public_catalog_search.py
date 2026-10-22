#!/usr/bin/env python3
"""AC-01/02: search contract and real synthetic Symfony HTTP integration."""

from __future__ import annotations

import shutil
import subprocess
import unittest
from pathlib import Path

ROOT = Path(__file__).resolve().parents[1]


class CatalogoPublicoBusquedaTests(unittest.TestCase):
    def test_acceptance(self) -> None:
        criteria = (ROOT / "src/Application/Storefront/PublicCatalogSearch.php").read_text(encoding="utf-8")
        presentation = (ROOT / "src/Application/Storefront/PublicCatalogPresentation.php").read_text(encoding="utf-8")
        controller = (ROOT / "src/Http/Controller/TenantPublicController.php").read_text(encoding="utf-8")
        template = (ROOT / "templates/tenant/index.html.twig").read_text(encoding="utf-8")
        self.assertIn("MAX_QUERY_LENGTH = 80", criteria)
        self.assertIn("MAX_PAGE = 1000", criteria)
        self.assertIn("pageHref", criteria)
        self.assertIn("LOCATE(LOWER(:search_term), LOWER(product.name))", presentation)
        self.assertIn("product.tenant = :tenant", presentation)
        self.assertIn("product.active = true", presentation)
        self.assertIn("publishablePrice->getDQL()", presentation)
        self.assertIn("product.category = :selected_category", presentation)
        self.assertIn("PublicCatalogSearch::fromQuery", controller)
        self.assertIn('name="q"', template)
        self.assertIn('name="categoria"', template)
        self.assertIn("pagination.previous_href", template)
        self.assertIn("pagination.next_href", template)

    def test_http_contract(self) -> None:
        self.assertIsNotNone(shutil.which("php"), "PHP CLI is required")
        runner = ROOT / "vendor/bin/simple-phpunit"
        self.assertTrue(runner.is_file(), "Symfony/PHPUnit dependencies required")
        result = subprocess.run(
            [str(runner), "tests/php/Http/TenantPublicCatalogSearchTest.php"],
            cwd=ROOT,
            capture_output=True,
            text=True,
            check=False,
            timeout=180,
        )
        self.assertEqual(result.returncode, 0, result.stdout + result.stderr)


if __name__ == "__main__":
    unittest.main()
