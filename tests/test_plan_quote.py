#!/usr/bin/env python3
from __future__ import annotations

import subprocess
import unittest
from pathlib import Path

ROOT = Path(__file__).resolve().parents[1]
TEST = ROOT / "tests/php/Application/Commercial/PlanQuoteTest.php"


class PlanQuoteTests(unittest.TestCase):
    def run_php(self, name: str) -> None:
        result = subprocess.run(
            [str(ROOT / "vendor/bin/simple-phpunit"), "--filter", name, str(TEST)],
            cwd=ROOT, text=True, capture_output=True, check=False,
        )
        self.assertEqual(0, result.returncode, result.stdout + result.stderr)

    def test_quote_uses_effective_catalog_and_server_side_quantities(self) -> None:
        self.run_php("testQuoteUsesEffectiveCatalogAndServerSideQuantities")

    def test_client_supplied_total_is_ignored(self) -> None:
        self.run_php("testClientSuppliedTotalIsIgnored")

    def test_incompatible_selection_fails_closed(self) -> None:
        self.run_php("testIncompatibleSelectionFailsClosed")

    def test_unpriced_configuration_becomes_proposal(self) -> None:
        self.run_php("testUnpricedConfigurationBecomesProposal")

    def test_quote_preserves_plan_version_and_composition(self) -> None:
        self.run_php("testQuotePreservesPlanVersionAndComposition")

    def test_same_version_and_configuration_is_deterministic(self) -> None:
        self.run_php("testSameVersionAndConfigurationIsDeterministic")


if __name__ == "__main__":
    unittest.main()
