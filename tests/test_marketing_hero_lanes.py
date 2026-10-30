#!/usr/bin/env python3
"""Hero del home: mientras no hay foto, el marco muestra los carriles del plano dibujándose en bucle."""

from __future__ import annotations

import re
import unittest
from pathlib import Path

ROOT = Path(__file__).resolve().parents[1]
HOME = (ROOT / "templates" / "home" / "index.html.twig").read_text(encoding="utf-8")
CSS = (ROOT / "public" / "marketing.css").read_text(encoding="utf-8")
LANES = CSS[CSS.index("Hero del home: mientras no hay foto"):]


class MarketingHeroLanesTests(unittest.TestCase):
    def test_hero_frame_holds_four_decorative_lanes_and_no_inline_style(self) -> None:
        frame = HOME[HOME.index('<div class="mk-img-frame"'):HOME.index("</figure>")]
        self.assertIn('aria-hidden="true"', frame)
        self.assertEqual(len(re.findall(r'class="mk-lanes-line mk-lanes-', frame)), 4)
        self.assertNotIn("style=", frame)
        self.assertNotIn("<img", HOME)
        self.assertIn("Espacio para imagen · 4:3", frame)
        for label in ("Vender", "Gente", "Cumplir", "Crecer"):
            with self.subTest(label=label):
                self.assertIn(">" + label + "</text>", frame)
        self.assertEqual(frame.count('class="mk-lanes-rail"'), 4)
        self.assertEqual(frame.count('class="mk-lanes-train"'), 4)

    def test_every_animation_runs_only_without_reduced_motion(self) -> None:
        outside = re.sub(r"@media \(prefers-reduced-motion: no-preference\) \{.*", "", LANES, flags=re.S)
        self.assertNotIn("animation", outside)
        self.assertIn("@media (prefers-reduced-motion: no-preference)", LANES)

    def test_reduced_motion_does_not_show_trains_at_svg_origin(self) -> None:
        # Sin animación no hay offset-path: los puntos (cx=0, cy=0)
        # deben estar ocultos, no amontonados en la esquina del SVG.
        static = LANES.split("@media (prefers-reduced-motion: no-preference)", 1)[0]
        self.assertRegex(
            static,
            r"\.mk-lanes-line \.mk-lanes-train\s*\{[^}]*opacity:\s*0;",
        )
        self.assertIn("animation: mk-lane-run", LANES)

    def test_lane_colors_are_tokens_defined_in_the_hero_block(self) -> None:
        self.assertEqual(re.findall(r"#[0-9a-fA-F]{3,6}\b", LANES), [])
        for lane in ("vender", "gente", "cumplir", "crecer"):
            self.assertIn(f"--l-{lane}:", CSS)


if __name__ == "__main__":
    unittest.main()
