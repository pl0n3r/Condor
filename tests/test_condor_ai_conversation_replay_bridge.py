#!/usr/bin/env python3
from __future__ import annotations

import subprocess
import unittest
from pathlib import Path

ROOT = Path(__file__).resolve().parents[1]
PHPUNIT = ROOT / "vendor" / "bin" / "simple-phpunit"
TEST_FILE = "tests/php/Application/AI/AiConversationCoreTest.php"


class CondorAiConversationReplayBridgeTests(unittest.TestCase):
    def _run(self, method: str) -> None:
        subprocess.run(
            [str(PHPUNIT), TEST_FILE, "--filter", method],
            cwd=ROOT,
            check=True,
            capture_output=True,
            text=True,
            timeout=60,
        )

    def test_reversible_write_requires_guard_and_duplicate_never_reexecutes_handler(
        self,
    ) -> None:
        self._run(
            "testReversibleWriteRequiresGuardAndDuplicateNeverReexecutesHandler"
        )

    def test_read_only_and_sensitive_paths_preserve_existing_semantics(self) -> None:
        self._run(
            "testReadOnlyAndSensitivePathsPreserveExistingSemanticsWithGuard"
        )


if __name__ == "__main__":
    unittest.main()
