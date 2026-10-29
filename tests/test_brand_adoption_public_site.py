#!/usr/bin/env python3
"""El sitio público usa los tokens de la propuesta de marca aprobada por el owner."""

from __future__ import annotations

import json
import re
import unittest
from pathlib import Path

ROOT = Path(__file__).resolve().parents[1]
CSS = (ROOT / "public" / "marketing.css").read_text(encoding="utf-8")
PALETTE = json.loads(
    (ROOT / "docs" / "brand" / "condor-brand-proposal.json").read_text(encoding="utf-8")
)["palette"]


def _light_tokens() -> dict[str, str]:
    block = re.search(r"\.mk-site\s*\{(?P<body>.*?)\n\s*background:", CSS, re.DOTALL)
    assert block is not None, "No se encontró el bloque de tokens claros"
    return dict(re.findall(r"(--mk-[a-z0-9-]+):\s*(#[0-9a-fA-F]{6})", block.group("body")))


def _dark_tokens() -> dict[str, str]:
    block = re.search(
        r"@media \(prefers-color-scheme: dark\)\s*\{\s*\.mk-site\s*\{(?P<body>.*?)\}",
        CSS, re.DOTALL,
    )
    assert block is not None, "No se encontró el bloque de tokens oscuros"
    return dict(re.findall(r"(--mk-[a-z0-9-]+):\s*(#[0-9a-fA-F]{6})", block.group("body")))


class BrandAdoptionPublicSiteTests(unittest.TestCase):
    def test_light_theme_uses_flightline_palette(self) -> None:
        tokens = _light_tokens()
        expected = {
            "--mk-bg": "condor_cloud",
            "--mk-surface": "condor_paper",
            "--mk-ink": "condor_night",
            "--mk-ink-2": "condor_slate",
            "--mk-mark": "condor_cobalt",
            "--mk-link": "condor_cobalt",
            "--mk-ok": "semantic_success",
            "--mk-bad": "semantic_danger",
        }
        for token, color in expected.items():
            with self.subTest(token=token):
                self.assertEqual(tokens[token].lower(), PALETTE[color]["hex"].lower())

    def test_dark_theme_is_built_on_condor_night(self) -> None:
        self.assertEqual(_dark_tokens()["--mk-bg"].lower(), PALETTE["condor_night"]["hex"].lower())
        self.assertIn(
            f"background: {PALETTE['condor_night']['hex'].lower()}", CSS.lower(),
        )

    def test_previous_yellow_accent_is_gone(self) -> None:
        self.assertNotIn("#ffc400", CSS.lower())

    def test_status_colors_keep_their_functional_meaning(self) -> None:
        # success/danger son semánticos y no se reutilizan como color de marca.
        tokens = _light_tokens()
        self.assertNotEqual(tokens["--mk-mark"].lower(), tokens["--mk-ok"].lower())
        self.assertNotEqual(tokens["--mk-mark"].lower(), tokens["--mk-bad"].lower())


if __name__ == "__main__":
    unittest.main()
