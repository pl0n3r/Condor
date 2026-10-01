#!/usr/bin/env python3
"""Aceptación ejecutable del dominio CMS V1 de Condor #409."""

from __future__ import annotations

import subprocess
import unittest
from pathlib import Path

ROOT = Path(__file__).resolve().parents[1]
TEST = ROOT / "tests/php/Domain/Cms/CmsContentTest.php"


class CmsContentTests(unittest.TestCase):
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

    def test_tenant_scoped_pages_themes_and_blocks_are_isolated(self) -> None:
        self.phpunit("testTenantScopedPagesThemesAndBlocksAreIsolated")

    def test_unknown_or_executable_block_types_fail_closed(self) -> None:
        self.phpunit("testUnknownOrExecutableBlockTypesFailClosed")


if __name__ == "__main__":
    unittest.main()
