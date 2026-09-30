#!/usr/bin/env python3
from __future__ import annotations

import subprocess
import unittest
from pathlib import Path

ROOT = Path(__file__).resolve().parents[1]
RECORD_TEST = ROOT / "tests/php/Domain/Commercial/UsageRecordTest.php"
LEDGER_TEST = ROOT / "tests/php/Domain/Commercial/UsageLedgerTest.php"
VERSION = ROOT / "config/version.php"


class SubscriptionUsageAcceptanceTests(unittest.TestCase):
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

    def test_ac01_record_invariants(self) -> None:
        self.phpunit(RECORD_TEST, "testRecordInvariants")

    def test_ac02_tenant_isolation(self) -> None:
        self.phpunit(LEDGER_TEST, "testTenantIsolation")

    def test_ac03_metric_aggregation_semantics(self) -> None:
        self.phpunit(RECORD_TEST, "testMetricCatalogOwnsAggregationSemantics")
        self.phpunit(LEDGER_TEST, "testMetricAggregationSemantics")

    def test_ac04_deterministic_snapshot_and_fail_closed_queries(self) -> None:
        self.phpunit(LEDGER_TEST, "testEquivalentTimezoneWindowsShareTheSameBucket")
        self.phpunit(LEDGER_TEST, "testDeterministicSnapshotAndFailClosedQueries")

    def test_ac05_usage_stays_separate_and_payload_free(self) -> None:
        self.phpunit(LEDGER_TEST, "testUsageStaysSeparatedAndPayloadFree")

    def test_ac06_release_identity(self) -> None:
        contents = VERSION.read_text(encoding="utf-8")
        self.assertIn("'version' => '0.1.90'", contents)


if __name__ == "__main__":
    unittest.main()
