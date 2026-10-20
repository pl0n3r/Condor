"""Executable AC bridge for Condor #673.

Executes Symfony WebTestCase and TypeScript where dependencies are provisioned.
In the lightweight acceptance runner, checks real PHP syntax and verifies that
separate mandatory Backend/Frontend CI jobs execute the suites. A bridge success
is NOT a substitute for these jobs' exact-HEAD terminal success.
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
        source = ROOT / suite
        self.assertTrue(source.exists(), "WebTestCase fixture required: " + suite)
        self.assertIn(pattern, source.read_text())
        if PHPUNIT.exists():
            # Backend job: Composer/Doctrine are installed; execute real HTTP.
            self.run_command([str(PHPUNIT), "--filter", pattern, suite])
            return
        # Acceptance runner intentionally has PHP but not Composer/MariaDB.
        # Check real PHP syntax and prove the separate mandatory Backend
        # job executes ALL PHP WebTestCases (never count this as HTTP PASS).
        self.run_command(["php", "-l", str(source)])
        workflow = (ROOT / ".github/workflows/ci.yml").read_text()
        self.assertIn("vendor/bin/simple-phpunit", workflow)
        self.assertIn("Aplicar migraciones en MariaDB descartable", workflow)

    def test_owner_get_uses_canonical_tenant_read_model(self) -> None:
        self.php_test(
            "testOwnerGetUsesSelectedAddOnsEffectiveLimitsAndCurrentWindow"
        )

    def test_quote_required_catalog_keeps_unknown_prices(self) -> None:
        # Real Doctrine/catalog adapter regression. Full HTTP 200 requires
        # the disjoint #705 reader correction to be integrated first.
        self.php_test("testQuoteRequiredCatalogKeepsExplicitUnknownPrices")

    def test_auth_and_cross_tenant_fail_closed(self) -> None:
        # Actual Symfony routing: the owner cannot turn a read-only GET into a write.
        self.php_test("testCommercialReadRouteRejectsWriteMethods")
        self.php_test("testNonOwnerAndUnknownTenantFailClosed")
        self.php_test("testUnresolvedCommercialConfigurationIsNotReportedAsAllowed")
        self.php_test("testUnexpectedCommercialAdapterFailureIsSanitized")
        self.php_test("testTenantWithoutSubscriptionRemainsUnknownNotKnownZero")

    def test_ui_shows_safe_states_and_canonical_values(self) -> None:
        panel = (ROOT / "frontend/admin/SaasControlCenterReadPanel.tsx").read_text()
        app = (ROOT / "frontend/admin/PlatformOwnerApp.tsx").read_text()
        for state in (
            "loading", "ready", "empty", "permission-denied", "not-found", "error",
            "UNKNOWN",
        ):
            self.assertIn(state, panel)
        self.assertIn("credentials: 'same-origin'", panel)
        # Native output exposes an accessible status without redundant role overrides.
        self.assertIn('<output>Cargando información comercial…</output>', panel)
        self.assertNotIn('role="status"', panel)
        # A stale 200 must not update global page, data, or revision state.
        self.assertRegex(
            app, r"if \(requestId !== requestSequence\.current\) \{\s*return;\s*\}"
        )
        self.assertIn("response.status === 404", panel)
        self.assertIn("setState({ status: 'not-found' })", panel)

        # A quote-required price may be explicitly unknown; never conflate
        # null with a known-zero price or erase known usage/entitlements.
        self.assertIn("price_minor: number | null", panel)
        self.assertIn("isNullableCount(plan.price_minor)", panel)
        # Null must be explicit. Undefined or malformed prices remain errors.
        self.assertIn("value === null || isCount(value)", panel)
        self.assertNotIn("price_minor ?? 0", panel)
        self.assertIn("isNullableCount(row.price_minor)", panel)
        self.assertIn("minor === null", panel)
        self.assertIn("return 'UNKNOWN';", panel)
        controller = (
            ROOT / "src/Http/Controller/SaasControlCenterReadController.php"
        ).read_text()
        self.assertIn("$price === null ? null : self::priceMinor($price)", controller)
        self.assertIn("$monthly === null ? null : self::priceMinor($monthly)", controller)

        # 404 must terminate before the generic error branch; never invite a blind retry.
        self.assertRegex(
            panel,
            r"if \(response\.status === 404\) \{\s*"
            r"setState\(\{ status: 'not-found' \}\);\s*return;\s*\}"
            r"\s*if \(!response\.ok\)",
        )
        self.assertIn(
            '<p role="alert">Esta empresa no se encontró. Información comercial: UNKNOWN.</p>',
            panel,
        )
        self.assertNotRegex(panel, r"method:\s*['\"](?:POST|PUT|PATCH|DELETE)")
        self.assertIn("SaasControlCenterReadPanel", app)
        self.assertRegex(
            app, r"<SaasControlCenterReadPanel\s+key=\{`\$\{selected\.id\}:\$\{saasPanelRevision\}`\}\s+tenantId=\{selected\.id\}"
        )
        self.assertIn("setSaasPanelRevision((current) => current + 1)", app)
        self.assertIn("if (tenantId !== undefined)", app)

        # El bridge no certifica comportamiento DOM por inspección de TSX.
        # La suite Playwright ejerce realmente el bundle en Chromium.
        browser_spec = ROOT / "tests/e2e/saas-control-center.spec.mjs"
        self.assertTrue(browser_spec.is_file(), "AC-03 exige navegador real")
        browser_cases = browser_spec.read_text()
        for case in ("HTTP 403", "HTTP 404", "HTTP 5xx", "empty declara UNKNOWN",
                     "ready representa plan", "POST comercial exitoso"):
            self.assertIn(case, browser_cases)
        ci_workflow = (ROOT / ".github/workflows/ci.yml").read_text()
        self.assertIn("npm run test:e2e", ci_workflow)
        if (ROOT / "node_modules/.bin/tsc").exists():
            self.run_command(["npm", "run", "typecheck"])
            self.run_command(["npm", "run", "build:admin"])
        else:
            # Acceptance runner has no node_modules. The mandatory Frontend
            # job installs dependencies and executes these commands for real.
            workflow = (ROOT / ".github/workflows/ci.yml").read_text()
            self.assertIn("npm ci --ignore-scripts", workflow)
            self.assertIn("npm run typecheck", workflow)
            self.assertIn("npm run build:admin", workflow)

    def test_existing_owner_portfolio_and_navigation_remain_intact(self) -> None:
        self.php_test("testOwnerReadsCanonicalTenantPlanSubscriptionAndUsage", EXISTING_TEST)
        app = (ROOT / "frontend/admin/PlatformOwnerApp.tsx").read_text()
        self.assertIn("commercial_portfolio", app)
        for route in ("/adminpl0n3r", "/adminpl0n3r/empresas", "/adminpl0n3r/staff"):
            self.assertIn(route, app)
        self.assertIn("SubscriptionStateTransitionControl", app)


if __name__ == "__main__":
    unittest.main()
