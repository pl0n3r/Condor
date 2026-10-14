"""Executable AC bridge for Condor #673.

Calls real Symfony WebTestCase fixtures and the actual TypeScript compiler.
No simulated authorization, synthetic green checks or HTTP calls to production.
"""
from __future__ import annotations

from pathlib import Path
import re
import subprocess
import unittest

ROOT = Path(__file__).resolve().parents[1]
PHPUNIT = ROOT / "vendor" / "bin" / "simple-phpunit"
HTTP_TEST = "tests/php/Http/SaasControlCenterReadControllerTest.php"
EXISTING_TEST = "tests/php/Http/PlatformOwnerSaasControlCenterTest.php"


class SaasControlCenterUiIntegrationTests(unittest.TestCase):
    def run_command(self, argv: list[str], *, timeout: int = 240) -> None:
        result = subprocess.run(
            argv, cwd=ROOT, capture_output=True, text=True, timeout=timeout,
            check=False,
        )
        self.assertEqual(
            result.returncode, 0,
            "Executable integration gate failed: "
            + " ".join(argv)
            + "\nstdout:\n" + result.stdout[-4000:]
            + "\nstderr:\n" + result.stderr[-4000:],
        )

    def php_test(self, pattern: str, suite: str = HTTP_TEST) -> None:
        self.assertTrue(PHPUNIT.exists(), "Symfony PHPUnit bridge is required")
        self.run_command([str(PHPUNIT), "--filter", pattern, suite])

    def test_owner_get_uses_canonical_tenant_read_model(self) -> None:
        self.php_test(
            "testOwnerGetUsesSelectedAddOnsEffectiveLimitsAndCurrentWindow"
        )

    def test_auth_and_cross_tenant_fail_closed(self) -> None:
        self.php_test("testNonOwnerAndUnknownTenantFailClosed")
        self.php_test("testUnresolvedCommercialConfigurationIsNotReportedAsAllowed")

    def test_ui_shows_safe_states_and_canonical_values(self) -> None:
        panel = (ROOT / "frontend/admin/SaasControlCenterReadPanel.tsx").read_text()
        app = (ROOT / "frontend/admin/PlatformOwnerApp.tsx").read_text()
        for state in (
            "loading", "ready", "empty", "permission-denied", "error",
            "UNKNOWN",
        ):
            self.assertIn(state, panel)
        self.assertIn("credentials: 'same-origin'", panel)
        self.assertNotRegex(panel, r"method:\s*['\"](?:POST|PUT|PATCH|DELETE)")
        self.assertIn("SaasControlCenterReadPanel", app)
        self.assertRegex(
            app, r"<SaasControlCenterReadPanel\s+key=\{selected\.id\}\s+tenantId=\{selected\.id\}"
        )
        # These commands run the actual TS/React compilation, not a fake parser.
        self.run_command(["npm", "run", "typecheck"])
        self.run_command(["npm", "run", "build:admin"])

    def test_existing_owner_portfolio_and_navigation_remain_intact(self) -> None:
        self.php_test("testOwnerReadsCanonicalTenantPlanSubscriptionAndUsage", EXISTING_TEST)
        app = (ROOT / "frontend/admin/PlatformOwnerApp.tsx").read_text()
        self.assertIn("commercial_portfolio", app)
        for route in ("/adminpl0n3r", "/adminpl0n3r/empresas", "/adminpl0n3r/staff"):
            self.assertIn(route, app)
        self.assertIn("SubscriptionStateTransitionControl", app)


if __name__ == "__main__":
    unittest.main()
