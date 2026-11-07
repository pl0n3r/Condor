"""Accesibilidad de teclado de las regiones desplazables de /producto.

El contrato valida la semántica en el Twig real y detecta regresiones que
eliminan foco, nombre accesible o vuelven a role="presentation".
"""
from __future__ import annotations

from html.parser import HTMLParser
from pathlib import Path
import unittest


TEMPLATE = (
    Path(__file__).resolve().parents[1]
    / "templates"
    / "marketing"
    / "producto.html.twig"
)


class _ProductRegions(HTMLParser):
    """Localiza regiones por clases e id del ancestro, no por texto accidental."""

    VOID_TAGS = {
        "area", "base", "br", "col", "embed", "hr", "img",
        "input", "link", "meta", "param", "source", "track", "wbr",
    }

    def __init__(self) -> None:
        super().__init__(convert_charrefs=True)
        self.ancestors: list[tuple[str, dict[str, str | None]]] = []
        self.tablist: list[dict[str, str | None]] = []
        self.catalog_bodies: list[dict[str, str | None]] = []

    def handle_starttag(self, tag: str, attrs: list[tuple[str, str | None]]) -> None:
        values = dict(attrs)
        classes = (values.get("class") or "").split()
        if tag == "div" and "mk-tablist" in classes:
            self.tablist.append(values)
        if (
            tag == "div"
            and "mk-bd" in classes
            and any(
                ancestor_tag == "article" and ancestor_attrs.get("id") == "modulo-catalogo"
                for ancestor_tag, ancestor_attrs in self.ancestors
            )
        ):
            self.catalog_bodies.append(values)
        if tag not in self.VOID_TAGS:
            self.ancestors.append((tag, values))

    def handle_endtag(self, tag: str) -> None:
        for index in range(len(self.ancestors) - 1, -1, -1):
            if self.ancestors[index][0] == tag:
                del self.ancestors[index:]
                break


class MarketingProductScrollA11yTests(unittest.TestCase):
    @staticmethod
    def _regions(source: str) -> _ProductRegions:
        parser = _ProductRegions()
        parser.feed(source)
        parser.close()
        return parser

    def _assert_accessible_regions(self, source: str) -> None:
        regions = self._regions(source)
        self.assertEqual(len(regions.tablist), 1, "La lista de módulos debe ser única")
        self.assertEqual(len(regions.catalog_bodies), 1, "La tabla desplazable del catálogo debe ser única")

        group = regions.tablist[0]
        self.assertEqual(group.get("role"), "group")
        self.assertEqual(group.get("aria-label"), "Opciones de módulos")
        self.assertEqual(group.get("tabindex"), "0")
        self.assertNotEqual(group.get("role"), "presentation")

        catalog = regions.catalog_bodies[0]
        self.assertEqual(catalog.get("role"), "region")
        self.assertEqual(catalog.get("aria-label"), "Tabla de variantes y precios desplazable")
        self.assertEqual(catalog.get("tabindex"), "0")
        self.assertNotEqual(catalog.get("role"), "presentation")

    def test_scrollable_regions_named_and_focusable(self) -> None:
        """Ambos contenedores son enfocables con rol y nombre accesible."""
        source = TEMPLATE.read_text(encoding="utf-8")
        self._assert_accessible_regions(source)
        self.assertIn('for="mx1"', source, "Conservar el enlace radio/label original")
        self.assertIn('id="modulo-catalogo"', source)

    def test_no_presentation_role_on_focusable_module_group(self) -> None:
        """Mutaciones de rol, foco o nombre deben romper las aserciones."""
        source = TEMPLATE.read_text(encoding="utf-8")
        self._assert_accessible_regions(source)
        mutations = (
            ('role="group" aria-label="Opciones de módulos"',
             'role="presentation" aria-label="Opciones de módulos"'),
            ('aria-label="Opciones de módulos"', 'aria-label=""'),
            ('aria-label="Opciones de módulos" tabindex="0"',
             'aria-label="Opciones de módulos" tabindex="-1"'),
            ('role="region" aria-label="Tabla de variantes y precios desplazable"',
             'role="presentation" aria-label="Tabla de variantes y precios desplazable"'),
            ('aria-label="Tabla de variantes y precios desplazable"', 'aria-label=""'),
            ('aria-label="Tabla de variantes y precios desplazable" tabindex="0"',
             'aria-label="Tabla de variantes y precios desplazable" tabindex="-1"'),
        )
        for original, replacement in mutations:
            with self.subTest(mutacion=original):
                self.assertIn(original, source)
                with self.assertRaises(AssertionError):
                    self._assert_accessible_regions(source.replace(original, replacement, 1))


if __name__ == "__main__":
    unittest.main()
