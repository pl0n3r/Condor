#!/usr/bin/env python3
"""AC-01 Condor #788: auditoría reproducible de accesibilidad del marketing.

Inspección estática de Twig y paletas CSS; no sustituye una auditoría de
navegador/lector de pantalla. Las excepciones son deuda medida y documentada
en Condor #788, no declaraciones de cumplimiento WCAG.
"""
from __future__ import annotations

import re
import unittest
from html.parser import HTMLParser
from pathlib import Path

ROOT = Path(__file__).resolve().parents[1]
BASE = ROOT / "templates/base.html.twig"
CSS = ROOT / "public/marketing.css"
PAGES = {
    "home": ROOT / "templates/home/index.html.twig",
    "producto": ROOT / "templates/marketing/producto.html.twig",
    "soluciones": ROOT / "templates/marketing/soluciones.html.twig",
    "seguridad": ROOT / "templates/marketing/seguridad.html.twig",
}

# Deuda real de código ya fusionado: https://github.com/pl0n3r/Condor/issues/788
# Una regresión NUEVA falla; un defecto corregido sale de esta lista de hallazgos
# automáticamente (la deuda anotada podrá retirarse en la reparación específica).
KNOWN_DEBT = {
    "heading:home:h1-to-h3",
    "contrast:light:chip-ok:mk-bg",
    "contrast:light:chip-ok:mk-surface",
    "contrast:light:chip-warn:mk-bg",
    "contrast:light:chip-warn:mk-surface",
    "contrast:light:tag:mk-bg",
    "contrast:light:tag-low:mk-bg",
}


class _Markup(HTMLParser):
    def __init__(self) -> None:
        super().__init__(convert_charrefs=True)
        self.headings: list[int] = []
        self.inputs: list[dict[str, str | None]] = []
        self.labels: set[str] = set()
        self.images: list[dict[str, str | None]] = []
        self.svgs: list[dict[str, str | None]] = []

    def handle_starttag(self, tag: str, attrs: list[tuple[str, str | None]]) -> None:
        attributes = dict(attrs)
        if re.fullmatch(r"h[1-6]", tag):
            self.headings.append(int(tag[1]))
        elif tag == "input" and attributes.get("type") in {"radio", "checkbox"}:
            self.inputs.append(attributes)
        elif tag == "label" and attributes.get("for"):
            self.labels.add(str(attributes["for"]))
        elif tag == "img":
            self.images.append(attributes)
        elif tag == "svg":
            self.svgs.append(attributes)


def _audit_markup(name: str, html: str, css: str) -> set[str]:
    doc = _Markup()
    doc.feed(html)
    problems: set[str] = set()
    if doc.headings.count(1) != 1:
        problems.add(f"heading:{name}:h1-count")
    for preceding, current in zip(doc.headings, doc.headings[1:]):
        if current > preceding + 1:
            problems.add(f"heading:{name}:h{preceding}-to-h{current}")

    seen: set[str] = set()
    for control in doc.inputs:
        control_id = str(control.get("id") or "")
        if not control_id or control_id in seen:
            problems.add(f"controls:{name}:id-missing-or-duplicated")
            continue
        seen.add(control_id)
        if control_id not in doc.labels:
            problems.add(f"controls:{name}:{control_id}:missing-label")
        # Los radios/checkboxes .mk-vh son transparentes: el foco debe
        # hacerse visible EN su label, no solo sobre el input oculto.
        selector = (
            rf"#{re.escape(control_id)}:focus-visible\s*~[^{{}}]*"
            rf'label\[for="{re.escape(control_id)}"\]\s*\{{\s*outline:\s*[1-9]'
        )
        if not re.search(selector, css, re.IGNORECASE):
            problems.add(f"focus:{name}:{control_id}:invisible")

    for image in doc.images:
        alt = image.get("alt")
        if alt is None or (not alt.strip() and image.get("aria-hidden") != "true"
                           and image.get("role") not in {"none", "presentation"}):
            problems.add(f"images:{name}:missing-informative-alt")
    for svg in doc.svgs:
        if svg.get("role") == "img" and not (
            svg.get("aria-label") or svg.get("aria-labelledby")
        ):
            problems.add(f"images:{name}:unnamed-svg")
    return problems


def _palette(css: str, dark: bool) -> dict[str, str]:
    light = re.search(r"(?m)^\.mk-site\s*\{([^}]*)\}", css, re.DOTALL)
    if light is None:
        raise AssertionError("Falta paleta principal .mk-site")
    values = dict(re.findall(r"--(mk-[\w-]+):\s*(#[a-fA-F0-9]{6})", light.group(1)))
    if dark:
        dark_rule = re.search(
            r"@media\s*\(prefers-color-scheme:\s*dark\)\s*\{\s*"
            r"\.mk-site\s*\{([^}]*)\}", css, re.DOTALL,
        )
        if dark_rule is None:
            raise AssertionError("Falta paleta de alto contraste oscuro")
        values.update(re.findall(
            r"--(mk-[\w-]+):\s*(#[a-fA-F0-9]{6})", dark_rule.group(1),
        ))
    return values


def _rgb(hex_color: str) -> tuple[float, float, float]:
    return tuple(int(hex_color[i:i + 2], 16) / 255 for i in (1, 3, 5))


def _luminance(rgb: tuple[float, float, float]) -> float:
    linear = [c / 12.92 if c <= 0.04045 else ((c + 0.055) / 1.055) ** 2.4
              for c in rgb]
    return 0.2126 * linear[0] + 0.7152 * linear[1] + 0.0722 * linear[2]


def _contrast(fg: tuple[float, float, float],
              bg: tuple[float, float, float]) -> float:
    first, second = sorted((_luminance(fg), _luminance(bg)), reverse=True)
    return (first + 0.05) / (second + 0.05)


def _blend(fg: tuple[float, float, float], bg: tuple[float, float, float],
           portion: float) -> tuple[float, float, float]:
    return tuple(portion * a + (1 - portion) * b for a, b in zip(fg, bg))


def _tint_fraction(css: str, selector: str, variable: str) -> float:
    expression = (
        rf"\.{re.escape(selector)}\s*\{{[^}}]*?"
        rf"background:\s*color-mix\(in srgb,\s*var\(--{variable}\)\s*"
        rf"(\d+)%,\s*transparent\)"
    )
    match = re.search(expression, css, re.DOTALL)
    if match is None:
        raise AssertionError(f"Sin mezcla verificable: {selector}/{variable}")
    return int(match.group(1)) / 100


def _audit_contrast(css: str) -> set[str]:
    issues: set[str] = set()
    for theme, dark in (("light", False), ("dark", True)):
        p = _palette(css, dark)
        # Pares de texto / fondo realmente definidos por el sitio.
        pairs = (
            ("mk-ink", "mk-bg"), ("mk-ink", "mk-surface"),
            ("mk-ink-2", "mk-bg"), ("mk-ink-2", "mk-surface"),
            ("mk-ink-2", "mk-soft"), ("mk-link", "mk-bg"),
            ("mk-ok", "mk-bg"), ("mk-warn", "mk-bg"),
            ("mk-bad", "mk-bg"), ("mk-mark-ink", "mk-mark"),
            ("mk-bg", "mk-ink"),
        )
        for foreground, background in pairs:
            if foreground not in p or background not in p:
                issues.add(f"contrast:{theme}:missing:{foreground}/{background}")
                continue
            if _contrast(_rgb(p[foreground]), _rgb(p[background])) < 4.5:
                issues.add(f"contrast:{theme}:{foreground}-on-{background}")

        # Un color-mix(..., transparent) se compone con el fondo: comparar el
        # texto con el color mezclado, no con el fondo base sin mezcla.
        for selector, foreground, contexts in (
            ("mk-chip-ok", "mk-ok", ("mk-bg", "mk-surface")),
            ("mk-chip-warn", "mk-warn", ("mk-bg", "mk-surface")),
            ("mk-tag", "mk-ok", ("mk-bg",)),
            ("mk-tag-low", "mk-bad", ("mk-bg",)),
        ):
            amount = _tint_fraction(css, selector, foreground)
            for background in contexts:
                text = _rgb(p[foreground])
                tinted = _blend(text, _rgb(p[background]), amount)
                if _contrast(text, tinted) < 4.5:
                    short_name = {
                        "mk-chip-ok": "chip-ok", "mk-chip-warn": "chip-warn",
                        "mk-tag": "tag", "mk-tag-low": "tag-low",
                    }[selector]
                    issues.add(f"contrast:{theme}:{short_name}:{background}")
    return issues


class AccesibilidadSitioPublicoTests(unittest.TestCase):
    def test_acceptance(self) -> None:
        self.assertTrue(BASE.is_file(), "No existe la plantilla HTML base")
        self.assertTrue(CSS.is_file(), "No existe marketing.css")
        css = CSS.read_text(encoding="utf-8")
        base = BASE.read_text(encoding="utf-8")
        self.assertRegex(base, r'<html\s+lang="es-CO"')
        self.assertRegex(
            css, r"\.mk-site\s+:focus-visible\s*\{\s*outline:\s*[1-9]",
        )
        findings = _audit_contrast(css)
        for name, path in PAGES.items():
            with self.subTest(page=name):
                self.assertTrue(path.is_file(), str(path))
                html = path.read_text(encoding="utf-8")
                self.assertIn("{% extends 'base.html.twig' %}", html)
                findings |= _audit_markup(name, html, css)
        unknown = findings - KNOWN_DEBT
        self.assertEqual(
            set(), unknown,
            "Hallazgos sin issue/exceptuación explícita (Condor #788): "
            + ", ".join(sorted(unknown)),
        )
        # El test no certifica que la deuda anterior sea conforme con WCAG.
        # El inventario de fallos conocidos queda ligado a #788 y a su PR.

    def test_detecta_salto_de_encabezados_y_control_sin_label(self) -> None:
        css = ".mk-site :focus-visible { outline: 3px solid blue; }"
        html = '<h1>Uno</h1><h3>Tres</h3><input type="radio" id="x">'
        result = _audit_markup("fixture", html, css)
        self.assertIn("heading:fixture:h1-to-h3", result)
        self.assertIn("controls:fixture:x:missing-label", result)
        self.assertIn("focus:fixture:x:invisible", result)

    def test_calculo_wcag_y_transparencia(self) -> None:
        self.assertAlmostEqual(21, _contrast(_rgb("#000000"), _rgb("#ffffff")))
        white, black = _rgb("#ffffff"), _rgb("#000000")
        self.assertLess(_contrast(white, _blend(black, white, .10)), 4.5)


if __name__ == "__main__":
    unittest.main()
