#!/usr/bin/env python3
"""Aceptación ejecutable del editor administrativo CMS V1 de Condor #410."""

from __future__ import annotations

import subprocess
import unittest
from pathlib import Path

ROOT = Path(__file__).resolve().parents[1]
TEST = ROOT / "tests/php/Http/CmsAdminControllerTest.php"


class CmsAdminTests(unittest.TestCase):
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

    def test_authorized_staff_can_edit_draft_and_publish_content(self) -> None:
        self.phpunit("testAuthorizedStaffCanEditDraftAndPublishContent")

    def test_permissions_tenant_and_csrf_are_enforced(self) -> None:
        self.phpunit("testPermissionsTenantAndCsrfAreEnforced")


if __name__ == "__main__":
    unittest.main()
