#!/usr/bin/env python3
from __future__ import annotations

import subprocess
import unittest
from pathlib import Path

ROOT = Path(__file__).resolve().parents[1]
PHP_TEST = (
    ROOT
    / "tests/php/Application/Commercial/SubscriptionEntitlementContextFactoryTest.php"
)


class SubscriptionEntitlementContextTests(unittest.TestCase):
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

    def test_persisted_configuration_resolves_vertical_addons_and_configured_limits_deterministically(self) -> None:
        self.phpunit(
            "testPersistedConfigurationResolvesVerticalAddOnsAndConfiguredLimitsDeterministically"
        )

    def test_missing_cross_tenant_stale_or_incompatible_configuration_fails_closed(self) -> None:
        self.phpunit(
            "testMissingCrossTenantStaleOrIncompatibleConfigurationFailsClosed"
        )

    def test_configured_limits_apply_before_explicit_overrides_without_rbac_or_lifecycle_policy(self) -> None:
        self.phpunit(
            "testConfiguredLimitsApplyBeforeExplicitOverridesWithoutRbacOrLifecyclePolicy"
        )


if __name__ == "__main__":
    unittest.main()
