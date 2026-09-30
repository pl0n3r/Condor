#!/usr/bin/env python3
from __future__ import annotations

import subprocess
import unittest
from pathlib import Path

ROOT = Path(__file__).resolve().parents[1]
RECORD_TEST = ROOT / "tests/php/Domain/Commercial/UsageRecordTest.php"
LEDGER_TEST = ROOT / "tests/php/Domain/Commercial/UsageLedgerTest.php"


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

    def test_ac01_usage_record_contract(self) -> None:
        self.phpunit(RECORD_TEST, "testUsageRecordContractIsClosedAndCanonical")

    def test_ac02_ledger_aggregation_and_tenant_scope(self) -> None:
        self.phpunit(LEDGER_TEST, "testLedgerAggregationAndTenantScopeAreFailClosed")

    def test_ac03_explicit_windows(self) -> None:
        self.phpunit(LEDGER_TEST, "testExplicitHalfOpenWindowsExcludeMissingOrOutOfRangeData")

    def test_ac04_plan_version_limit_comparison(self) -> None:
        self.phpunit(LEDGER_TEST, "testPlanVersionLimitComparisonUsesCanonicalIntegerLimit")

    def test_ac05_pure_deterministic_contract(self) -> None:
        self.phpunit(LEDGER_TEST, "testPureDeterministicContractHasNoPersistenceOrFreePayload")


if __name__ == "__main__":
    unittest.main()
