#!/usr/bin/env python3
from __future__ import annotations

import subprocess
import unittest
from pathlib import Path

ROOT = Path(__file__).resolve().parents[1]
PHP_TEST = ROOT / "tests/php/Domain/AI/AiToolPolicyTest.php"
SOURCE = ROOT / "src/Domain/AI/AiToolPolicy.php"
VERSION = ROOT / "config/version.php"


class AiToolPolicyTests(unittest.TestCase):
    def phpunit(self, pattern: str) -> None:
        runner = ROOT / "vendor/bin/simple-phpunit"
        if not runner.exists():
            self.fail("vendor/bin/simple-phpunit no está disponible")
        result = subprocess.run(
            [str(runner), "--filter", pattern, str(PHP_TEST)],
            cwd=ROOT,
            text=True,
            capture_output=True,
            check=False,
        )
        self.assertEqual(0, result.returncode, result.stdout + result.stderr)

    def test_explicit_allowlist_classifies_read_only_and_reversible_tools(self) -> None:
        self.phpunit(
            "testExplicitAllowlistClassifiesReadOnlyAndReversibleTools"
        )
        self.assertIn(
            "'version' => '0.1.119'",
            VERSION.read_text(encoding="utf-8"),
        )
        source = SOURCE.read_text(encoding="utf-8")
        self.assertNotIn("PDO", source)
        self.assertNotIn("Doctrine", source)
        self.assertNotIn("HttpClient", source)

    def test_unknown_sensitive_or_direct_database_tools_fail_closed(self) -> None:
        self.phpunit(
            "testUnknownSensitiveOrDirectDatabaseToolsFailClosed"
        )


if __name__ == "__main__":
    unittest.main()
