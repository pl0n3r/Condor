#!/usr/bin/env python3
from __future__ import annotations

import subprocess
import unittest
from pathlib import Path


ROOT = Path(__file__).resolve().parents[1]
PHPUNIT = ROOT / "vendor" / "bin" / "simple-phpunit"
TEST_FILE = "tests/php/Application/AI/AiCommerceReadRuntimeTest.php"
SOURCE = ROOT / "src/Application/AI/AiCommerceReadRuntime.php"


class CondorAiCommerceReadCoreTests(unittest.TestCase):
    def _assert_php_case(self, method: str) -> None:
        subprocess.check_call(
            [str(PHPUNIT), "--filter", method, TEST_FILE],
            cwd=ROOT,
            stdout=subprocess.DEVNULL,
            stderr=subprocess.STDOUT,
            timeout=60,
        )

    def test_catalog_and_inventory_lookup_flow_through_existing_conversation_core_with_minimized_receipt(self) -> None:
        self._assert_php_case(
            "testCatalogAndInventoryLookupFlowThroughExistingConversationCoreWithMinimizedReceipt"
        )

    def test_cross_tenant_unknown_tool_or_invalid_contract_handoff_before_accepting_result(self) -> None:
        self._assert_php_case(
            "testCrossTenantUnknownToolOrInvalidContractHandoffBeforeAcceptingResult"
        )

    def test_read_only_commerce_path_never_requires_or_acquires_write_authority(self) -> None:
        self._assert_php_case(
            "testReadOnlyCommercePathNeverRequiresOrAcquiresWriteAuthority"
        )

    def test_runtime_composition_has_no_database_network_provider_or_real_data_dependency(self) -> None:
        source = SOURCE.read_text(encoding="utf-8").lower()
        self.assertIn("aiconversationcore::turn", source)
        self.assertIn("aicommercereadregistry::fromarray", source)
        self.assertNotIn("aitoolreplayguard", source)
        for marker in (
            "doctrine",
            "entitymanager",
            "pdo",
            "httpclient",
            "curl_",
            "guzzle",
            "provider",
            "model",
            "channel",
        ):
            with self.subTest(marker=marker):
                self.assertNotIn(marker, source)


if __name__ == "__main__":
    unittest.main()
