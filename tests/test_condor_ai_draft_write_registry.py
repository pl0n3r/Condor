#!/usr/bin/env python3
from __future__ import annotations

import subprocess
import unittest
from pathlib import Path

ROOT = Path(__file__).resolve().parents[1]
PHPUNIT = ROOT / "vendor" / "bin" / "simple-phpunit"
TEST_FILE = "tests/php/Application/AI/AiDraftWriteRegistryTest.php"
SOURCE = ROOT / "src/Application/AI/AiDraftWriteRegistry.php"
POLICY = ROOT / "src/Domain/AI/AiToolPolicy.php"


class CondorAiDraftWriteRegistryTests(unittest.TestCase):
    def _php(self, method: str) -> None:
        subprocess.check_call(
            [str(PHPUNIT), "--filter", method, TEST_FILE],
            cwd=ROOT,
            stdout=subprocess.DEVNULL,
            stderr=subprocess.STDOUT,
            timeout=60,
        )

    def test_registry_binds_only_existing_draft_write_tools_with_injected_handlers(self) -> None:
        self._php("testRegistryBindsOnlyExistingDraftWriteToolsWithInjectedHandlers")

    def test_invalid_inputs_fail_before_handler_and_invalid_outputs_fail_closed(self) -> None:
        for method in (
            "testInvalidInputsFailBeforeHandlerAndInvalidOutputsFailClosed",
            "testMissingExtraOrNonClosureHandlersFailClosed",
        ):
            self._php(method)

    def test_registry_preserves_reversible_write_risk_without_database_network_or_provider(self) -> None:
        self._php("testRegistryPreservesReversibleWriteRiskAndRejectsUnknownTools")
        source = SOURCE.read_text(encoding="utf-8").lower()
        policy = POLICY.read_text(encoding="utf-8")
        for marker in (
            "pdo", "doctrine", "httpclient", "curl_", "file_get_contents(",
            "fopen(", "guzzle", "provider", "model", "channel", "secret",
            "token", "credential",
        ):
            with self.subTest(marker=marker):
                self.assertNotIn(marker, source)
        self.assertIn("'content.draft.update' => self::REVERSIBLE_WRITE", policy)
        self.assertIn("'settings.draft.update' => self::REVERSIBLE_WRITE", policy)

    def test_binding_is_deterministic_and_carries_no_freeform_payload(self) -> None:
        self._php("testBindingIsDeterministicAndCarriesNoFreeformPayload")
        source = SOURCE.read_text(encoding="utf-8").lower()
        for marker in ("payload", "prompt", "transcript"):
            self.assertNotIn(marker, source)


if __name__ == "__main__":
    unittest.main()
