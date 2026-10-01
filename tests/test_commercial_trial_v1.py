#!/usr/bin/env python3
from __future__ import annotations

import subprocess
import unittest
from pathlib import Path

ROOT = Path(__file__).resolve().parents[1]
PHP_TEST = ROOT / "tests/php/Http/PlatformOwnerCommercialTrialTest.php"
LIFECYCLE = ROOT / "src/Domain/Commercial/SubscriptionLifecycle.php"
CREATOR = ROOT / "src/Application/Commercial/PlatformCommercialTrialCreator.php"
SUMMARY = ROOT / "src/Application/Commercial/PlatformCommercialTenantSummary.php"
CONTROLLER = ROOT / "src/Http/Controller/PlatformCommercialSubscriptionController.php"
VERSION = ROOT / "config/version.php"


class CommercialTrialV1AcceptanceTests(unittest.TestCase):
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

    def test_ac01_trial_window_is_exact_and_not_fabricated(self) -> None:
        self.phpunit("testTrialWindowIsExactAndNotFabricated")
        source = LIFECYCLE.read_text(encoding="utf-8")
        self.assertIn("TRIAL_DURATION_DAYS = 14", source)
        self.assertIn("public function trialStartedAt()", source)
        self.assertIn("public function trialEndsAt()", source)
        self.assertIn("SubscriptionState::Trialing->value", source)

    def test_ac02_creator_is_business_only_and_duplicate_safe(self) -> None:
        self.phpunit("testOwnerStartsBusinessTrialAndDuplicateFailsClosed")
        source = CREATOR.read_text(encoding="utf-8")
        self.assertIn("=== 'business'", source)
        self.assertIn("SubscriptionState::Trialing", source)
        self.assertIn("UniqueConstraintViolationException", source)
        self.assertNotIn("duration_days", source)
        self.assertNotIn("planVersionId,", source)

    def test_ac03_trial_endpoint_is_owner_only_csrf_and_server_authoritative(self) -> None:
        self.phpunit("testTrialEndpointIsOwnerCsrfAndServerAuthoritative")
        source = CONTROLLER.read_text(encoding="utf-8")
        marker = "public function startTrial"
        self.assertIn(marker, source)
        trial_method = source[source.index(marker):source.index("#[Route(", source.index(marker))]
        self.assertIn("$this->platformOwner();", trial_method)
        self.assertIn("platform_commercial_subscription_management", trial_method)
        self.assertIn("$this->tenantContext->tenant($tenantId);", trial_method)
        self.assertIn("$payload !== []", trial_method)
        self.assertIn("$this->trialCreator->create($tenantId)", trial_method)
        for forbidden in ("plan_version_id", "duration_days", "target_state"):
            self.assertNotIn(forbidden, trial_method)

    def test_ac04_summary_exposes_trial_window_only_while_trialing(self) -> None:
        self.phpunit("testSummaryExposesTrialWindowOnlyWhileTrialing")
        source = SUMMARY.read_text(encoding="utf-8")
        self.assertIn("SubscriptionState::Trialing", source)
        self.assertIn("'trial_started_at'", source)
        self.assertIn("'trial_ends_at'", source)
        self.assertIn("trialStartedAt()", source)
        self.assertIn("trialEndsAt()", source)

    def test_ac05_scope_has_no_migration_pii_or_billing(self) -> None:
        sources = "\n".join(
            path.read_text(encoding="utf-8")
            for path in (LIFECYCLE, CREATOR, SUMMARY, CONTROLLER)
        ).lower()
        for forbidden in (
            "#[orm\\column",
            "credit_card",
            "card_number",
            "billing_provider",
            "payment_provider",
            "phone_number",
            "password_hash",
        ):
            self.assertNotIn(forbidden, sources)
        self.assertIn(
            "'version' => '0.1.101'",
            VERSION.read_text(encoding="utf-8"),
        )


if __name__ == "__main__":
    unittest.main()
