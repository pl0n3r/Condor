#!/usr/bin/env python3
from __future__ import annotations

import subprocess
import unittest
from pathlib import Path

ROOT = Path(__file__).resolve().parents[1]
PERSISTENCE_TEST = ROOT / "tests/php/Infrastructure/Persistence/UsagePersistenceTest.php"


class UsagePersistenceAcceptanceTests(unittest.TestCase):
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

    def test_ac01_doctrine_mapping(self) -> None:
        self.phpunit("testSchemaContract")

    def test_ac02_exact_round_trip(self) -> None:
        self.phpunit("testExactRoundTrip")

    def test_ac03_corrupt_persistence_fails_closed(self) -> None:
        self.phpunit("testCorruptPersistenceFailsClosed")

    def test_ac04_ledger_semantics_survive_round_trip(self) -> None:
        self.phpunit("testLedgerSemanticsSurviveRoundTrip")

    def test_ac05_schema_and_d054_contract(self) -> None:
        self.phpunit("testSchemaContract")

    def test_ac06_payload_boundary(self) -> None:
        self.phpunit("testPayloadBoundary")


if __name__ == "__main__":
    unittest.main()
