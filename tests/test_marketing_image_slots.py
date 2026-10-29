#!/usr/bin/env python3
"""Espacios para imagen del sitio público: rotulados, decorativos para lectores de pantalla y sin cambio de maquetación."""

from __future__ import annotations

import re
import unittest
from pathlib import Path

ROOT = Path(__file__).resolve().parents[1]
PAGES = ("templates/home/index.html.twig", "templates/marketing/empezar.html.twig")
CSS = (ROOT / "public" / "marketing.css").read_text(encoding="utf-8")


class MarketingImageSlotsTests(unittest.TestCase):
    def test_each_landing_hero_reserves_one_labeled_decorative_slot(self) -> None:
        for page in PAGES:
            with self.subTest(page=page):
                html = (ROOT / page).read_text(encoding="utf-8")
                self.assertEqual(html.count('<figure class="mk-img">'), 1)
                self.assertIn('class="mk-img-frame" aria-hidden="true"', html)
                self.assertRegex(html, r"Espacio para imagen · \d:\d · ")
                self.assertNotIn("<img", html)

    def test_slot_keeps_a_fixed_ratio_and_uses_only_palette_tokens(self) -> None:
        block = CSS[CSS.index(".mk-img-frame"):]
        self.assertIn("aspect-ratio: 4 / 3;", block)
        self.assertEqual(re.findall(r"#[0-9a-fA-F]{3,6}\b", block.split("}")[0]), [])


if __name__ == "__main__":
    unittest.main()
