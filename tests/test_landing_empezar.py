import re
import unittest
from pathlib import Path

ROOT = Path(__file__).resolve().parents[1]
PAGE = ROOT / "templates" / "marketing" / "empezar.html.twig"
NAV = ROOT / "templates" / "marketing" / "_nav.html.twig"
HOME = ROOT / "templates" / "home" / "index.html.twig"
MODULOS = ROOT / "templates" / "marketing" / "modulos.html.twig"
PARTIALS = {name: ROOT / "templates" / "marketing" / f"_{name}.html.twig" for name in ("red", "panel", "servidor")}
CONTROLLER = ROOT / "src" / "Http" / "Controller" / "MarketingController.php"


def text(path: Path) -> str:
    return path.read_text(encoding="utf-8")


def visible(source: str) -> str:
    source = re.sub(r"\{#.*?#\}", " ", source, flags=re.DOTALL)
    source = re.sub(r"\{%.*?%\}", " ", source, flags=re.DOTALL)
    return re.sub(r"<[^>]+>", " ", source)


def section(html: str, section_id: str) -> str:
    start = html.index(f'id="{section_id}"')
    end = html.index("</section>", start)
    return html[start:end]


def red() -> str:
    return section(text(PARTIALS["red"]), "red")


def panel() -> str:
    return section(text(PARTIALS["panel"]), "vista")


def servidor() -> str:
    return section(text(PARTIALS["servidor"]), "servidor")


class LandingEmpezarTests(unittest.TestCase):
    def test_route_and_minimal_navigation(self) -> None:
        self.assertIn("Route('/empezar', name: 'app_marketing_empezar', methods: ['GET'])", text(CONTROLLER))
        self.assertIn("'minimal': true", text(PAGE))
        nav = text(NAV)
        self.assertIn("minimal|default(false)", nav)
        self.assertEqual(nav.count("{% if not minimal|default(false) %}"), 1)

    def test_primary_call_to_action_leads_to_the_plan_configurator(self) -> None:
        html = text(PAGE)
        self.assertEqual(html.count("path('app_plan_configurator')"), 2)
        self.assertGreaterEqual(html.count("path('app_commercial_pricing')"), 2)
        self.assertIn("Configura tu plan", html)
        self.assertIn("path('app_plan_configurator')", text(NAV))

    def test_it_does_not_promise_online_payment_or_unconfirmed_pricing(self) -> None:
        words = visible(text(PAGE)).lower()
        self.assertIn("el pago en línea se habilitará más adelante", words)
        for forbidden in ("compra tu plan", "compra en línea", "paga tu plan", "pagar tu plan", "paga con", "checkout", "factura electrónica", "sin letra pequeña", "no se paga", "gratis", "prueba gratuita", "garantiz"):
            with self.subTest(phrase=forbidden):
                self.assertNotIn(forbidden, words)

    def test_network_map_encodes_each_stop_state_in_its_shape_and_text(self) -> None:
        net = red()
        lines = re.findall(r'<li class="mk-line (mk-line-[a-z]+)">(.*?)</ol></div></li>', net, flags=re.DOTALL)
        self.assertEqual([name for name, _ in lines], ["mk-line-vender", "mk-line-gente", "mk-line-cumplir", "mk-line-dinero", "mk-line-orden", "mk-line-crecer"])
        total = 0
        for name, body in lines:
            for state, hidden_prefix in re.findall(r'<li class="mk-stop is-([a-z]+)"><span class="mk-vh">([^<]*)</span>', body):
                total += 1
                expected = {"now": "Disponible: ", "building": "En construcción: ", "soon": "Próximamente: "}[state]
                self.assertEqual(hidden_prefix, expected)
                if state == "now":
                    self.assertEqual(name, "mk-line-vender", "Solo la línea Vender está disponible hoy")
        self.assertEqual(total, 27)
        self.assertEqual(net.count("is-now"), 6)
        self.assertEqual(net.count("is-soon"), 2)
        self.assertEqual(net.count("is-building"), 19)
        self.assertNotIn("mk-chip-ok", net)

    def test_backend_section_states_what_exists_and_what_is_under_construction(self) -> None:
        server = servidor()
        rows = re.findall(r'<li class="mk-cat-row">.*?</li>', server, flags=re.DOTALL)
        self.assertEqual(len(rows), 6)
        self.assertIn("/api/v1", server)
        ok = [re.search(r"<h3>(.*?)</h3>", row).group(1) for row in rows if "mk-chip-ok" in row]
        building = [re.search(r"<h3>(.*?)</h3>", row).group(1) for row in rows if "mk-chip-warn" in row]
        self.assertEqual(ok, ["Una API versionada", "Cada empresa, aparte", "Permisos por rol", "Sin duplicados"])
        self.assertEqual(building, ["Historial de cambios", "Respaldos"])

    def test_panel_mockup_is_labeled_as_an_example_with_fictional_data(self) -> None:
        vista = panel()
        self.assertIn("datos ficticios", vista)
        self.assertEqual(vista.count('<span class="mk-chip">Ejemplo</span>'), 2)
        self.assertEqual(vista.count('<span class="mk-chip mk-chip-warn">Simulación</span>'), 1)
        self.assertIn('aria-hidden="true"', vista[vista.index("mk-shell-nav"):vista.index("mk-shell-main")])

    def test_landing_home_and_modules_share_the_same_partials(self) -> None:
        for page in (PAGE, HOME):
            source = text(page)
            with self.subTest(page=page.name):
                for name in PARTIALS:
                    self.assertEqual(source.count(f"{{% include 'marketing/_{name}.html.twig' %}}"), 1)
                self.assertLess(source.index("_red.html"), source.index("_panel.html"))
                self.assertLess(source.index("_panel.html"), source.index("_servidor.html"))
        modules = text(MODULOS)
        self.assertEqual(modules.count("{% include 'marketing/_red.html.twig' with {'more': false} %}"), 1)
        self.assertNotIn("_panel.html", modules)
        self.assertIn("{% if more|default(true) %}", text(PARTIALS["red"]))
        self.assertIn("path('app_marketing_modulos')", text(PARTIALS["red"]))
        self.assertNotIn("Con registro", modules)

    def test_landing_has_one_h1_no_eyebrow_and_the_motion_partials(self) -> None:
        html = text(PAGE)
        self.assertEqual(html.count("<h1"), 1)
        self.assertNotIn("mk-eyebrow", html)
        self.assertIn('rel="canonical" href="https://www.condorapp.com.co/empezar"', html)
        self.assertIn("{% include 'marketing/_motion_head.html.twig' %}", html)
        self.assertIn("{% include 'marketing/_motion_scripts.html.twig' %}", html)


if __name__ == "__main__":
    unittest.main()
