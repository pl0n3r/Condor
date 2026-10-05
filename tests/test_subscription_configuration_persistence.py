#!/usr/bin/env python3
from __future__ import annotations

import subprocess
import unittest
from pathlib import Path

ROOT = Path(__file__).resolve().parents[1]
PHP_TEST = ROOT / "tests/php/Infrastructure/Persistence/SubscriptionConfigurationPersistenceTest.php"


class SubscriptionConfigurationPersistenceTests(unittest.TestCase):
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

    def test_round_trip_preserves_vertical_quantities_and_canonical_addons_once_per_subscription(self) -> None:
        self.phpunit(
            "testRoundTripPreservesVerticalQuantitiesAndCanonicalAddOnsOncePerSubscription"
        )

    def test_rejects_incompatible_inactive_duplicate_addons_or_noncanonical_quantities(self) -> None:
        self.phpunit(
            "testRejectsIncompatibleInactiveDuplicateAddOnsOrNonCanonicalQuantities"
        )

    def test_schema_is_additive_unique_and_legacy_subscription_can_exist_without_configuration(self) -> None:
        self.phpunit(
            "testSchemaIsAdditiveUniqueAndLegacySubscriptionCanExistWithoutConfiguration"
        )


if __name__ == "__main__":
    unittest.main()
