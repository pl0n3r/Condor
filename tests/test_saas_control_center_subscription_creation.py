#!/usr/bin/env python3
from __future__ import annotations

import subprocess
import unittest
from pathlib import Path

ROOT = Path(__file__).resolve().parents[1]
PHP_TEST = ROOT / "tests/php/Http/PlatformOwnerCommercialSubscriptionCreationTest.php"
CREATOR = ROOT / "src/Application/Commercial/PlatformCommercialSubscriptionCreator.php"
CONTROLLER = ROOT / "src/Http/Controller/PlatformCommercialSubscriptionController.php"
CATALOG = ROOT / "src/Application/Commercial/CommercialCatalogReader.php"
UI = ROOT / "frontend/admin/PlatformOwnerApp.tsx"
MAIN = ROOT / "frontend/admin/main.tsx"
TEMPLATE = ROOT / "templates/platform_owner/index.html.twig"


class SaasControlCenterSubscriptionCreationAcceptanceTests(unittest.TestCase):
    def phpunit(self, pattern: str) -> None:
        runner = ROOT / "vendor/bin/simple-phpunit"
        result = subprocess.run(
            [str(runner), "--filter", pattern, str(PHP_TEST)],
            cwd=ROOT,
            text=True,
            capture_output=True,
            check=False,
        ) if runner.exists() else None
        self.assertIsNotNone(result, "vendor/bin/simple-phpunit no está disponible")
        self.assertEqual(0, result.returncode, result.stdout + result.stderr)

    def test_owner_creates_initial_subscription_and_gets_updated_summary(self) -> None:
        self.phpunit("testOwnerCreatesInitialSubscriptionAndGetsUpdatedSummary")
        self.assertIn("'plan_version_id' => $version->id()", CATALOG.read_text(encoding="utf-8"))

    def test_creation_uses_domain_lifecycle_and_server_owned_identity(self) -> None:
        self.phpunit("testCreationUsesDomainLifecycleAndServerOwnedIdentity")
        source = CREATOR.read_text(encoding="utf-8")
        self.assertIn("new SubscriptionLifecycle(", source)
        self.assertIn("SubscriptionState::Active", source)
        self.assertIn("Subscription::fromLifecycle($lifecycle, $now)", source)
        self.assertNotIn("target_state", source)
        self.assertNotIn("changed_at", source)

    def test_missing_tenant_plan_or_existing_subscription_fail_closed(self) -> None:
        self.phpunit("testMissingTenantPlanOrExistingSubscriptionFailClosed")
        source = CREATOR.read_text(encoding="utf-8")
        self.assertIn("$planVersion->plan()->isActive()", source)
        self.assertIn("$planVersion->isEffectiveAt($now)", source)

    def test_endpoint_is_owner_csrf_post_only_with_closed_payload(self) -> None:
        self.phpunit("testEndpointIsOwnerCsrfPostOnlyWithClosedPayload")
        source = CONTROLLER.read_text(encoding="utf-8")
        self.assertIn("platform_commercial_subscription_management", source)
        self.assertIn("methods: ['POST']", source)
        self.assertIn("$keys !== ['plan_version_id']", source)

    def test_creation_is_tenant_scoped(self) -> None:
        self.phpunit("testCreationIsTenantScoped")
        source = CONTROLLER.read_text(encoding="utf-8")
        self.assertIn("$this->tenantContext->tenant($tenantId);", source)
        self.assertNotIn("tenant_id", source)

    def test_ui_only_offers_creation_for_not_configured_tenant(self) -> None:
        ui = UI.read_text(encoding="utf-8")
        main = MAIN.read_text(encoding="utf-8")
        template = TEMPLATE.read_text(encoding="utf-8")
        self.assertIn("plan_version_id", ui)
        self.assertIn("Crear suscripción", ui)
        self.assertIn("status === 'not_configured'", ui)
        self.assertNotIn("Cambiar plan", ui)
        self.assertIn("subscriptionToken", main)
        self.assertIn("data-subscription-token", template)

    def test_release_identity_is_v0198(self) -> None:
        self.assertIn(
            "'version' => '0.1.98'",
            (ROOT / "config/version.php").read_text(encoding="utf-8"),
        )


if __name__ == "__main__":
    unittest.main()
