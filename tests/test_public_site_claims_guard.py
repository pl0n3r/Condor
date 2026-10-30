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
from tempfile import TemporaryDirectory

ROOT = Path(__file__).resolve().parents[1]
def discover_public_templates(root: Path) -> dict[str, Path]:
    """Incluir automáticamente nuevos Twig de home y marketing, incluso subcarpetas."""
    template_root = root / "templates"
    return {
        path.relative_to(template_root).as_posix(): path
        for area in ("home", "marketing")
        for path in sorted((template_root / area).rglob("*.html.twig"))
    }


PUBLIC = discover_public_templates(ROOT)

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

# Solo dos contextos ilustrativos aprobados: la regla 10+ unidades y
# las dos filas de ejemplo "Mayorista −12 % desde 10 und".
DEMO_RULE = re.compile(r"Regla\s*·\s*Mayorista\s*·\s*10 o m[aá]s unidades\s*[−-]?\s*$", re.I)
DEMO_ROW = re.compile(r"Mayorista\s*[−-]?\s*$", re.I)
DEMO_ROW_SUFFIX = re.compile(r"\s+desde\s+10\s+und\b", re.I)

# Recursos de imagen/SVG autorizados expresamente en estas páginas.
# Cualquier SVG nuevo falla cerrado excepto el decorativo exacto del hero
# dentro de un marco oculto; logos de clientes y recursos siguen prohibidos.
APPROVED_INLINE_SVGS = {
    "home/index.html.twig": {
        ("0 0 520 200", "Una tienda que se ordena"),
        ("0 0 520 200", "Un taller que produce y vende"),
        ("0 0 520 200", "Una distribuidora con varias sedes"),
        ("0 0 520 200", "Un grupo de empresas, una cuenta"),
    },
    "marketing/producto.html.twig": {("0 0 760 330", "")},
}

PERCENT = re.compile(r"\b\d+(?:[.,]\d+)?\s*%", re.IGNORECASE)
CUSTOMER_METRIC = re.compile(
    r"\b(?:\d[\d.,]*\s*(?:clientes|usuarios|empresas|negocios|"
    r"suscriptores|ventas)|"
    r"(?:clientes|usuarios|empresas|negocios|suscriptores|ventas)"
    r"(?:\s+(?:activos?|registrad[oa]s?|satisfech[oa]s?|totales?)){0,2}"
    r"\s*[:—-]?\s+\d[\d.,]*)\b", re.IGNORECASE
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
    """Analizar HTML sin regex de etiquetas ni depender de nombres de marca."""

    def __init__(self, page: str) -> None:
        super().__init__(convert_charrefs=True)
        self.page = page
        self.hidden = 0
        self.parts: list[str] = []
        self.has_blockquote = False
        self.has_client_logo = False
        self._decorative_frames: list[bool] = []
        self._in_hero_lanes = False
        self._hero_svg_depth = 0

    def handle_starttag(self, tag: str, attrs: list[tuple[str, str | None]]) -> None:
        attributes = dict(attrs)
        # Vigilar solo anidación de divs: etiquetas vacías no alteran el contexto.
        if tag == "div":
            hidden_frame = (
                attributes.get("class") == "mk-img-frame"
                and attributes.get("aria-hidden") == "true"
            )
            self._decorative_frames.append(
                hidden_frame
                or bool(self._decorative_frames and self._decorative_frames[-1])
            )
        if self._in_hero_lanes and (
            tag in {"use", "foreignobject", "script", "a"}
            or any(a in attributes for a in ("src", "href", "xlink:href", "style"))
        ):
            self.has_client_logo = True
        # No unir etiquetas de bloques separados ("Empresas" / "10:00")
        # como si formaran una sola afirmación cuantificada.
        if tag in {"li", "p", "tr", "td", "th", "h1", "h2", "h3", "h4", "article", "section"}:
            self.parts.append(" | ")
        if tag in {"script", "style"}:
            self.hidden += 1
        elif tag == "blockquote":
            self.has_blockquote = True
        elif tag == "meta" and not self.hidden:
            # Descripciones públicas también son claims (buscadores y redes).
            name = (attributes.get("name") or attributes.get("property") or "").lower()
            if name in {"description", "twitter:description", "og:description",
                        "og:title", "twitter:title"}:
                self.parts.append(attributes.get("content") or "")
        elif tag in {"img", "image"}:
            # No se ha aprobado ningún recurso de cliente; registrar cualquier
            # nueva imagen para aprobación explícita, sin heurísticas de filename.
            self.has_client_logo = True
        elif tag == "svg":
            if self._hero_svg_depth:
                # Los SVG anidados no forman parte del esquema aprobado.
                # Conservar el estado exterior para vigilar los nodos siguientes.
                self.has_client_logo = True
                self._hero_svg_depth += 1
            else:
                identity = (attributes.get("viewbox") or "", attributes.get("aria-label") or "")
                has_url = any(a in attributes for a in ("src", "href", "xlink:href", "style"))
                approved_hero = (
                    self.page == "home/index.html.twig"
                    and attributes.get("class") == "mk-lanes"
                    and attributes.get("viewbox") == "0 0 520 380"
                    and attributes.get("focusable") == "false"
                    and not attributes.get("aria-label")
                    and bool(self._decorative_frames)
                    and self._decorative_frames[-1]
                    and not has_url
                )
                if approved_hero:
                    self._hero_svg_depth = 1
                elif identity not in APPROVED_INLINE_SVGS.get(self.page, set()) or has_url:
                    self.has_client_logo = True
            self._in_hero_lanes = self._hero_svg_depth > 0

    def handle_startendtag(self, tag: str, attrs: list[tuple[str, str | None]]) -> None:
        self.handle_starttag(tag, attrs)
        if tag in {"svg", "div"}:
            # HTMLParser no invoca handle_endtag para etiquetas autocontenidas.
            self.handle_endtag(tag)

    def handle_endtag(self, tag: str) -> None:
        if tag == "svg" and self._hero_svg_depth:
            self._hero_svg_depth -= 1
            self._in_hero_lanes = self._hero_svg_depth > 0
        if tag == "div" and self._decorative_frames:
            self._decorative_frames.pop()
        if tag in {"li", "p", "tr", "td", "th", "h1", "h2", "h3", "h4", "article", "section"}:
            self.parts.append(" | ")
        if tag in {"script", "style"} and self.hidden:
            self.hidden -= 1

    def handle_data(self, data: str) -> None:
        if not self.hidden:
            self.parts.append(data)


def parse_markup(source: str, page: str) -> tuple[str, bool, bool]:
    parser = _VisibleText(page)
    parser.feed(source)
    return (
        " ".join(" ".join(parser.parts).split()),
        parser.has_blockquote,
        parser.has_client_logo,
    )


def scan_claims(page: str, source: str) -> tuple[list[str], Counter[str]]:
    clean = clean_source(source)
    text, has_blockquote, has_client_logo = parse_markup(clean, page)
    findings: list[str] = []
    examples: Counter[str] = Counter()
    approved = ALLOWED_DEMO_PERCENTAGES.get(page, Counter())
    for match in PERCENT.finditer(text):
        value = " ".join(match.group(0).split())
        examples[value] += 1
        prefix = text[max(0, match.start() - 110):match.start()]
        suffix = text[match.end():match.end() + 60]
        is_demo = (
            page == "marketing/producto.html.twig"
            and value == "12 %"
            and (bool(DEMO_RULE.search(prefix))
                 or (bool(DEMO_ROW.search(prefix))
                     and bool(DEMO_ROW_SUFFIX.match(suffix))))
        )
        if not is_demo or examples[value] > approved[value]:
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
    if has_client_logo or CLIENT_LOGO.search(text):
        findings.append("logotipo_cliente_sin_respaldo")
    return findings, examples


class AfirmacionesSitioPublicoTests(unittest.TestCase):
    def test_acceptance(self) -> None:
        self.assertTrue(PUBLIC, "No se encontraron plantillas públicas")
        self.assertIn("home/index.html.twig", PUBLIC)
        self.assertIn("marketing/producto.html.twig", PUBLIC)
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
            ("<p>100 % garantizado</p>", "garantia_absoluta"),
            ("<h1>El mejor ERP del mercado</h1>", "superlativo"),
            ("<p>Número 1 en ventas</p>", "liderazgo"),
            ("<p>500 clientes satisfechos</p>", "metrica_de_clientes"),
            ("<p>Clientes activos: 500</p>", "metrica_de_clientes"),
            ("<p>Usuarios registrados 1.200</p>", "metrica_de_clientes"),
            ('<blockquote>“Lo recomiendo”, Cliente real</blockquote>',
             "testimonio_blockquote_sin_respaldo"),
            ('<img src="/logos/clientes/acme.png" alt="Logo de cliente">',
             "logotipo_cliente_sin_respaldo"),
            ('<img src="/assets/acme-logo.svg" alt="Acme">',
             "logotipo_cliente_sin_respaldo"),
            ("<p>Certificación ISO 27001</p>", "certificaciones"),
            ('<meta name="description" content="Garantizamos resultados">',
             "garantia_absoluta"),
        )
        for fixture, expected_category in fixtures:
            with self.subTest(html=fixture):
                problems, _ = scan_claims("marketing/seguridad.html.twig", fixture)
                self.assertTrue(
                    any(problem.startswith(expected_category + ":")
                        or problem == expected_category
                        for problem in problems),
                    f"Categoría {expected_category} no detectada: {problems}",
                )

    def test_nueva_plantilla_se_descubre_y_rechaza_claims(self) -> None:
        with TemporaryDirectory() as directory:
            root = Path(directory)
            page = root / "templates/marketing/nueva/oferta.html.twig"
            page.parent.mkdir(parents=True)
            page.write_text("<h1>El mejor ERP</h1>", encoding="utf-8")
            discovered = discover_public_templates(root)
            self.assertIn("marketing/nueva/oferta.html.twig", discovered)
            findings, _ = scan_claims(
                "marketing/nueva/oferta.html.twig", page.read_text(encoding="utf-8")
            )
            self.assertTrue(any(f.startswith("superlativo:") for f in findings))

    def test_bloques_separados_no_forman_cifras_falsas(self) -> None:
        fixture = (
            "<ol><li><small>Empresas</small></li>"
            "<li><time>10:00</time><p>Ejemplo de horario</p></li></ol>"
        )
        findings, counts = scan_claims("marketing/soluciones.html.twig", fixture)
        self.assertEqual([], findings)
        self.assertEqual(Counter(), counts)

    def test_porcentaje_demo_solo_en_contexto_autorizado(self) -> None:
        # Misma página, mismo porcentaje, mismas tres apariciones:
        # no bastan para transformar publicidad de ventas en demo aprobada.
        claim = "<p>12 % de aumento en ventas</p>" * 3
        findings, examples = scan_claims("marketing/producto.html.twig", claim)
        self.assertEqual(Counter({"12 %": 3}), examples)
        self.assertEqual(3, sum(f.startswith("porcentaje_no_sustentado:")
                                for f in findings))

    def test_no_marca_datos_de_productos_como_clientes(self) -> None:
        text = (
            "<p>Precios según cantidad: 10 unidades</p>"
            "<p>6 módulos activados</p><p>Un negocio con varias sedes</p>"
        )
        problems, examples = scan_claims("home/index.html.twig", text)
        self.assertEqual([], problems)
        self.assertEqual(Counter(), examples)


    def test_svg_hero_decorativo_estrictamente_acotado(self) -> None:
        svg = (
            '<svg class="mk-lanes" viewBox="0 0 520 380" focusable="false">'
            '<path d="M44 70H490"/></svg>'
        )
        valid = '<div class="mk-img-frame" aria-hidden="true">' + svg + '</div>'
        findings, _ = scan_claims("home/index.html.twig", valid)
        self.assertEqual([], findings)

        # Una etiqueta SVG autocontenida termina su contexto: el enlace
        # ordinario que sigue al SVG no es un logotipo de cliente.
        self_closing = (
            '<div class="mk-img-frame" aria-hidden="true">'
            '<svg class="mk-lanes" viewBox="0 0 520 380" focusable="false"/>'
            '<a href="/contacto">Contacto</a></div>'
        )
        findings, _ = scan_claims("home/index.html.twig", self_closing)
        self.assertEqual([], findings)

        forbidden = (
            ("fuera de marco", svg, "home/index.html.twig"),
            ("marco visible", valid.replace('aria-hidden="true"', 'aria-hidden="false"'), "home/index.html.twig"),
            ("marco falso", valid.replace('mk-img-frame', 'mk-another-frame'), "home/index.html.twig"),
            ("otra pagina", valid, "marketing/producto.html.twig"),
            ("otra clase", valid.replace('class="mk-lanes"', 'class="another-logo"'), "home/index.html.twig"),
            ("otro viewBox", valid.replace('0 0 520 380', '0 0 520 381'), "home/index.html.twig"),
            ("enfocable", valid.replace('focusable="false"', 'focusable="true"'), "home/index.html.twig"),
            ("url externa", valid.replace('<svg ', '<svg href="https://example.org/logo.svg" '), "home/index.html.twig"),
            ("imagen en svg", valid.replace('</svg>', '<image href="/client.png"/></svg>'), "home/index.html.twig"),
            ("use en svg", valid.replace('</svg>', '<use href="#logo"/></svg>'), "home/index.html.twig"),
            ("etiqueta de logo", valid.replace('<svg ', '<svg aria-label="Logo de cliente" '), "home/index.html.twig"),
            ("foreignobject", valid.replace('</svg>', '<foreignObject></foreignObject></svg>'), "home/index.html.twig"),
            (
                "svg anidado con use externo posterior",
                valid.replace(
                    '</svg>',
                    '<svg viewBox="0 0 12 12"></svg>'
                    '<use href="/assets/logo.svg#marca"/></svg>',
                ),
                "home/index.html.twig",
            ),
            ("path externo", valid.replace('<path ', '<path href="https://example.org/asset.svg" '), "home/index.html.twig"),
        )
        for reason, html, page in forbidden:
            with self.subTest(reason=reason):
                findings, _ = scan_claims(page, html)
                self.assertIn("logotipo_cliente_sin_respaldo", findings)

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
