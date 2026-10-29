import re
import unittest
from pathlib import Path

ROOT = Path(__file__).resolve().parents[1]
TEMPLATES = ROOT / "templates"
PAGES = {
    "home": TEMPLATES / "home" / "index.html.twig",
    "producto": TEMPLATES / "marketing" / "producto.html.twig",
    "modulos": TEMPLATES / "marketing" / "modulos.html.twig",
    "soluciones": TEMPLATES / "marketing" / "soluciones.html.twig",
    "seguridad": TEMPLATES / "marketing" / "seguridad.html.twig",
}
PARTIALS = (TEMPLATES / "marketing" / "_nav.html.twig", TEMPLATES / "marketing" / "_cta.html.twig")
CSS = ROOT / "public" / "marketing.css"
FONT = ROOT / "public" / "fonts" / "bricolage-grotesque-latin.woff2"
LICENSE = ROOT / "public" / "fonts" / "LICENSE-OFL-bricolage-grotesque.txt"
CONTROLLER = ROOT / "src" / "Http" / "Controller" / "MarketingController.php"
CANONICAL = {
    "home": "https://www.condorapp.com.co/",
    "producto": "https://www.condorapp.com.co/producto",
    "modulos": "https://www.condorapp.com.co/modulos",
    "soluciones": "https://www.condorapp.com.co/soluciones",
    "seguridad": "https://www.condorapp.com.co/seguridad",
}


def text(path: Path) -> str:
    return path.read_text(encoding="utf-8")


class MarketingSiteTests(unittest.TestCase):
    def test_routes_exist_only_for_the_three_new_pages(self) -> None:
        controller = text(CONTROLLER)
        for path, name in (("/producto", "app_marketing_producto"), ("/modulos", "app_marketing_modulos"), ("/soluciones", "app_marketing_soluciones"), ("/seguridad", "app_marketing_seguridad")):
            with self.subTest(path=path):
                self.assertIn(f"Route('{path}', name: '{name}', methods: ['GET'])", controller)
        self.assertIn("NotFoundHttpException", controller)
        self.assertIn("$tenantContext->current() !== null", controller)

    def test_every_page_is_ssr_without_third_parties(self) -> None:
        for name, path in PAGES.items():
            html = text(path)
            with self.subTest(page=name):
                self.assertNotIn("<script", html.lower())
                self.assertNotIn("<img", html.lower())
                self.assertNotIn("<iframe", html.lower())
                # La CSP del sitio (style-src 'self') bloquea los estilos en línea.
                self.assertNotIn(" style=", html.lower())
                self.assertNotIn("<style", html.lower())
                self.assertNotIn("http://", html.lower())
                self.assertEqual(html.count("<h1"), 1)
                self.assertIn(f'rel="canonical" href="{CANONICAL[name]}"', html)
                self.assertIn('name="description"', html)
                self.assertIn('href="/marketing.css"', html)
                hosts = set(re.findall(r"https://([a-z0-9.-]+)", html))
                self.assertEqual(hosts - {"www.condorapp.com.co"}, set())
        css = text(CSS)
        self.assertNotIn("googleapis", css)
        self.assertNotIn("gstatic", css)
        self.assertNotIn("@import", css)
        self.assertEqual(set(re.findall(r"url\(\"([^\"]+)\"\)", css)), {"/fonts/bricolage-grotesque-latin.woff2"})
        self.assertTrue(FONT.is_file() and FONT.stat().st_size > 10_000)
        self.assertIn("SIL OPEN FONT LICENSE", text(LICENSE))

    def test_interactivity_is_css_only_and_complete_at_rest(self) -> None:
        css = text(CSS)
        for name, path in PAGES.items():
            html = text(path)
            ids = re.findall(r'<input class="mk-vh" type="(?:radio|checkbox)"[^>]*id="([^"]+)"', html)
            labels = set(re.findall(r'for="([^"]+)"', html))
            with self.subTest(page=name):
                self.assertTrue(set(ids) <= labels, f"entradas sin etiqueta: {set(ids) - labels}")
                for i in ids:
                    self.assertIn(f"#{i}:checked", css, f"{i} sin regla CSS")
            groups = re.findall(r'<input class="mk-vh" type="radio" name="([^"]+)"[^>]*>', html)
            for group in set(groups):
                first = re.search(rf'<input class="mk-vh" type="radio" name="{group}"[^>]*>', html)
                self.assertIsNotNone(first)
                self.assertIn("checked", first.group(0), f"el grupo {group} debe abrir con una opción activa")

    def test_statuses_are_honest(self) -> None:
        home = text(PAGES["home"])
        producto = text(PAGES["producto"])
        soluciones = text(PAGES["soluciones"])
        seguridad = text(PAGES["seguridad"])
        self.assertIn("Compras", home)
        self.assertRegex(producto, r"mk-chip-warn\">En construcción</span>")
        self.assertIn("!Alertas de stock bajo (en construcción)".lstrip("!"), producto)
        self.assertIn("Costo real de cada orden (en construcción)", producto)
        self.assertIn("Compras · en construcción", soluciones)
        for pending in ("Verificación en dos pasos", "Copia fuera del servidor, bloqueada contra borrado"):
            self.assertIn(pending, seguridad)
        self.assertRegex(seguridad, r"En construcción</span><h3>Lo que estamos terminando</h3>")
        for page in PAGES.values():
            html = text(page)
            if "$ " in html:
                self.assertIn("Ejemplo", html)

    def test_claims_are_bounded_and_unpriced(self) -> None:
        for name, path in PAGES.items():
            html = text(path)
            with self.subTest(page=name):
                for forbidden in ("100% seguro", "seguridad absoluta", "garantizamos", "Marcela Arias", "Fabritex", "FABRITEX", "facturación electrónica", "por mes", "/mes"):
                    self.assertNotIn(forbidden, html)
                self.assertIsNone(re.search(r"<a[^>]*>Precios</a>", html))
                self.assertIsNone(re.search(r"\bFactory\b", html))

    def test_stylesheet_contract(self) -> None:
        css = text(CSS)
        for needle in (
            ":focus-visible", "min-height: 44px", "@media (max-width: 860px)", "@media (max-width: 560px)",
            "prefers-color-scheme: dark", "prefers-reduced-motion: reduce", "-apple-system", "font-display: swap",
        ):
            with self.subTest(needle=needle):
                self.assertIn(needle, css)

    def test_acceptance_marketing_site_contract(self) -> None:
        self.test_routes_exist_only_for_the_three_new_pages()
        self.test_every_page_is_ssr_without_third_parties()
        self.test_interactivity_is_css_only_and_complete_at_rest()
        self.test_statuses_are_honest()
        self.test_claims_are_bounded_and_unpriced()
        self.test_stylesheet_contract()
        for partial in PARTIALS:
            self.assertTrue(partial.is_file())
        nav = text(PARTIALS[0])
        for route in ("app_marketing_producto", "app_marketing_modulos", "app_marketing_soluciones", "app_marketing_seguridad", "app_login"):
            self.assertIn(route, nav)


if __name__ == "__main__":
    unittest.main()
