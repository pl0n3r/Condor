#!/usr/bin/env python3
"""Los tokens del backoffice (public/app.css) siguen el mapeo semántico de la marca aprobada."""

from __future__ import annotations

import json
import re
import unittest
from pathlib import Path

ROOT = Path(__file__).resolve().parents[1]
CSS = (ROOT / "public" / "app.css").read_text(encoding="utf-8")
SPEC = json.loads(
    (ROOT / "docs" / "brand" / "condor-brand-proposal.json").read_text(encoding="utf-8")
)


def _root_tokens() -> dict[str, str]:
    block = re.search(r":root\s*\{(?P<body>.*?)\n\}", CSS, re.DOTALL)
    assert block is not None, "No se encontró el bloque :root de app.css"
    return dict(re.findall(r"(--[a-z0-9-]+):\s*(#[0-9a-fA-F]{6})", block.group("body")))


class BrandAdoptionBackofficeTests(unittest.TestCase):
    def test_root_tokens_follow_the_semantic_mapping(self) -> None:
        tokens = _root_tokens()
        mapping = SPEC["semantic_token_mapping"]["mapping"]
        self.assertGreaterEqual(len(mapping), 9)
        for token, color in mapping.items():
            with self.subTest(token=token):
                self.assertEqual(
                    tokens[token].lower(), SPEC["palette"][color]["hex"].lower(),
                )

    def test_functional_colors_stay_independent_from_the_brand(self) -> None:
        tokens = _root_tokens()
        brand = tokens["--brand"].lower()
        for functional in ("--green", "--danger", "--focus"):
            with self.subTest(token=functional):
                self.assertNotEqual(tokens[functional].lower(), brand)

    def test_semantic_layer_keeps_the_tokens_components_consume(self) -> None:
        tokens = _root_tokens()
        for token in ("--bg", "--surface", "--text", "--muted", "--border", "--accent"):
            with self.subTest(token=token):
                self.assertIn(token, tokens)


class BackofficeImpactTests(unittest.TestCase):
    BLOCK = CSS[CSS.index("/* Backoffice con la identidad de impacto"):]

    def test_eyebrow_text_meets_wcag_aa_using_the_muted_token(self) -> None:
        self.assertIn(".eyebrow { color: var(--muted); }", self.BLOCK)

    def test_navigation_uses_semantic_tokens_and_marks_the_current_page_without_relying_on_hover(self) -> None:
        self.assertIn("background: var(--accent);", self.BLOCK)
        self.assertIn('.admin-shell .sidebar a[aria-current="page"] { background: var(--brand);', self.BLOCK)
        self.assertIn(".admin-shell .sidebar a:focus-visible { outline: 3px solid #ffffff;", self.BLOCK)

    def test_transitions_are_switched_off_with_reduced_motion_and_never_use_all(self) -> None:
        self.assertIn("prefers-reduced-motion: reduce", self.BLOCK)
        self.assertNotRegex(self.BLOCK, r"transition:\s*all\b")
        self.assertNotIn("!important", self.BLOCK)

    def test_block_leaves_the_root_tokens_untouched(self) -> None:
        self.assertNotIn(":root", self.BLOCK)

    def test_on_narrow_screens_the_menu_becomes_a_horizontally_scrollable_row(self) -> None:
        narrow = self.BLOCK[self.BLOCK.index("@media (max-width: 860px)"):]
        self.assertIn(".admin-shell .sidebar nav { display: flex;", narrow)
        self.assertIn("overflow-x: auto;", narrow)
        self.assertIn("white-space: nowrap;", narrow)

    def test_buttons_inside_the_navy_menu_have_light_text(self) -> None:
        self.assertIn(".admin-shell .sidebar .button { color: #eef1f8;", self.BLOCK)


if __name__ == "__main__":
    unittest.main()
