#!/usr/bin/env python3
"""AC-01: real ControlBotRequestAuthenticator PHPUnit coverage plus CI wiring guard.

Backend PHP / MariaDB runs the PHP tests; the contracts job runs without
Composer/vendor or MariaDB and validates the CI wiring plus PHP syntax.
"""
from __future__ import annotations

import os
import shutil
import subprocess
import unittest
from pathlib import Path

ROOT = Path(__file__).resolve().parents[1]
PHP_TEST = ROOT / "tests/php/Infrastructure/Security/ControlBotRequestAuthenticatorTest.php"
SOURCE = ROOT / "src/Infrastructure/Security/ControlBotRequestAuthenticator.php"
RUNNER = ROOT / "vendor/bin/simple-phpunit"
WORKFLOW = ROOT / ".github/workflows/ci.yml"


class ControlBotRequestAuthenticatorUnitTests(unittest.TestCase):
    def test_acceptance(self) -> None:
        self.assertTrue(SOURCE.is_file(), "Source of real authenticator missing")
        self.assertTrue(PHP_TEST.is_file(), "PHPUnit regression suite missing")
        self.assertTrue(WORKFLOW.is_file(), "Canonical CI workflow missing")
        workflow = WORKFLOW.read_text(encoding="utf-8")
        self.assertIn("run: vendor/bin/simple-phpunit", workflow,
                      "PHP/MariaDB CI must execute the real PHPUnit suite")
        self.assertIn("name: Backend PHP / MariaDB", workflow,
                      "PHP tests must execute with MariaDB, not static assertions")
        php = shutil.which("php")
        self.assertIsNotNone(php, "PHP CLI required by CI contracts")
        lint = subprocess.run([php, "-l", str(PHP_TEST)], cwd=ROOT,
                              capture_output=True, text=True, check=False, timeout=30)
        self.assertEqual(0, lint.returncode, lint.stdout + lint.stderr)
        if RUNNER.is_file() and "DATABASE_URL" in os.environ:
            result = subprocess.run(
                [str(RUNNER), "--configuration", "phpunit.xml.dist", str(PHP_TEST)],
                cwd=ROOT, capture_output=True, text=True, check=False, timeout=240,
            )
            self.assertEqual(0, result.returncode, result.stdout + result.stderr)


if __name__ == "__main__":
    unittest.main()
