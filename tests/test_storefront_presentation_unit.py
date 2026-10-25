#!/usr/bin/env python3
"""AC-01: real PHPUnit coverage and CI wiring for public StorefrontPresentation."""

from __future__ import annotations

import shutil
import subprocess
import unittest
from pathlib import Path

ROOT = Path(__file__).resolve().parents[1]
SOURCE = ROOT / "src/Application/Storefront/StorefrontPresentation.php"
PHP_SUITE = ROOT / "tests/php/Application/Storefront/StorefrontPresentationTest.php"
TEMPLATE = ROOT / "templates/tenant/index.html.twig"
WORKFLOW = ROOT / ".github/workflows/ci.yml"


class StorefrontPresentationUnitTests(unittest.TestCase):
    def test_acceptance(self) -> None:
        for path in (SOURCE, PHP_SUITE, TEMPLATE, WORKFLOW):
            self.assertTrue(path.is_file(), f"Missing source or executable test: {path}")
        source = SOURCE.read_text(encoding="utf-8")
        suite = PHP_SUITE.read_text(encoding="utf-8")
        template = TEMPLATE.read_text(encoding="utf-8")
        workflow = WORKFLOW.read_text(encoding="utf-8")
        self.assertIn("final readonly class StorefrontPresentation", source)
        self.assertIn("function publicIdentity(", source)
        self.assertIn("function registeredDomains(", source)
        for case in (
            "testMissingProfileOrVerifiedPrimaryFailsClosedToCondor",
            "testVerifiedPrimaryAndPublicProfileAreRenderedWithoutTrustingHtml",
            "testAmbiguousVerifiedPrimariesNeverClaimAExternalCanonical",
            "testQueriesAreScopedByExactTenant",
        ):
            self.assertIn(f"function {case}(", suite)
        # Actual frontend output uses Twig's autoescaping, never |raw on these fields.
        for field in ("storefront.headline", "storefront.description", "storefront.canonical"):
            self.assertIn(field, template)
            self.assertNotIn(field + "|raw", template.replace(" ", ""))
        self.assertIn("name: Backend PHP / MariaDB", workflow)
        self.assertIn("run: vendor/bin/simple-phpunit", workflow)
        php = shutil.which("php")
        self.assertIsNotNone(php, "PHP CLI required for acceptance")
        lint = subprocess.run([php, "-l", str(PHP_SUITE)], cwd=ROOT,
                              capture_output=True, text=True, check=False, timeout=30)
        self.assertEqual(0, lint.returncode, lint.stdout + lint.stderr)


if __name__ == "__main__":
    unittest.main()
