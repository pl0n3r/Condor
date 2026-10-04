#!/usr/bin/env python3
from __future__ import annotations

import re
import unittest
from pathlib import Path

ROOT = Path(__file__).resolve().parents[1]
CI = ROOT / ".github/workflows/ci.yml"
DESCRIPTOR_TEST = ROOT / "tests/test_condor_ai_tool_descriptor.py"


class CondorAiCiContractsTests(unittest.TestCase):
    def test_ci_runs_all_condor_ai_contracts_after_composer_install(self) -> None:
        workflow = CI.read_text(encoding="utf-8")
        start = workflow.index("\n  backend-php:")
        end = workflow.index("\n  e2e:", start)
        backend = workflow[start:end]
        command = "python3 -m unittest discover -s tests -p 'test_condor_ai_*.py'"

        self.assertEqual(1, workflow.count(command))
        self.assertIn(command, backend)
        self.assertIn("composer install --no-interaction --prefer-dist --no-progress", backend)
        self.assertLess(
            backend.index("composer install --no-interaction --prefer-dist --no-progress"),
            backend.index(command),
        )

    def test_descriptor_contract_does_not_pin_historical_release_version(self) -> None:
        descriptor = DESCRIPTOR_TEST.read_text(encoding="utf-8")

        self.assertNotIn("VERSION =", descriptor)
        self.assertNotRegex(
            descriptor,
            re.compile(r"'version'\s*=>\s*'\d+\.\d+\.\d+'"),
        )
        self.assertIn(
            "def test_descriptor_is_canonical_minimized_and_offline",
            descriptor,
        )


if __name__ == "__main__":
    unittest.main()
