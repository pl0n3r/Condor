#!/usr/bin/env python3
"""Regresión de política Dependabot para evitar majors automáticos fuera del stack."""

from __future__ import annotations

import unittest
from pathlib import Path


ROOT = Path(__file__).resolve().parents[1]
CONFIG = ROOT / ".github/dependabot.yml"
ECOSYSTEMS = ("composer", "npm", "github-actions")
MAJOR_UPDATE = "version-update:semver-major"


class DependabotPolicyTests(unittest.TestCase):
    @classmethod
    def setUpClass(cls) -> None:
        cls.config = CONFIG.read_text(encoding="utf-8")

    def block(self, ecosystem: str) -> str:
        marker = f'  - package-ecosystem: "{ecosystem}"'
        self.assertIn(marker, self.config)
        block = self.config.split(marker, 1)[1]
        next_marker = "\n  - package-ecosystem:"
        if next_marker in block:
            block = block.split(next_marker, 1)[0]
        return block

    def ignore_entries(self, ecosystem: str) -> list[dict[str, object]]:
        block = self.block(ecosystem)
        entries: list[dict[str, object]] = []
        current: dict[str, object] | None = None
        inside_ignore = False

        for line in block.splitlines():
            if line == "    ignore:":
                inside_ignore = True
                continue
            if not inside_ignore:
                continue
            if line.startswith("    ") and not line.startswith("      "):
                break

            stripped = line.strip()
            if stripped.startswith("- dependency-name:"):
                if current is not None:
                    entries.append(current)
                current = {
                    "dependency-name": stripped.split(":", 1)[1].strip().strip('"'),
                    "update-types": [],
                }
                continue

            if current is not None and stripped.startswith("update-types:"):
                raw = stripped.split(":", 1)[1].strip()
                values = [
                    item.strip().strip('"')
                    for item in raw.strip("[]").split(",")
                    if item.strip()
                ]
                current["update-types"] = values

        if current is not None:
            entries.append(current)

        return entries

    def assert_major_ignore_rule(self, ecosystem: str) -> None:
        entries = self.ignore_entries(ecosystem)
        self.assertTrue(
            any(
                entry.get("dependency-name") == "*"
                and MAJOR_UPDATE in entry.get("update-types", [])
                for entry in entries
            ),
            f"{ecosystem} debe ignorar majors con una sola regla dependency-name/update-types.",
        )

    def test_composer_major_updates_are_ignored(self) -> None:
        self.assert_major_ignore_rule("composer")

    def test_minor_patch_updates_remain_grouped(self) -> None:
        expected_groups = {
            "composer": "composer-minor:",
            "npm": "npm-minor:",
            "github-actions": "github-actions-minor:",
        }
        for ecosystem, group in expected_groups.items():
            with self.subTest(ecosystem=ecosystem):
                block = self.block(ecosystem)
                self.assertIn(group, block)
                self.assertIn('update-types: ["minor", "patch"]', block)

    def test_policy_is_covered_by_repository_contract(self) -> None:
        for ecosystem in ECOSYSTEMS:
            with self.subTest(ecosystem=ecosystem):
                block = self.block(ecosystem)
                self.assertIn("open-pull-requests-limit: 3", block)
                self.assert_major_ignore_rule(ecosystem)


if __name__ == "__main__":
    unittest.main()
