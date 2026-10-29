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
    "modulos": ROOT / "templates/marketing/modulos.html.twig",
    "empezar": ROOT / "templates/marketing/empezar.html.twig",
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
    "contrast:light:tag:mk-bg",
    "contrast:light:tag-low:mk-bg",
}


class _Node:
    """Árbol HTML mínimo para comprobar relaciones reales de selectores CSS."""

    def __init__(self, tag: str, attrs: dict[str, str | None],
                 parent: _Node | None = None) -> None:
        self.tag = tag
        self.attrs = attrs
        self.parent = parent
        self.children: list[_Node] = []
        self.text: list[str] = []


def _descendants(node: _Node) -> list[_Node]:
    result: list[_Node] = []
    for child in node.children:
        result.append(child)
        result.extend(_descendants(child))
    return result


def _text_content(node: _Node) -> str:
    return "".join(node.text) + "".join(_text_content(c) for c in node.children)


def _has_class(node: _Node, cls: str) -> bool:
    return cls in (node.attrs.get("class") or "").split()


class _Markup(HTMLParser):
    VOID = {"area", "base", "br", "col", "embed", "hr", "img", "input",
            "link", "meta", "param", "source", "track", "wbr"}

    def __init__(self) -> None:
        super().__init__(convert_charrefs=True)
        self.headings: list[int] = []
        self.inputs: list[_Node] = []
        self.labels: dict[str, list[_Node]] = {}
        self.images: list[dict[str, str | None]] = []
        self.svgs: list[dict[str, str | None]] = []
        self.root = _Node("root", {})
        self.stack = [self.root]

    def handle_starttag(self, tag: str, attrs: list[tuple[str, str | None]]) -> None:
        attributes = dict(attrs)
        node = _Node(tag, attributes, self.stack[-1])
        self.stack[-1].children.append(node)
        if re.fullmatch(r"h[1-6]", tag):
            self.headings.append(int(tag[1]))
        elif tag == "input" and attributes.get("type") in {"radio", "checkbox"}:
            self.inputs.append(node)
        elif tag == "label" and attributes.get("for"):
            self.labels.setdefault(str(attributes["for"]), []).append(node)
        elif tag == "img":
            self.images.append(attributes)
        elif tag == "svg":
            self.svgs.append(attributes)
        if tag not in self.VOID:
            self.stack.append(node)

    def handle_startendtag(self, tag: str,
                           attrs: list[tuple[str, str | None]]) -> None:
        self.handle_starttag(tag, attrs)
        if tag not in self.VOID:
            self.handle_endtag(tag)

    def handle_endtag(self, tag: str) -> None:
        for index in range(len(self.stack) - 1, 0, -1):
            if self.stack[index].tag == tag:
                del self.stack[index:]
                break

    def handle_data(self, data: str) -> None:
        self.stack[-1].text.append(data)


def _focus_reaches_label(control: _Node, control_id: str, target: str) -> bool:
    """Comprueba combinadores + y ~ contra los hermanos reales del input."""
    parent = control.parent
    if parent is None:
        return False
    siblings = parent.children
    later = siblings[siblings.index(control) + 1:]
    target = target.strip()
    if re.fullmatch(r"\+\s*label", target):
        return bool(later and later[0].tag == "label"
                    and later[0].attrs.get("for") == control_id)

    # Selectores reales: ~ .mk-qlist label[for="id"] y
    # ~ .mk-sw label[for="id"] .mk-tg (outline en el conmutador).
    relation = re.fullmatch(
        r'~\s*((?:\.[\w-]+\s+){1,3})label\[for="([^"]+)"\](\s+\.mk-tg)?',
        target,
    )
    if relation is None or relation.group(2) != control_id:
        return False
    ancestor_classes = re.findall(r"\.([\w-]+)", relation.group(1))
    toggle = relation.group(3)
    for sibling in later:
        if not _has_class(sibling, ancestor_classes[0]):
            continue
        containers = [sibling]
        # Una ruta ".mk-section .mk-tablist" exige anidación real,
        # nunca solo que los nombres estén presentes en el CSS.
        for cls in ancestor_classes[1:]:
            containers = [child for container in containers
                          for child in _descendants(container)
                          if _has_class(child, cls)]
        for container in containers:
            for label in _descendants(container):
                if label.tag != "label" or label.attrs.get("for") != control_id:
                    continue
                if not toggle or any(_has_class(n, "mk-tg")
                                     for n in _descendants(label)):
                    return True
    return False

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
        attrs = control.attrs
        control_id = str(attrs.get("id") or "")
        if not control_id or control_id in seen:
            problems.add(f"controls:{name}:id-missing-or-duplicated")
            continue
        seen.add(control_id)
        labels = doc.labels.get(control_id, [])
        if not labels:
            problems.add(f"controls:{name}:{control_id}:missing-label")
        # for=id solo asocia una etiqueta, pero esta también debe nombrar
        # al control, salvo nombre accesible alternativo explícito.
        other_name = bool((attrs.get("aria-label") or "").strip()
                          or (attrs.get("title") or "").strip())
        ids = (attrs.get("aria-labelledby") or "").split()
        referenced = [
            node for node in _descendants(doc.root)
            if node.attrs.get("id") in ids and _text_content(node).strip()
        ]
        if not (other_name or referenced or any(
                _text_content(label).strip()
                or (label.attrs.get("aria-label") or "").strip()
                for label in labels)):
            problems.add(f"controls:{name}:{control_id}:missing-accessible-name")

        # El foco en .mk-vh ha de pintar un label realmente alcanzable en DOM.
        # Limitar la expresión evita patrones CSS dinámicos arbitrarios.
        focus_rules = re.finditer(
            rf"#{re.escape(control_id)}:focus-visible\s*"
            r"(?P<target>[^{}]{0,160})\{\s*outline:\s*[1-9]",
            css, re.IGNORECASE,
        )
        if not any(_focus_reaches_label(control, control_id,
                                        match.group("target"))
                   for match in focus_rules):
            problems.add(f"focus:{name}:{control_id}:invisible")

    for image in doc.images:
        # alt="" es la anotación semántica válida de una imagen decorativa.
        # En imágenes informativas se exige un alt no vacío.
        if image.get("alt") is None:
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

    def test_reconoce_foco_en_label_y_conmutador(self) -> None:
        css = "\n".join((
            '#s1:focus-visible ~ .mk-seg label[for="s1"] { outline: 3px solid blue; }',
            '#m1:focus-visible ~ .mk-sw label[for="m1"] .mk-tg { outline: 3px solid blue; }',
            '.mk-qs #q1:focus-visible + label { outline: 3px solid blue; }',
        ))
        html = (
            '<h1>Prueba</h1>'
            '<div><input type="radio" id="s1"><div class="mk-seg">'
            '<label for="s1">Uno</label></div></div>'
            '<div><input type="checkbox" id="m1"><ul class="mk-sw">'
            '<li><label for="m1"><span class="mk-tg"></span>'
            '<span>Dos</span></label></li></ul></div>'
            '<div><input type="radio" id="q1"><label for="q1">Tres</label></div>'
        )
        self.assertEqual(set(), _audit_markup("fixture", html, css))

    def test_rechaza_etiqueta_vacia_sin_nombre_accesible(self) -> None:
        css = '#x:focus-visible + label { outline: 3px solid blue; }'
        html = '<h1>Prueba</h1><input type="radio" id="x"><label for="x"></label>'
        self.assertIn(
            "controls:fixture:x:missing-accessible-name",
            _audit_markup("fixture", html, css),
        )
        named = html.replace('id="x"', 'id="x" aria-label="Opción uno"')
        self.assertEqual(set(), _audit_markup("fixture", named, css))

    def test_rechaza_selector_adyacente_sin_hermano_label(self) -> None:
        css = '#x:focus-visible + label { outline: 3px solid blue; }'
        html = ('<h1>Prueba</h1><input type="radio" id="x">'
                '<span>Intercalado</span><label for="x">Opción</label>')
        self.assertIn(
            "focus:fixture:x:invisible",
            _audit_markup("fixture", html, css),
        )
        self.assertNotIn(
            "controls:fixture:x:missing-label",
            _audit_markup("fixture", html, css),
        )

    def test_rechaza_contenedor_equivocado_para_foco(self) -> None:
        css = '#x:focus-visible ~ .mk-sw label[for="x"] { outline: 3px solid blue; }'
        html = ('<h1>Prueba</h1><div><input type="checkbox" id="x">'
                '<div class="otro"><label for="x">Opción</label></div></div>')
        self.assertIn(
            "focus:fixture:x:invisible",
            _audit_markup("fixture", html, css),
        )

    def test_foco_de_producto_exige_ambos_contenedores(self) -> None:
        css = ('#x:focus-visible ~ .mk-section .mk-tablist '
               'label[for="x"] { outline: 3px solid blue; }')
        html = ('<h1>Prueba</h1><div><input type="radio" id="x">'
                '<section class="mk-section"><div class="mk-tablist">'
                '<label for="x">Módulo</label></div></section></div>')
        self.assertEqual(set(), _audit_markup("fixture", html, css))
        broken = html.replace('class="mk-tablist"', 'class="otra"')
        self.assertIn(
            "focus:fixture:x:invisible",
            _audit_markup("fixture", broken, css),
        )

    def test_acepta_alt_decorativo_y_detecta_alt_ausente(self) -> None:
        decorative = '<h1>Prueba</h1><img src="icono.svg" alt="">'
        self.assertEqual(set(), _audit_markup("fixture", decorative, ""))
        lacking = '<h1>Prueba</h1><img src="noticia.png">'
        self.assertIn(
            "images:fixture:missing-informative-alt",
            _audit_markup("fixture", lacking, ""),
        )

    def test_calculo_wcag_y_transparencia(self) -> None:
        self.assertAlmostEqual(21, _contrast(_rgb("#000000"), _rgb("#ffffff")))
        white, black = _rgb("#ffffff"), _rgb("#000000")
        self.assertLess(_contrast(white, _blend(black, white, .10)), 4.5)


if __name__ == "__main__":
    unittest.main()
