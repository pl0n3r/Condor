#!/usr/bin/env python3
from __future__ import annotations

import subprocess
import unittest
from pathlib import Path

ROOT = Path(__file__).resolve().parents[1]
RESOLVER_TEST = ROOT / "tests/php/Application/Commercial/EntitlementResolverTest.php"
OVERRIDE_TEST = ROOT / "tests/php/Domain/Commercial/EntitlementOverrideTest.php"


class EntitlementsAcceptanceTests(unittest.TestCase):
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

    def test_ac01_resolves_effective_entitlements(self) -> None:
        self.phpunit(RESOLVER_TEST, "testResolvesEffectiveCapabilitiesAddOnsAndLimitsDeterministically")

    def test_ac02_keeps_rbac_separate(self) -> None:
        self.phpunit(RESOLVER_TEST, "testKeepsCommercialEntitlementsSeparateFromRbacAndTenantContext")

    def test_ac03_fails_closed(self) -> None:
        self.phpunit(RESOLVER_TEST, "testFailsClosedForUnknownInactiveOrIncompatibleCommercialState")

    def test_ac04_overrides_are_auditable(self) -> None:
        self.phpunit(OVERRIDE_TEST, "testOverridesAreTenantScopedAuditableAndDoNotMutateCatalog")

    def test_ac05_base_controls_are_not_commercial(self) -> None:
        self.phpunit(RESOLVER_TEST, "testBaseSecurityPrivacyBackupRecoveryAndIntegrityAreNotEntitlements")

    def test_ac06_isolates_tenants_and_reuses_catalog(self) -> None:
        self.phpunit(RESOLVER_TEST, "testIsolatesTenantsAndReusesCanonicalCommercialCatalog")


if __name__ == "__main__":
    unittest.main()
