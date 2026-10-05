#!/usr/bin/env python3
from __future__ import annotations

import subprocess
import unittest
from pathlib import Path

ROOT = Path(__file__).resolve().parents[1]
PHP_TEST = (
    ROOT
    / "tests/php/Application/Commercial/SubscriptionQuoteConversionTest.php"
)


class SubscriptionQuoteConversionTests(unittest.TestCase):
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

    def test_trial_approval_uses_exact_quote_plan_vertical_quantities_and_addons(self) -> None:
        self.phpunit(
            "testTrialApprovalUsesExactQuotePlanVerticalQuantitiesAndAddOns"
        )

    def test_missing_expired_non_business_or_converted_quote_fails_closed_without_partial_subscription(self) -> None:
        self.phpunit(
            "testMissingExpiredNonBusinessOrConvertedQuoteFailsClosedWithoutPartialSubscription"
        )

    def test_cross_tenant_review_remains_denied_and_quote_is_not_converted(self) -> None:
        self.phpunit(
            "testCrossTenantReviewRemainsDeniedAndQuoteIsNotConverted"
        )


if __name__ == "__main__":
    unittest.main()
