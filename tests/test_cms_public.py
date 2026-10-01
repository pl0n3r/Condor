#!/usr/bin/env python3
"""Aceptación ejecutable del render público CMS V1 de Condor #411."""

from __future__ import annotations

import subprocess
import unittest
from pathlib import Path

ROOT = Path(__file__).resolve().parents[1]
TEST = ROOT / "tests/php/Http/CmsPublicControllerTest.php"


class CmsPublicTests(unittest.TestCase):
    def phpunit(self, pattern: str) -> None:
        runner = ROOT / "vendor/bin/simple-phpunit"
        if not runner.exists():
            self.fail("vendor/bin/simple-phpunit no está disponible")
        result = subprocess.run(
            [str(runner), "--filter", pattern, str(TEST)],
            cwd=ROOT,
            text=True,
            capture_output=True,
            check=False,
        )
        self.assertEqual(0, result.returncode, result.stdout + result.stderr)

    def test_published_content_renders_for_the_resolved_tenant(self) -> None:
        self.phpunit("testPublishedContentRendersForTheResolvedTenant")

    def test_draft_cross_tenant_and_unsafe_content_never_render(self) -> None:
        self.phpunit("testDraftCrossTenantAndUnsafeContentNeverRender")


if __name__ == "__main__":
    unittest.main()
