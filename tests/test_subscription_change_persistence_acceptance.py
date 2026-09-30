#!/usr/bin/env python3
from __future__ import annotations

import subprocess
import unittest
from pathlib import Path

ROOT = Path(__file__).resolve().parents[1]
PERSISTENCE_TEST = ROOT / "tests/php/Infrastructure/Persistence/SubscriptionChangePersistenceTest.php"
VERSION = ROOT / "config/version.php"

class SubscriptionChangePersistenceAcceptanceTests(unittest.TestCase):
    def phpunit(self, pattern: str) -> None:
        runner = ROOT / "vendor/bin/simple-phpunit"
        if not runner.exists():
            self.fail("vendor/bin/simple-phpunit no está disponible")
        result = subprocess.run(
            [str(runner), "--filter", pattern, str(PERSISTENCE_TEST)],
            cwd=ROOT,
            text=True,
            capture_output=True,
            check=False,
        )
        self.assertEqual(0, result.returncode, result.stdout + result.stderr)

    def test_upgrade_round_trip_preserves_canonical_change(self) -> None:
        self.phpunit("testUpgradeRoundTripPreservesCanonicalChange")

    def test_downgrade_round_trip_preserves_schedule_and_blockers(self) -> None:
        self.phpunit("testDowngradeRoundTripPreservesScheduleAndBlockers")

    def test_corrupt_or_cross_tenant_payload_fails_closed(self) -> None:
        self.phpunit("testCorruptOrCrossTenantPayloadFailsClosed")

    def test_doctrine_persists_auditable_changes_without_mutating_subscription(self) -> None:
        self.phpunit("testDoctrinePersistsAuditableChangesWithoutMutatingSubscription")

    def test_migration_is_expand_compatible_and_guarded(self) -> None:
        self.phpunit("testMigrationIsExpandCompatibleAndGuarded")
        self.phpunit("testSchemaMatchesDoctrineAndRelationsAreCanonical")

    def test_slice_does_not_add_operational_change_surface(self) -> None:
        production_slice = [
            ROOT / "src/Domain/Commercial/Entity/SubscriptionChangeRecord.php",
            ROOT / "migrations/Version20260930204000.php",
        ]
        contents = "\n".join(path.read_text(encoding="utf-8") for path in production_slice)
        for forbidden in (
            "Controller",
            "Route(",
            "Scheduler",
            "MessageHandler",
            "Invoice",
            "Payment",
            "applyChange",
        ):
            self.assertNotIn(forbidden, contents)

    def test_release_identity_is_v0199(self) -> None:
        self.assertIn("'version' => '0.1.99'", VERSION.read_text(encoding="utf-8"))

if __name__ == "__main__":
    unittest.main()
