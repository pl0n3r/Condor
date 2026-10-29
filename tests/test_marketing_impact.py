#!/usr/bin/env python3
"""Impacto del home de Condor: hero navy a sangre, cinta de módulos y movimiento solo sin «reducir movimiento»."""

from __future__ import annotations

import re
import unittest
from pathlib import Path

ROOT = Path(__file__).resolve().parents[1]
HOME = (ROOT / "templates/home/index.html.twig").read_text(encoding="utf-8")
CSS = (ROOT / "public/marketing.css").read_text(encoding="utf-8")
BLOCK = CSS[CSS.index("/* Impacto: hero navy"):CSS.index("/* Impacto en las páginas interiores")]
TOP = CSS[CSS.index("/* Impacto en las páginas interiores"):]


def inside_no_preference(css: str, offset: int) -> bool:
    stack: list[bool] = []
    for index in range(offset):
        if css[index] == "{":
            header = css[css.rfind("}", 0, index) + 1:index].rsplit(";", 1)[-1]
            stack.append("prefers-reduced-motion: no-preference" in header)
        elif css[index] == "}" and stack:
            stack.pop()
    return any(stack)


class MarketingImpactTests(unittest.TestCase):
    def test_ticker_lists_real_modules_and_is_decorative(self) -> None:
        ticker = HOME[HOME.index('<div class="mk-ticker"'):]
        ticker = ticker[:ticker.index("</div>\n</div>") + 12]
        self.assertIn('aria-hidden="true"', ticker.split(">")[0] + ">")
        for module in ("Catálogo", "Inventario", "Pedidos", "Tienda online", "Producción", "Precios", "Sedes y roles", "Empresas"):
            with self.subTest(module=module):
                self.assertEqual(ticker.count(f"<span>{module}</span>"), 2, "Dos pasadas: el bucle no tiene salto")

    def test_every_animation_in_the_impact_block_runs_only_without_reduced_motion(self) -> None:
        rules = list(re.finditer(r"\banimation\s*:", BLOCK))
        self.assertGreaterEqual(len(rules), 1)
        for rule in rules:
            with self.subTest(offset=rule.start()):
                self.assertTrue(inside_no_preference(BLOCK, rule.start()))

    def test_hero_remaps_tokens_instead_of_hard_coding_component_colors(self) -> None:
        hero = BLOCK.split(".mk-hero.mk-landing {", 1)[1].split("}", 1)[0]
        for token in ("--mk-bg", "--mk-ink", "--mk-ink-2", "--mk-line", "--mk-mark"):
            with self.subTest(token=token):
                self.assertIn(f"{token}:", hero)
        rest = BLOCK.replace(hero, "")
        self.assertEqual(re.findall(r"#[0-9a-fA-F]{3,6}\b", rest), [], "Fuera del remapeo, solo tokens")

    def test_inner_page_headers_remap_the_same_tokens_and_keep_their_eyebrow_below_the_title(self) -> None:
        header = TOP.split(".mk-top {", 1)[1].split("}", 1)[0]
        for token in ("--mk-bg", "--mk-ink", "--mk-ink-2", "--mk-line", "--mk-mark"):
            with self.subTest(token=token):
                self.assertIn(f"{token}:", header)
        self.assertIn("flex-direction: column-reverse", TOP, "La etiqueta no va sobre el título")
        self.assertEqual(re.findall(r"#[0-9a-fA-F]{3,6}\b", TOP.replace(header, "")), [])

    def test_block_has_no_important_and_no_transition_all(self) -> None:
        self.assertNotIn("!important", BLOCK)
        self.assertNotRegex(BLOCK, r"transition:\s*all\b")


if __name__ == "__main__":
    unittest.main()
