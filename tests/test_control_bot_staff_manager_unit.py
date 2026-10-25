#!/usr/bin/env python3
"""AC-01: coverage and execution wiring for ControlBotStaffManager.

The PHP/MariaDB CI job executes the real PHPUnit suite. The Python contracts
job checks the CI wiring and PHP syntax even without Composer or a database.
"""

from __future__ import annotations

import os
import shutil
import subprocess
import unittest
from pathlib import Path

ROOT = Path(__file__).resolve().parents[1]
SOURCE = ROOT / "src/Application/Identity/ControlBotStaffManager.php"
PHP_TEST = ROOT / "tests/php/Application/Identity/ControlBotStaffManagerTest.php"
RUNNER = ROOT / "vendor/bin/simple-phpunit"
WORKFLOW = ROOT / ".github/workflows/ci.yml"


class ControlBotStaffManagerUnitTests(unittest.TestCase):
    def test_acceptance(self) -> None:
        for path in (SOURCE, PHP_TEST, WORKFLOW):
            self.assertTrue(path.is_file(), f"Required source/test missing: {path}")

        workflow = WORKFLOW.read_text(encoding="utf-8")
        self.assertIn("name: Backend PHP / MariaDB", workflow)
        self.assertIn("run: vendor/bin/simple-phpunit", workflow)
        # Verify that the actual application class and its regression suite
        # are executed rather than considering a static placeholder sufficient.
        source = SOURCE.read_text(encoding="utf-8")
        suite = PHP_TEST.read_text(encoding="utf-8")
        self.assertIn("final readonly class ControlBotStaffManager", source)
        for operation in ("invite", "deliverInvitation", "revokeInvitation",
                          "reissueInvitation", "suspend", "reactivate", "setRole"):
            self.assertIn(f"function {operation}(", source)
        for scenario in ("testInvitationDeliveryRevocationAndReactivationAreExplicit",
                         "testInvalidOrUnavailableInvitationsCannotPersistAccounts",
                         "testNonStaffAndPrivilegedAccountsCannotBeMutatedByControlBot"):
            self.assertIn(f"function {scenario}(", suite)

        php = shutil.which("php")
        self.assertIsNotNone(php, "PHP CLI is required for lint in CI contracts")
        lint = subprocess.run(
            [php, "-l", str(PHP_TEST)], cwd=ROOT, capture_output=True,
            text=True, check=False, timeout=30,
        )
        self.assertEqual(0, lint.returncode, lint.stdout + lint.stderr)

        # The Backend PHP / MariaDB job always runs PHPUnit. Run the exact
        # suite here too when Composer and a database are available.
        if RUNNER.is_file() and os.environ.get("DATABASE_URL"):
            result = subprocess.run(
                [str(RUNNER), "--configuration", "phpunit.xml.dist", str(PHP_TEST)],
                cwd=ROOT, capture_output=True, text=True, check=False, timeout=240,
            )
            self.assertEqual(0, result.returncode, result.stdout + result.stderr)


if __name__ == "__main__":
    unittest.main()
