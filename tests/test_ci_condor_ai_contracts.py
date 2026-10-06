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


    def test_all_ai_contract_fixtures_are_release_agnostic_and_php85_clean(
        self,
    ) -> None:
        fixtures = sorted((ROOT / "tests").glob("test_condor_ai_*.py"))
        self.assertEqual(25, len(fixtures))

        release_pin = re.compile(r"'version'\\s*=>\\s*'0\\.1\\.\\d+'")
        forbidden_imports = (
            "use DateTimeImmutable;",
            "use DomainException;",
            "use RuntimeException;",
            "use stdClass;",
        )

        for fixture in fixtures:
            source = fixture.read_text(encoding="utf-8")
            with self.subTest(fixture=fixture.name):
                compile(source, fixture.name, "exec")
                self.assertNotIn('VERSION = ROOT / "config/version.php"', source)
                self.assertNotIn("VERSION.read_text", source)
                self.assertNotRegex(source, release_pin)
                for forbidden in forbidden_imports:
                    self.assertNotIn(forbidden, source)

        replay = (
            ROOT / "tests/test_condor_ai_conversation_replay_bridge.py"
        ).read_text(encoding="utf-8")
        self.assertIn('vendor" / "bin" / "simple-phpunit"', replay)
        self.assertNotIn('vendor" / "bin" / "phpunit"', replay)

        handoff = (
            ROOT / "tests/test_condor_ai_handoff_envelope.py"
        ).read_text(encoding="utf-8")
        self.assertIn("AiToolRegistry::fromArray($policy, [])", handoff)
        self.assertNotIn("$executor", handoff)


if __name__ == "__main__":
    unittest.main()
