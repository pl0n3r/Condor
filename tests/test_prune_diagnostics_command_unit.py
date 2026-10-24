#!/usr/bin/env python3
"""AC-01: executable regressions for diagnostics pruning without real records.

The PHP/MariaDB CI lane runs PHPUnit. This acceptance adapter additionally
verifies PHP syntax and executes the specific mock-only suite when installed.
"""
from __future__ import annotations

import shutil
import subprocess
import unittest
from pathlib import Path

ROOT = Path(__file__).resolve().parents[1]
SOURCE = ROOT / "src/Console/PruneDiagnosticsCommand.php"
PHP_TEST = ROOT / "tests/php/Console/PruneDiagnosticsCommandTest.php"
RUNNER = ROOT / "vendor/bin/simple-phpunit"
WORKFLOW = ROOT / ".github/workflows/ci.yml"


class PruneDiagnosticsCommandUnitTests(unittest.TestCase):
    def test_acceptance(self) -> None:
        for path in (SOURCE, PHP_TEST, WORKFLOW):
            self.assertTrue(path.is_file(), f"Required source or test missing: {path}")

        controller = SOURCE.read_text(encoding="utf-8")
        suite = PHP_TEST.read_text(encoding="utf-8")
        workflow = WORKFLOW.read_text(encoding="utf-8")

        self.assertIn("final class PruneDiagnosticsCommand", controller)
        self.assertIn("FILTER_VALIDATE_INT", controller)
        self.assertIn("DateTimeZone('UTC')", controller)
        self.assertIn("executeStatement(", controller)
        self.assertIn("WHERE expires_at < :now OR revoked_at IS NOT NULL", controller)
        self.assertIn("WHERE occurred_at < :cutoff", controller)
        self.assertIn("run: vendor/bin/simple-phpunit", workflow)

        for case in (
            "testRejectsInvalidRetentionWithoutIssuingAnyDelete",
            "testDefaultRetentionDeletesOnlyStrictlyExpiredRecordsWithUtcBindings",
            "testMinimumAndMaximumRetentionBothRespectTheUtcCutoff",
            "testRepeatedPruneIsSafeAndReportsZeroFurtherDeletions",
        ):
            self.assertIn(f"function {case}(", suite)

        for check in (
            "self::never()", "self::exactly(2)", "self::exactly(4)",
            "P14D", "P'.$days.'D", "assertWithinWindow(",
            "assertStringContainsString(", "willReturnOnConsecutiveCalls",
            "['now']", "['cutoff']",
        ):
            self.assertIn(check, suite, f"Missing negative/boundary check: {check}")

        php = shutil.which("php")
        self.assertIsNotNone(php, "PHP CLI required, not silently skipped")
        lint = subprocess.run(
            [php, "-l", str(PHP_TEST)],
            cwd=ROOT, capture_output=True, text=True, timeout=30, check=False,
        )
        self.assertEqual(lint.returncode, 0, lint.stdout + lint.stderr)

        # No DATABASE_URL required: the mock DBAL connection cannot access data.
        if RUNNER.is_file():
            run = subprocess.run(
                [str(RUNNER), "--configuration", "phpunit.xml.dist", str(PHP_TEST)],
                cwd=ROOT, capture_output=True, text=True, timeout=180,
                check=False,
            )
            self.assertEqual(run.returncode, 0, (run.stdout + run.stderr)[-3600:])


if __name__ == "__main__":
    unittest.main()
