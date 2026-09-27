#!/usr/bin/env python3
from __future__ import annotations

import subprocess
import unittest
from pathlib import Path

ROOT = Path(__file__).resolve().parents[1]
PHP_TEST = ROOT / "tests/php/Http/PlanConfiguratorPublicApiTest.php"


class PlanConfiguratorPublicApiTests(unittest.TestCase):
    def phpunit(self, name: str) -> None:
        run = subprocess.run(
            [str(ROOT / "vendor/bin/simple-phpunit"), "--filter", name, str(PHP_TEST)],
            cwd=ROOT,
            text=True,
            capture_output=True,
            check=False,
        )
        self.assertEqual(0, run.returncode, run.stdout + run.stderr)

    def test_public_catalog_and_options_use_canonical_read_model(self) -> None:
        self.phpunit("testPublicCatalogAndOptionsUseCanonicalReadModel")

    def test_preview_is_authoritative_and_does_not_persist_quote(self) -> None:
        self.phpunit("testPreviewIsAuthoritativeAndDoesNotPersistQuote")

    def test_incompatible_and_manual_scale_addons_fail_closed(self) -> None:
        self.phpunit("testIncompatibleAndManualScaleAddonsFailClosed")

    def test_preview_is_rate_limited_and_payload_allowlisted(self) -> None:
        self.phpunit("testPreviewIsRateLimitedAndPayloadAllowlisted")

    def test_legal_and_proposal_states_come_from_canonical_services(self) -> None:
        self.phpunit("testLegalAndProposalStatesComeFromCanonicalServices")


if __name__ == "__main__":
    unittest.main()
