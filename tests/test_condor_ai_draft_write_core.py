#!/usr/bin/env python3
from __future__ import annotations

import subprocess
import unittest
from pathlib import Path

ROOT = Path(__file__).resolve().parents[1]
PHPUNIT = ROOT / "vendor" / "bin" / "simple-phpunit"
TEST_FILE = "tests/php/Application/AI/AiDraftWriteRuntimeTest.php"
SOURCE = ROOT / "src/Application/AI/AiDraftWriteRuntime.php"
CORE = ROOT / "src/Application/AI/AiConversationCore.php"


class CondorAiDraftWriteCoreTests(unittest.TestCase):
    def _php(self, method: str) -> None:
        subprocess.check_call(
            [str(PHPUNIT), "--filter", method, TEST_FILE],
            cwd=ROOT,
            stdout=subprocess.DEVNULL,
            stderr=subprocess.STDOUT,
            timeout=60,
        )

    def test_draft_updates_flow_through_existing_conversation_core_with_reversible_write_receipt(self) -> None:
        self._php(
            "testDraftUpdatesFlowThroughExistingConversationCoreWithReversibleWriteReceipt"
        )
        source = SOURCE.read_text(encoding="utf-8")
        self.assertIn("AiConversationCore::turn(", source)
        self.assertIn("AiDraftWriteRegistry::fromArray", source)

    def test_missing_replay_guard_handoffs_before_handler_execution(self) -> None:
        self._php("testMissingReplayGuardHandoffsBeforeHandlerExecution")
        core = CORE.read_text(encoding="utf-8")
        self.assertIn("'tool_replay_guard_required'", core)

    def test_duplicate_replay_key_never_executes_handler_twice(self) -> None:
        self._php("testDuplicateReplayKeyNeverExecutesHandlerTwice")
        core = CORE.read_text(encoding="utf-8")
        self.assertIn("$replayGuard->claim($replayKey)", core)
        self.assertIn("'tool_replay_detected'", core)

    def test_cross_tenant_unknown_tool_invalid_contract_or_handler_failure_fail_closed(self) -> None:
        self._php("testCrossTenantUnknownToolInvalidContractOrHandlerFailureFailClosed")

    def test_runtime_has_no_database_network_provider_real_data_or_authority_expansion(self) -> None:
        self._php("testRuntimeHasNoDatabaseNetworkProviderRealDataOrAuthorityExpansion")
        source = SOURCE.read_text(encoding="utf-8").lower()
        for marker in (
            "pdo",
            "doctrine",
            "redis",
            "httpclient",
            "curl_",
            "file_get_contents(",
            "fopen(",
            "guzzle",
            "provider",
            "model",
            "channel",
            "secret",
            "token",
            "credential",
        ):
            with self.subTest(marker=marker):
                self.assertNotIn(marker, source)
        self.assertNotIn("new aitoolpolicy", source)
        self.assertIn("?AiToolReplayGuard $replayGuard = null", SOURCE.read_text(encoding="utf-8"))


if __name__ == "__main__":
    unittest.main()
