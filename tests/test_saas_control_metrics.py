#!/usr/bin/env python3
from __future__ import annotations

import subprocess
import unittest
from pathlib import Path

ROOT = Path(__file__).resolve().parents[1]
PHP_TEST = ROOT / "tests/php/Application/Commercial/PlatformCommercialMetricsTest.php"
CONTEXT = ROOT / "src/Http/Controller/PlatformOwnerContextController.php"


class SaasControlMetricsTests(unittest.TestCase):
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

    def test_mrr_arr_active_customers_and_trials_are_derived_from_canonical_commercial_data(self) -> None:
        self.phpunit(
            "testMrrArrActiveCustomersAndTrialsAreDerivedFromCanonicalCommercialData"
        )
        source = CONTEXT.read_text(encoding="utf-8")
        self.assertIn("PlatformCommercialMetrics $commercialMetrics", source)
        self.assertIn("'commercial_metrics' => $commercialMetrics->snapshot()", source)

    def test_incomplete_or_inconsistent_commercial_data_is_not_reported_as_valid_metrics(self) -> None:
        self.phpunit(
            "testIncompleteOrInconsistentCommercialDataIsNotReportedAsValidMetrics"
        )


if __name__ == "__main__":
    unittest.main()
