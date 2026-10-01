#!/usr/bin/env python3
from __future__ import annotations

import subprocess
import unittest
from pathlib import Path

ROOT = Path(__file__).resolve().parents[1]
PHP_TEST = ROOT / "tests/php/Http/PlatformOwnerSaasControlCenterTest.php"


class SaasControlCenterTests(unittest.TestCase):
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

    def test_owner_reads_canonical_tenant_plan_subscription_and_usage(self) -> None:
        self.phpunit("testOwnerReadsCanonicalTenantPlanSubscriptionAndUsage")

    def test_non_owner_access_fails_closed(self) -> None:
        self.phpunit("testNonOwnerAccessFailsClosed")


if __name__ == "__main__":
    unittest.main()
