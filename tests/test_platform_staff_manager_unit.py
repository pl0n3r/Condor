#!/usr/bin/env python3
"""#841 AC-01: execute real PlatformStaffManager PHPUnit tests against synthetic fixtures."""

from __future__ import annotations

import shutil
import subprocess
import unittest
from pathlib import Path

ROOT = Path(__file__).resolve().parents[1]
SOURCE = ROOT / "src/Application/Identity/PlatformStaffManager.php"
PHP_TEST = ROOT / "tests/php/Application/Identity/PlatformStaffManagerTest.php"
RUNNER = ROOT / "vendor/bin/simple-phpunit"
CONFIG = ROOT / "phpunit.xml.dist"


class PlatformStaffManagerUnitTests(unittest.TestCase):
    def test_acceptance(self) -> None:
        """Prove real tenant-scoped grants, negative ownership and invitation lifecycle."""
        for path in (SOURCE, PHP_TEST, CONFIG, RUNNER):
            self.assertTrue(path.is_file(), f"Missing PHP test dependency: {path}")

        php = shutil.which("php")
        self.assertIsNotNone(php, "PHP CLI required to execute unit acceptance")
        lint = subprocess.run(
            [php, "-l", str(PHP_TEST)],
            cwd=ROOT, capture_output=True, text=True, timeout=30, check=False,
        )
        self.assertEqual(0, lint.returncode, lint.stdout + lint.stderr)

        result = subprocess.run(
            [str(RUNNER), "--configuration", str(CONFIG), str(PHP_TEST)],
            cwd=ROOT, capture_output=True, text=True, timeout=240, check=False,
        )
        self.assertEqual(0, result.returncode, result.stdout + result.stderr)
        self.assertRegex(
            result.stdout + result.stderr,
            r"OK\s*\(\s*2 tests?,",
            "Both PHP regression tests must execute successfully",
        )


if __name__ == "__main__":
    unittest.main()
