#!/usr/bin/env python3
from __future__ import annotations

import subprocess
import unittest
from pathlib import Path

ROOT = Path(__file__).resolve().parents[1]
PHP_TEST = ROOT / "tests/php/Http/PlatformOwnerCommercialSubscriptionStateTest.php"
MANAGER = ROOT / "src/Application/Commercial/PlatformCommercialSubscriptionStateManager.php"
CONTROLLER = ROOT / "src/Http/Controller/PlatformCommercialSubscriptionController.php"


class SaasControlCenterSubscriptionStateAcceptanceTests(unittest.TestCase):
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

    def test_ac01_owner_transitions_persisted_subscription_state(self) -> None:
        self.phpunit("testOwnerTransitionsPersistedSubscriptionState")

    def test_ac02_domain_lifecycle_is_single_transition_authority(self) -> None:
        self.phpunit("testPayloadRejectsCallerTimestamp")
        source = MANAGER.read_text(encoding="utf-8")
        self.assertIn("$lifecycle->transitionTo($target, $changedAt);", source)
        self.assertIn("$subscription->syncFromLifecycle($lifecycle, $changedAt);", source)
        self.assertNotIn("TRANSITIONS", source)
        self.assertNotIn("changed_at", source)

    def test_ac03_unknown_or_unconfigured_tenant_fails_closed(self) -> None:
        self.phpunit("testUnknownOrUnconfiguredTenantFailsClosed")

    def test_ac04_owner_csrf_and_post_only_boundary(self) -> None:
        self.phpunit("testOwnerCsrfAndPostOnlyBoundary")
        source = CONTROLLER.read_text(encoding="utf-8")
        self.assertIn("platform_commercial_subscription_management", source)
        self.assertIn("methods: ['POST']", source)

    def test_ac05_invalid_transition_does_not_mutate_subscription(self) -> None:
        self.phpunit("testInvalidTransitionDoesNotMutateSubscription")

    def test_ac06_tenant_scope_and_optimistic_conflict(self) -> None:
        self.phpunit("testTenantScopePreservesOtherSubscription")
        source = MANAGER.read_text(encoding="utf-8")
        self.assertIn("->flush();", source)
        self.assertNotIn("OptimisticLockException", source)

    def test_ac07_release_identity_is_v0_1_96(self) -> None:
        version = (ROOT / "config/version.php").read_text(encoding="utf-8")
        self.assertIn("'version' => '0.1.96'", version)


if __name__ == "__main__":
    unittest.main()
