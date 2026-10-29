#!/usr/bin/env python3
"""El configurador del plan comparte la identidad Flightline del sitio público sin tocar el código React."""

from __future__ import annotations

import re
import unittest
from pathlib import Path

ROOT = Path(__file__).resolve().parents[1]
TWIG = (ROOT / "templates/configurator/index.html.twig").read_text(encoding="utf-8")
CSS = (ROOT / "public/configurator.css").read_text(encoding="utf-8")
BLOCK = CSS[CSS.index("/* Identidad Flightline en el configurador"):]


class ConfiguratorIdentityTests(unittest.TestCase):
    def test_page_uses_the_public_site_shell_and_keeps_the_react_mount_and_scripts(self) -> None:
        self.assertIn("marketing/_nav.html.twig", TWIG)
        self.assertIn('<div class="mk-site">', TWIG)
        self.assertIn('/marketing.css', TWIG)
        for needle in ('id="condor-configurator-root"', "/configurator-telemetry.js", "/build/configurator.js", "Cargando configurador…"):
            with self.subTest(needle=needle):
                self.assertIn(needle, TWIG)

    def test_the_title_comes_first_and_the_eyebrow_stays_below_it_via_the_shared_header_block(self) -> None:
        self.assertIn('class="mk-top configurator-top"', TWIG)
        self.assertIn('<h1 id="configurator-title">', TWIG)

    def test_selected_choice_has_a_visible_non_color_marker_and_a_focus_ring(self) -> None:
        self.assertIn('.choice:has(input:checked)::after { content: "✓";', BLOCK)
        self.assertIn(".choice:has(input:focus-visible) { outline: 3px solid var(--mk-link);", BLOCK)

    def test_block_uses_site_tokens_and_has_no_important_or_transition_all(self) -> None:
        summary = BLOCK.split(".mk-site .quote-summary {", 1)[1].split("}", 1)[0]
        self.assertEqual(re.findall(r"#[0-9a-fA-F]{3,6}\b", BLOCK.replace(summary, "")), [])
        self.assertNotIn("!important", BLOCK)
        self.assertNotRegex(BLOCK, r"transition:\s*all\b")

    def test_hover_motion_only_runs_when_motion_is_allowed(self) -> None:
        hover = BLOCK[BLOCK.index("@media (hover: hover)"):]
        self.assertIn("prefers-reduced-motion: no-preference", hover.split("{", 1)[0])

    def test_the_lead_under_the_title_overrides_the_muted_class_so_it_keeps_aa_on_navy(self) -> None:
        self.assertIn(".mk-site .configurator-top .muted, .mk-site .configurator-top .mk-lead { color: var(--mk-ink-2); }", CSS)

    def test_choice_cards_and_hints_use_the_themed_secondary_color_so_dark_mode_keeps_aa(self) -> None:
        self.assertIn(".mk-site .choice span, .mk-site .choice small, .mk-site .choice .muted, .mk-site .choice .hint, .mk-site .configurator-flow .hint, .mk-site .configurator-flow .muted { color: var(--mk-ink-2); }", CSS)


if __name__ == "__main__":
    unittest.main()
