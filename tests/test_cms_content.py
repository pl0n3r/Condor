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
        migration = (
            ROOT / "migrations/Version20261001171500.php"
        ).read_text(encoding="utf-8")
        for expected in (
            "uniq_cms_theme_tenant_id (tenant_id, id)",
            "uniq_cms_page_tenant_id (tenant_id, id)",
            "FK_CMS_PAGE_THEME_TENANT FOREIGN KEY (tenant_id, theme_id)",
            "REFERENCES condor_cms_theme (tenant_id, id)",
            "FK_CMS_BLOCK_PAGE_TENANT FOREIGN KEY (tenant_id, page_id)",
            "REFERENCES condor_cms_page (tenant_id, id)",
        ):
            with self.subTest(expected=expected):
                self.assertIn(expected, migration)

    def test_unknown_or_executable_block_types_fail_closed(self) -> None:
        self.phpunit("testUnknownOrExecutableBlockTypesFailClosed")


if __name__ == "__main__":
    unittest.main()
