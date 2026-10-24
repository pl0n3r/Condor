#!/usr/bin/env python3
"""#845 AC-01: deterministic CLI tests for the platform-owner provisioning command.

No production database, tenant, account or secret is used. PHPUnit runs in the
canonical Condor PHP lane; this adapter verifies that the suite is discoverable.
"""
from __future__ import annotations

import shutil
import subprocess
import unittest
from pathlib import Path

ROOT = Path(__file__).resolve().parents[1]
COMMAND = ROOT / "src/Console/ProvisionPlatformOwnerCommand.php"
PROVISIONER = ROOT / "src/Application/Identity/ProvisionPlatformOwner.php"
TEST = ROOT / "tests/php/Console/ProvisionPlatformOwnerCommandTest.php"
PHPUNIT = ROOT / "phpunit.xml.dist"
WORKFLOW = ROOT / ".github/workflows/ci.yml"
RUNNER = ROOT / "vendor/bin/simple-phpunit"


class ProvisionPlatformOwnerCommandUnitTests(unittest.TestCase):
    def test_acceptance(self) -> None:
        for path in (COMMAND, PROVISIONER, TEST, PHPUNIT, WORKFLOW):
            self.assertTrue(path.is_file(), f"Missing required file: {path}")
        command = COMMAND.read_text(encoding="utf-8")
        suite = TEST.read_text(encoding="utf-8")
        config = PHPUNIT.read_text(encoding="utf-8")
        workflow = WORKFLOW.read_text(encoding="utf-8")
        self.assertIn("class ProvisionPlatformOwnerCommand extends Command", command)
        self.assertIn("$password = getenv($passwordEnv)", command)
        self.assertIn("$this->provisionPlatformOwner->execute(", command)
        self.assertIn("<directory>tests/php</directory>", config)
        self.assertIn("run: vendor/bin/simple-phpunit", workflow)
        for method in (
            "testMissingOrEmptyPasswordEnvironmentFailsWithoutAccessingStorage",
            "testInvalidEmailNameAndShortPasswordFailBeforeDatabaseAccess",
            "testNewOwnerIsProvisionedAndPasswordIsNeverPrinted",
            "testRepeatedProvisioningKeepsTheSameOwnerAndRotatesHash",
            "testSecondOwnerIsRejectedAndTransactionRollsBackWithoutLeakingPassword",
            "testDatabaseFailureReturnsFailureWithoutPrintingPassword",
        ):
            self.assertIn(f"function {method}(", suite)
        for essential in (
            "Command::FAILURE", "Command::SUCCESS", "assertStringNotContainsString",
            "expects(self::never())->method('persist')",
            "expects(self::once())->method('rollBack')",
            "ROLE_PLATFORM_OWNER", "ROLE_LEGACY_SUPER_ADMIN",
            "synthetic-test-password", "--password-env",
        ):
            self.assertIn(essential, suite)

        php = shutil.which("php")
        self.assertIsNotNone(php, "PHP CLI is required")
        lint = subprocess.run(
            [php, "-l", str(TEST)], cwd=ROOT, capture_output=True,
            text=True, timeout=30, check=False,
        )
        self.assertEqual(lint.returncode, 0, lint.stdout + lint.stderr)
        if RUNNER.is_file():
            result = subprocess.run(
                [str(RUNNER), "--configuration", str(PHPUNIT), str(TEST)],
                cwd=ROOT, capture_output=True, text=True, timeout=180, check=False,
            )
            self.assertEqual(result.returncode, 0, (result.stdout + result.stderr)[-3600:])


if __name__ == "__main__":
    unittest.main()
