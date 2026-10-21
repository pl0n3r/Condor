#!/usr/bin/env python3
"""Condor #791: guard de afirmaciones verificables en el sitio público SSR.

Solo inspección estática de Twig. Una allowlist de cifras de DEMOSTRACIÓN
no acredita clientes, resultados, certificaciones ni testimonios reales.
"""
from __future__ import annotations

import re
from html.parser import HTMLParser
import unittest
from collections import Counter
from pathlib import Path

ROOT = Path(__file__).resolve().parents[1]
PUBLIC = {
    "home/index.html.twig": ROOT / "templates/home/index.html.twig",
    "marketing/producto.html.twig": ROOT / "templates/marketing/producto.html.twig",
    "marketing/soluciones.html.twig": ROOT / "templates/marketing/soluciones.html.twig",
    "marketing/seguridad.html.twig": ROOT / "templates/marketing/seguridad.html.twig",
    "marketing/_nav.html.twig": ROOT / "templates/marketing/_nav.html.twig",
    "marketing/_cta.html.twig": ROOT / "templates/marketing/_cta.html.twig",
}

# Permisos explícitos y limitados por ruta, valor y número de apariciones:
# La maqueta de módulos en Producto muestra el EJEMPLO de una regla de
# descuento mayorista "−12 %" (3 apariciones). El producto soporta listas
# mayoristas/descuentos por cantidad; 12 % es un dato ilustrativo de UI,
# no rendimiento real, clientes, facturación ni promesa contractual.
# Las cifras de clientes, ventas, cuota de mercado o certificaciones nunca
# se permiten por esta lista. Si cambia el ejemplo, revisar conscientemente
# la excepción; nunca normalizar porcentajes de "éxito" como demostración.
ALLOWED_DEMO_PERCENTAGES = {
    "marketing/producto.html.twig": Counter({"12 %": 3}),
}

PERCENT = re.compile(r"\b\d+(?:[.,]\d+)?\s*%", re.IGNORECASE)
CUSTOMER_METRIC = re.compile(
    r"\b\d[\d.,]*\s*(?:clientes|usuarios|empresas|negocios|"
    r"suscriptores|ventas)\b", re.IGNORECASE
)
UNSUPPORTED = {
    "garantia_absoluta": re.compile(
        r"\bgarantizad[oa]s?\b|\bgarantizamos\b", re.IGNORECASE
    ),
    "superlativo": re.compile(r"\bel\s+mejor\b|\bla\s+mejor\b", re.IGNORECASE),
    "liderazgo": re.compile(
        r"\bn[uú]mero\s*(?:1|uno)\b|\bn[.º°]\s*1\b|\b#\s*1\b",
        re.IGNORECASE
    ),
    "testimonios": re.compile(
        r"\btestimoni(?:o|os)\b|\breseñas?\s+de\s+clientes\b",
        re.IGNORECASE
    ),
    "certificaciones": re.compile(
        r"\bcertificad[oa]s?\b|\bcertificaci[oó]n(?:es)?\b|"
        r"\bISO\s*27001\b|\bSOC\s*2\b|\bPCI[\s-]*DSS\b",
        re.IGNORECASE
    ),
}
CLIENT_LOGO = re.compile(
    r"\b(?:logo|logotipo)\s+(?:de\s+)?(?:un\s+)?(?:cliente|empresa|"
    r"marca\s+cliente)\b|\b(?:client[-_]?logo|customer[-_]?logo|"
    r"logos?/clientes)\b", re.IGNORECASE
)


def clean_source(source: str) -> str:
    """Quitar solo comentarios Twig; el parser procesa HTML real."""
    return re.sub(r"\{#.*?#\}", " ", source, flags=re.DOTALL)


class _VisibleText(HTMLParser):
    """Analizar etiquetas HTML con parser, nunca con expresiones regulares."""

    def __init__(self) -> None:
        super().__init__(convert_charrefs=True)
        self.hidden = 0
        self.parts: list[str] = []
        self.has_blockquote = False
        self.has_client_logo = False

    def handle_starttag(self, tag: str, attrs: list[tuple[str, str | None]]) -> None:
        if tag in {"script", "style"}:
            self.hidden += 1
        elif tag == "blockquote":
            self.has_blockquote = True
        elif tag in {"img", "svg"}:
            # HTMLParser maneja cierres espaciados, comillas y ">" en atributos.
            values = " ".join(value or "" for _, value in attrs)
            if CLIENT_LOGO.search(values):
                self.has_client_logo = True

    def handle_endtag(self, tag: str) -> None:
        if tag in {"script", "style"} and self.hidden:
            self.hidden -= 1

    def handle_data(self, data: str) -> None:
        if not self.hidden:
            self.parts.append(data)


def parse_markup(source: str) -> tuple[str, bool, bool]:
    parser = _VisibleText()
    parser.feed(source)
    return (
        " ".join(" ".join(parser.parts).split()),
        parser.has_blockquote,
        parser.has_client_logo,
    )


def scan_claims(page: str, source: str) -> tuple[list[str], Counter[str]]:
    clean = clean_source(source)
    text, has_blockquote, has_client_logo = parse_markup(clean)
    findings: list[str] = []
    examples: Counter[str] = Counter()
    approved = ALLOWED_DEMO_PERCENTAGES.get(page, Counter())
    for match in PERCENT.finditer(text):
        value = " ".join(match.group(0).split())
        examples[value] += 1
        if examples[value] > approved[value]:
            findings.append("porcentaje_no_sustentado:" + value)
    for match in CUSTOMER_METRIC.finditer(text):
        findings.append("metrica_de_clientes:" + match.group(0))
    for category, expression in UNSUPPORTED.items():
        for match in expression.finditer(text):
            findings.append(category + ":" + match.group(0))

    # En páginas comerciales, un testimonio debe tener procedencia aprobada;
    # no se acepta siquiera un bloque de cita identificado como cliente real.
    if has_blockquote:
        findings.append("testimonio_blockquote_sin_respaldo")
    if has_client_logo:
        findings.append("logotipo_cliente_sin_respaldo")
    return findings, examples


class AfirmacionesSitioPublicoTests(unittest.TestCase):
    def test_acceptance(self) -> None:
        self.assertEqual(6, len(PUBLIC), "Inventario público modificado sin revisar")
        for page, path in PUBLIC.items():
            with self.subTest(pagina=page):
                self.assertTrue(path.is_file(), f"Falta plantilla: {path}")
                problems, examples = scan_claims(
                    page, path.read_text(encoding="utf-8")
                )
                self.assertEqual(
                    [], problems,
                    "Afirmaciones no sustentadas; documentar o retirar: "
                    + "; ".join(problems),
                )
                self.assertEqual(
                    ALLOWED_DEMO_PERCENTAGES.get(page, Counter()),
                    examples,
                    "Allowlist obsoleta o nuevos porcentajes; revisión necesaria",
                )

    def test_rechaza_claims_no_verificados(self) -> None:
        fixtures = (
            "<p>100 % garantizado</p>",
            "<h1>El mejor ERP del mercado</h1>",
            "<p>Número 1 en ventas</p>",
            "<p>500 clientes satisfechos</p>",
            '<blockquote>“Lo recomiendo”, Cliente real</blockquote>',
            '<img src="/logos/clientes/acme.png" alt="Logo de cliente">',
            "<p>Certificación ISO 27001</p>",
        )
        for fixture in fixtures:
            with self.subTest(html=fixture):
                problems, _ = scan_claims("marketing/seguridad.html.twig", fixture)
                self.assertTrue(problems, f"No se rechazó: {fixture}")

    def test_no_marca_datos_de_productos_como_clientes(self) -> None:
        text = (
            "<p>Precios según cantidad: 10 unidades</p>"
            "<p>6 módulos activados</p><p>Un negocio con varias sedes</p>"
        )
        problems, examples = scan_claims("home/index.html.twig", text)
        self.assertEqual([], problems)
        self.assertEqual(Counter(), examples)


    def test_parser_html_detecta_variantes_y_evade_falsos_positivos(self) -> None:
        # Los cierres espaciados y atributos complejos no deben burlar el guard.
        fixtures = (
            '<IMG alt="Logo de cliente" src="/logos/clientes/acme.png" >',
            '<svg aria-label="Logo de cliente" />',
            '<script >var n = 12;</script   ><p>500 clientes</p>',
            '<style >.x:before{content:"100 %";}</style ><p>El mejor servicio</p>',
            '<blockquote class="comentario">Cliente satisfecho</blockquote>',
        )
        for fixture in fixtures:
            with self.subTest(html=fixture):
                findings, _ = scan_claims("home/index.html.twig", fixture)
                self.assertTrue(findings, f"No se detectó: {fixture}")
        invisible = (
            '<script >const claim = "500 clientes";</script >'
            '<style>.demo { content: "100 %"; }</style >'
            '<p>10 productos</p>'
        )
        findings, metrics = scan_claims("home/index.html.twig", invisible)
        self.assertEqual([], findings)
        self.assertEqual(Counter(), metrics)

if __name__ == "__main__":
    unittest.main()
