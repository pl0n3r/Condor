#!/usr/bin/env python3
"""Contrato ejecutable de la propuesta de marca Condor."""

from __future__ import annotations

import json
import re
import unittest
from pathlib import Path


ROOT = Path(__file__).resolve().parents[1]
SPEC = ROOT / "docs/brand/condor-brand-proposal.json"
DOC = ROOT / "docs/brand/CONDOR-BRAND-PROPOSAL.md"


def _linear(channel: int) -> float:
    value = channel / 255.0
    return value / 12.92 if value <= 0.04045 else ((value + 0.055) / 1.055) ** 2.4


def _luminance(value: str) -> float:
    if re.fullmatch(r"#[0-9A-Fa-f]{6}", value) is None:
        raise AssertionError(f"Color hexadecimal inválido: {value!r}")
    red, green, blue = (int(value[index:index + 2], 16) for index in (1, 3, 5))
    return 0.2126 * _linear(red) + 0.7152 * _linear(green) + 0.0722 * _linear(blue)


def _contrast(foreground: str, background: str) -> float:
    high, low = sorted((_luminance(foreground), _luminance(background)), reverse=True)
    return (high + 0.05) / (low + 0.05)


class BrandProposalTests(unittest.TestCase):
    @classmethod
    def setUpClass(cls) -> None:
        cls.spec = json.loads(SPEC.read_text(encoding="utf-8"))
        cls.doc = DOC.read_text(encoding="utf-8")

    def test_logo_system_has_variants_clearspace_minimums_and_misuse_rules(self) -> None:
        logo = self.spec["logo_system"]
        self.assertEqual("Flightline", logo["name"])
        self.assertIn("ala", logo["concept"])
        self.assertIn("C abierta", logo["concept"])
        self.assertGreaterEqual(len(logo["variants"]), 5)
        self.assertIn("primary-horizontal", logo["variants"])
        self.assertIn("wordmark-only", logo["variants"])
        self.assertIn("symbol-only", logo["variants"])
        self.assertEqual("1x en los cuatro lados", logo["clearspace"]["minimum"])
        self.assertGreaterEqual(logo["minimum_sizes"]["digital_primary_width_px"], 96)
        self.assertGreaterEqual(logo["minimum_sizes"]["digital_symbol_px"], 24)
        self.assertGreaterEqual(len(logo["misuse_rules"]), 5)

    def test_candidate_palette_has_explicit_accessible_contrast_pairs(self) -> None:
        palette = self.spec["palette"]
        self.assertGreaterEqual(len(palette), 9)
        for name, token in palette.items():
            with self.subTest(token=name):
                self.assertRegex(token["hex"], r"^#[0-9A-F]{6}$")
                self.assertTrue(token["role"])

        pairs = self.spec["contrast_pairs"]
        self.assertGreaterEqual(len(pairs), 5)
        for pair in pairs:
            with self.subTest(pair=pair["usage"]):
                ratio = _contrast(pair["foreground"], pair["background"])
                self.assertGreaterEqual(ratio, pair["minimum_ratio"])
                self.assertGreaterEqual(pair["minimum_ratio"], 4.5)

    def test_semantic_token_mapping_is_proposal_only_and_runtime_untouched(self) -> None:
        mapping = self.spec["semantic_token_mapping"]
        self.assertEqual("proposal", mapping["mode"])
        self.assertTrue(mapping["preserve_semantic_layer"])
        self.assertEqual("condor_cobalt", mapping["mapping"]["--brand"])
        self.assertEqual("semantic_focus", mapping["mapping"]["--focus"])
        self.assertEqual("semantic_success", mapping["mapping"]["--green"])
        self.assertEqual("semantic_danger", mapping["mapping"]["--danger"])
        self.assertTrue(self.spec["adoption"]["runtime_change_forbidden_without_owner_approval"])
        self.assertIn("No se modifica public/app.css", self.doc)

    def test_final_brand_remains_explicitly_owner_gated(self) -> None:
        status = self.spec["status"]
        self.assertTrue(status["proposal_only"])
        self.assertTrue(status["owner_approval_required"])
        self.assertFalse(status["runtime_applied"])
        self.assertFalse(status["final_identity"])

        stages = self.spec["adoption"]["stages"]
        self.assertEqual(
            ["proposal", "owner-approval", "brand-assets", "public-surfaces", "backoffice"],
            [stage["name"] for stage in stages],
        )
        self.assertLess(
            next(stage["order"] for stage in stages if stage["name"] == "owner-approval"),
            next(stage["order"] for stage in stages if stage["name"] == "brand-assets"),
        )
        self.assertIn("un merge técnico no cuenta como aprobación", self.doc)


if __name__ == "__main__":
    unittest.main()
