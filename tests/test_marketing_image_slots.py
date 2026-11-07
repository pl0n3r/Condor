#!/usr/bin/env python3
"""Contrato de imágenes locales reales en los dos héroes de marketing de Condor."""

from __future__ import annotations

import re
import unittest
from pathlib import Path

ROOT = Path(__file__).resolve().parents[1]
PAGES = {
    "templates/home/index.html.twig": "home-panel.webp",
    "templates/marketing/empezar.html.twig": "empezar-negocio.webp",
}
CSS = (ROOT / "public" / "marketing.css").read_text(encoding="utf-8")
MAX_IMAGE_BYTES = 180 * 1024


def webp_dimensions(data: bytes) -> tuple[int, int]:
    """Lee dimensiones de un WebP sin dependencias de imagen en CI."""
    if len(data) < 30 or data[:4] != b"RIFF" or data[8:12] != b"WEBP":
        raise ValueError("La imagen no es un contenedor WebP")
    if int.from_bytes(data[4:8], "little") + 8 != len(data):
        raise ValueError("Tamaño RIFF inválido")
    chunk = data[12:16]
    if chunk == b"VP8 ":
        if data[23:26] != bytes((0x9D, 0x01, 0x2A)):
            raise ValueError("Frame VP8 inválido")
        return (
            int.from_bytes(data[26:28], "little") & 0x3FFF,
            int.from_bytes(data[28:30], "little") & 0x3FFF,
        )
    if chunk == b"VP8X":
        return (
            int.from_bytes(data[24:27], "little") + 1,
            int.from_bytes(data[27:30], "little") + 1,
        )
    if chunk == b"VP8L" and data[20] == 0x2F:
        b1, b2, b3, b4 = data[21:25]
        return (
            1 + (((b2 & 0x3F) << 8) | b1),
            1 + (((b4 & 0x0F) << 10) | (b3 << 2) | (b2 >> 6)),
        )
    raise ValueError("Formato de frame WebP no reconocido")


class MarketingImageSlotsTests(unittest.TestCase):
    def test_slots_hold_real_images_with_fixed_ratio_and_alt(self) -> None:
        for page, name in PAGES.items():
            with self.subTest(page=page):
                html = (ROOT / page).read_text(encoding="utf-8")
                self.assertEqual(html.count('<figure class="mk-img">'), 1)
                match = re.search(r'<figure class="mk-img">([\s\S]*?)</figure>', html)
                self.assertIsNotNone(match)
                figure = match.group(1)
                self.assertIn('class="mk-img-frame"', figure)
                self.assertNotIn('aria-hidden="true"', figure)
                images = re.findall(r'<img\b[^>]*>', figure)
                self.assertEqual(len(images), 1)
                image = images[0]
                self.assertIn("asset('img/marketing/" + name + "')", image)
                self.assertIn('width="1200"', image)
                self.assertIn('height="900"', image)
                self.assertRegex(image, r'\balt="[^"]+"')
                self.assertNotIn('loading="lazy"', image)  # Ambos son héroes.
                self.assertNotRegex(image, r'https?://|data:|src="//')
                path = ROOT / "public" / "img" / "marketing" / name
                self.assertTrue(path.is_file(), f"Falta recurso local: {name}")
                data = path.read_bytes()
                self.assertLessEqual(len(data), MAX_IMAGE_BYTES)
                self.assertEqual(webp_dimensions(data), (1200, 900))

    def test_no_placeholder_labels_on_marketing_pages(self) -> None:
        for page in PAGES:
            with self.subTest(page=page):
                html = (ROOT / page).read_text(encoding="utf-8")
                self.assertNotIn("Espacio para imagen", html)
                self.assertNotIn("<span>Imagen</span>", html)

    def test_frame_keeps_palette_and_fixed_ratio(self) -> None:
        frame = CSS.split(".mk-img-frame {", 1)[1].split("}", 1)[0]
        self.assertIn("aspect-ratio: 4 / 3;", frame)
        self.assertIn("var(--mk-surface)", frame)
        self.assertNotRegex(frame, r"#[0-9a-fA-F]{3,8}\b")
        self.assertIn(".mk-img-frame img", CSS)
        self.assertIn("object-fit: cover;", CSS)

    def test_hero_illustrations_are_distinct(self) -> None:
        paths = [ROOT / "public" / "img" / "marketing" / name for name in PAGES.values()]
        self.assertEqual(len(paths), 2)
        self.assertNotEqual(paths[0].read_bytes(), paths[1].read_bytes())

    def test_invalid_webp_payload_fails_closed(self) -> None:
        with self.assertRaises(ValueError):
            webp_dimensions(b"not a webp")
        with self.assertRaises(ValueError):
            webp_dimensions(b"RIFF" + (50).to_bytes(4, "little") + b"WEBP" + bytes(24))


if __name__ == "__main__":
    unittest.main()
