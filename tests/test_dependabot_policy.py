#!/usr/bin/env python3
"""Regresión de política Dependabot para evitar majors automáticos fuera del stack."""

from __future__ import annotations

import unittest
from pathlib import Path


ROOT = Path(__file__).resolve().parents[1]
CONFIG = ROOT / ".github/dependabot.yml"
ECOSYSTEMS = ("composer", "npm", "github-actions")


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

    def test_composer_major_updates_are_ignored(self) -> None:
        block = self.block("composer")
        self.assertIn("ignore:", block)
        self.assertIn('dependency-name: "*"', block)
        self.assertIn('update-types: ["version-update:semver-major"]', block)

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
                self.assertIn("ignore:", block)
                self.assertIn('dependency-name: "*"', block)
                self.assertIn(
                    'update-types: ["version-update:semver-major"]',
                    block,
                )


if __name__ == "__main__":
    unittest.main()
