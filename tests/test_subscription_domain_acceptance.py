#!/usr/bin/env python3
from __future__ import annotations

import subprocess
import unittest
from pathlib import Path

ROOT = Path(__file__).resolve().parents[1]
LIFECYCLE_TEST = ROOT / "tests/php/Domain/Commercial/SubscriptionLifecycleTest.php"
CHANGE_TEST = ROOT / "tests/php/Domain/Commercial/SubscriptionChangeTest.php"


class SubscriptionDomainAcceptanceTests(unittest.TestCase):
    def phpunit(self, test_file: Path, pattern: str) -> None:
        runner = ROOT / "vendor/bin/simple-phpunit"
        if not runner.exists():
            self.fail("vendor/bin/simple-phpunit no está disponible")
        result = subprocess.run(
            [str(runner), "--filter", pattern, str(test_file)],
            cwd=ROOT,
            text=True,
            capture_output=True,
            check=False,
        )
        self.assertEqual(0, result.returncode, result.stdout + result.stderr)

    def test_ac01_lifecycle_transitions(self) -> None:
        self.phpunit(LIFECYCLE_TEST, "testLifecycleTransitionsAreCanonicalAndHistorical")

    def test_ac02_invalid_transitions_fail_closed(self) -> None:
        self.phpunit(LIFECYCLE_TEST, "testInvalidOrRegressiveTransitionsFailClosed")

    def test_ac03_upgrade_and_downgrade_timing(self) -> None:
        self.phpunit(CHANGE_TEST, "testUpgradeIsImmediateAndCompatibleDowngradeIsScheduled")

    def test_ac04_incompatible_downgrade_requires_resolution(self) -> None:
        self.phpunit(CHANGE_TEST, "testIncompatibleDowngradeRequiresResolutionWithoutEffectiveDate")

    def test_ac05_reuses_commercial_contracts(self) -> None:
        self.phpunit(CHANGE_TEST, "testSubscriptionSliceReusesCanonicalContractsAndTenantScope")


if __name__ == "__main__":
    unittest.main()
