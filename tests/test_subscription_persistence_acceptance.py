#!/usr/bin/env python3
from __future__ import annotations

import subprocess
import unittest
from pathlib import Path

ROOT = Path(__file__).resolve().parents[1]
LIFECYCLE_TEST = ROOT / "tests/php/Domain/Commercial/SubscriptionLifecycleTest.php"
PERSISTENCE_TEST = ROOT / "tests/php/Infrastructure/Persistence/SubscriptionPersistenceTest.php"
VERSION = ROOT / "config/version.php"


class SubscriptionPersistenceAcceptanceTests(unittest.TestCase):
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

    def test_ac01_schema_contract(self) -> None:
        self.phpunit(PERSISTENCE_TEST, "testSchemaContract")

    def test_ac02_round_trip(self) -> None:
        self.phpunit(PERSISTENCE_TEST, "testRoundTrip")

    def test_ac03_restore_fails_closed(self) -> None:
        self.phpunit(LIFECYCLE_TEST, "testRestoreValidatesHistoryFailClosed")

    def test_ac04_identity_isolation(self) -> None:
        self.phpunit(PERSISTENCE_TEST, "testIdentityIsolation")

    def test_ac05_optimistic_locking(self) -> None:
        self.phpunit(PERSISTENCE_TEST, "testOptimisticLocking")

    def test_ac06_payload_and_boundary_contract(self) -> None:
        self.phpunit(PERSISTENCE_TEST, "testPayloadBoundaryContract")

    def test_ac07_release_identity(self) -> None:
        contents = VERSION.read_text(encoding="utf-8")
        self.assertIn("'version' => '0.1.92'", contents)


if __name__ == "__main__":
    unittest.main()
