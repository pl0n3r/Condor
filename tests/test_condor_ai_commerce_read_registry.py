#!/usr/bin/env python3
from __future__ import annotations

import subprocess
import unittest
from pathlib import Path


ROOT = Path(__file__).resolve().parents[1]
PHPUNIT = ROOT / "vendor" / "bin" / "simple-phpunit"
TEST_FILE = "tests/php/Application/AI/AiCommerceReadRegistryTest.php"
SOURCE = ROOT / "src/Application/AI/AiCommerceReadRegistry.php"
POLICY = ROOT / "src/Domain/AI/AiToolPolicy.php"


class CondorAiCommerceReadRegistryTests(unittest.TestCase):
    def _assert_php_case(self, method: str) -> None:
        subprocess.check_call(
            [str(PHPUNIT), "--filter", method, TEST_FILE],
            cwd=ROOT,
            stdout=subprocess.DEVNULL,
            stderr=subprocess.STDOUT,
            timeout=60,
        )

    def test_registry_binds_only_catalog_and_inventory_read_with_injected_handlers(
        self,
    ) -> None:
        self._assert_php_case(
            "testRegistryBindsOnlyCatalogAndInventoryReadWithInjectedHandlers"
        )

    def test_invalid_inputs_fail_before_handler_and_invalid_outputs_fail_closed(
        self,
    ) -> None:
        self._assert_php_case(
            "testInvalidInputsFailBeforeHandlerAndInvalidOutputsFailClosed"
        )
        self._assert_php_case("testMissingExtraOrNonClosureHandlersFailClosed")

    def test_registry_has_no_database_network_provider_or_mutating_tool_authority(
        self,
    ) -> None:
        source = SOURCE.read_text(encoding="utf-8").lower()
        policy = POLICY.read_text(encoding="utf-8")

        forbidden = (
            "pdo",
            "doctrine",
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
            "commerce.price.read",
            "draft.update",
            "permission.change",
        )
        for marker in forbidden:
            with self.subTest(marker=marker):
                self.assertNotIn(marker, source)

        self.assertIn("'catalog.read' => self::READ_ONLY", policy)
        self.assertIn("'inventory.read' => self::READ_ONLY", policy)
        self.assertNotIn("commerce.price.read", policy)

    def test_binding_is_deterministic_and_preserves_existing_tool_policy(
        self,
    ) -> None:
        self._assert_php_case(
            "testBindingIsDeterministicAndPreservesExistingToolPolicy"
        )


if __name__ == "__main__":
    unittest.main()
