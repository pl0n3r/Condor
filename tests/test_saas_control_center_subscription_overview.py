#!/usr/bin/env python3
from __future__ import annotations

import subprocess
import unittest
from pathlib import Path

ROOT = Path(__file__).resolve().parents[1]
PHP_TEST = ROOT / "tests/php/Http/PlatformOwnerCommercialSubscriptionTest.php"
FRONTEND = ROOT / "frontend/admin/PlatformOwnerApp.tsx"
VERSION = ROOT / "config/version.php"


class SaasControlCenterSubscriptionOverviewAcceptanceTests(unittest.TestCase):
    def phpunit(self, pattern: str) -> None:
        runner = ROOT / "vendor/bin/simple-phpunit"
        if not runner.exists():
            self.fail("vendor/bin/simple-phpunit no está disponible")
        result = subprocess.run(
            [str(runner), "--filter", pattern, str(PHP_TEST)],
            cwd=ROOT,
            text=True,
            capture_output=True,
            check=False,
        )
        self.assertEqual(0, result.returncode, result.stdout + result.stderr)

    def test_ac01_owner_reads_persisted_subscription_summary(self) -> None:
        self.phpunit("testOwnerReadsPersistedSubscriptionSummary")

    def test_ac02_missing_subscription_is_explicit(self) -> None:
        self.phpunit("testMissingSubscriptionIsExplicit")

    def test_ac03_tenant_scope_and_unknown_tenant(self) -> None:
        self.phpunit("testTenantScopeAndUnknownTenant")

    def test_ac04_owner_only_and_read_only(self) -> None:
        self.phpunit("testOwnerOnlyAndReadOnly")

    def test_ac05_existing_owner_shell_renders_commercial_subscription(self) -> None:
        source = FRONTEND.read_text(encoding="utf-8")
        self.assertIn("commercial_subscription: CommercialSubscriptionOverview", source)
        self.assertIn("Suscripción comercial", source)
        self.assertIn("Sin suscripción configurada", source)
        self.assertIn("<AdminShell", source)
        self.assertNotIn("Cambiar plan", source)
        self.assertNotIn("Guardar suscripción", source)

    def test_ac06_payload_boundary(self) -> None:
        self.phpunit("testPayloadBoundaryOmitsCommercialInternals")

    def test_release_identity_is_v0_1_94(self) -> None:
        self.assertIn(
            "'version' => '0.1.94'",
            VERSION.read_text(encoding="utf-8"),
        )


if __name__ == "__main__":
    unittest.main()
