#!/usr/bin/env python3
from __future__ import annotations

import subprocess
import unittest
from pathlib import Path

ROOT = Path(__file__).resolve().parents[1]
PHP_TEST = ROOT / "tests/php/Http/PlatformOwnerCommercialAdjustmentTest.php"
MANAGER = ROOT / "src/Application/Commercial/PlatformCommercialAdjustmentManager.php"
CONTROLLER = ROOT / "src/Http/Controller/PlatformCommercialSubscriptionController.php"
RECORD = ROOT / "src/Domain/Commercial/Entity/SubscriptionChangeRecord.php"
MIGRATION = ROOT / "migrations/Version20261002142500.php"
VERSION = ROOT / "config/version.php"


class SaasCommercialAdjustmentTests(unittest.TestCase):
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

    def test_owner_applies_plan_addon_price_and_renewal_adjustments_with_audit(self) -> None:
        self.phpunit("testOwnerAppliesPlanAddonPriceAndRenewalAdjustmentsWithAudit")
        manager = MANAGER.read_text(encoding="utf-8")
        controller = CONTROLLER.read_text(encoding="utf-8")
        record = RECORD.read_text(encoding="utf-8")
        migration = MIGRATION.read_text(encoding="utf-8")
        self.assertIn("SubscriptionChangeRecord::fromManualAdjustment", manager)
        self.assertIn("$target->monthlyAmount()", manager)
        self.assertIn("SubscriptionLifecycle::parseHistoricalTime", manager)
        self.assertIn("platform_commercial_subscription_management", controller)
        self.assertIn("requested_by", migration)
        self.assertIn("audit_reason", migration)
        self.assertIn("fromManualAdjustment", record)
        self.assertIn("'version' => '0.1.109'", VERSION.read_text(encoding="utf-8"))

    def test_invalid_or_unauthorized_adjustment_fails_closed(self) -> None:
        self.phpunit("testInvalidOrUnauthorizedAdjustmentFailsClosed")
        controller = CONTROLLER.read_text(encoding="utf-8")
        manager = MANAGER.read_text(encoding="utf-8")
        self.assertIn("$owner = $this->platformOwner();", controller)
        self.assertIn("$this->tenantContext->tenant($tenantId);", controller)
        self.assertIn("Payload de ajuste comercial inválido.", controller)
        self.assertIn("Add-on incompatible o inactivo", manager)
        self.assertNotIn("Payment", manager)
        self.assertNotIn("Billing", manager)


if __name__ == "__main__":
    unittest.main()
