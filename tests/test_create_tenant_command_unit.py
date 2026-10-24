#!/usr/bin/env python3
"""#844: acceptance probe for CreateTenantCommand with fake persistence only."""

import re
import shutil
import subprocess
import unittest
from pathlib import Path

BASE = Path(__file__).resolve().parents[1]
CONSOLE = BASE / "src/Console/CreateTenantCommand.php"
SUITE = BASE / "tests/php/Console/CreateTenantCommandTest.php"


class CreateTenantCommandUnitTests(unittest.TestCase):
    def test_acceptance(self) -> None:
        self.assertTrue(CONSOLE.is_file())
        self.assertTrue(SUITE.is_file())

        command = CONSOLE.read_text(encoding="utf8")
        suite = SUITE.read_text(encoding="utf8")
        self.assertIn("final class CreateTenantCommand extends Command", command)
        self.assertIn("new CreateTenantInput(", command)
        self.assertIn("getenv($passwordEnv)", command)
        scenarios = set(re.findall(r"function (test[A-Za-z]+)\(", suite))
        self.assertTrue({
            "testAbsentPasswordFailsBeforeAnyPersistence",
            "testInvalidInputIsRejectedWithoutStartingTransaction",
            "testExistingSlugRejectsRepeatedRequestsWithoutWrites",
            "testDuplicateOwnerEmailAlsoPreventsTransaction",
            "testSuccessfulProvisioningReturnsIdsButNeverThePassword",
        }.issubset(scenarios))
        self.assertIn("self::SECRET", suite)
        self.assertIn("wrapInTransaction", suite)
        self.assertIn("assertStringNotContainsString", suite)

        php = shutil.which("php")
        self.assertIsNotNone(php, "PHP executable is mandatory for acceptance")
        runner = BASE / "vendor/bin/simple-phpunit"
        commands = [[php, "-l", str(SUITE)]]
        if runner.is_file():
            commands.append([str(runner), "--configuration", "phpunit.xml.dist", str(SUITE)])
        for argv in commands:
            result = subprocess.run(
                argv, cwd=BASE, capture_output=True, text=True,
                timeout=180, check=False,
            )
            self.assertEqual(0, result.returncode, (result.stdout + result.stderr)[-3000:])


if __name__ == "__main__":
    unittest.main()
