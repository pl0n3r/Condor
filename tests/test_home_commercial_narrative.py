import re
import unittest
from pathlib import Path

ROOT = Path(__file__).resolve().parents[1]
HOME = ROOT / "templates" / "home" / "index.html.twig"
NAV = ROOT / "templates" / "marketing" / "_nav.html.twig"
CTA = ROOT / "templates" / "marketing" / "_cta.html.twig"
BASE = ROOT / "templates" / "base.html.twig"
CSS = ROOT / "public" / "marketing.css"


class HomeCommercialNarrativeTests(unittest.TestCase):
    def source(self, path: Path) -> str:
        return path.read_text(encoding="utf-8")

    def test_value_proposition_and_ctas_without_plan_prices(self) -> None:
        home = self.source(HOME)
        nav = self.source(NAV)
        self.assertIn("Digitaliza tu negocio, sea del tamaño que", home)
        self.assertIn("Para cualquier negocio, de cualquier tamaño", home)
        self.assertIn("Solicitar una demo", home)
        self.assertIn("Solicitar una demo", nav)
        self.assertIn("Ingresar", nav)
        self.assertIn('id="solicitar-demo"', self.source(CTA))
        self.assertIsNone(re.search(r"<a[^>]*>Precios</a>", home + nav))
        self.assertNotIn("por mes", home.lower())
        self.assertNotIn("/mes", home.lower())

    def test_story_is_built_on_what_the_product_does(self) -> None:
        home = self.source(HOME)
        for need in (
            "Sabe lo que tienes", "Todos los pedidos, juntos", "Tu tienda, en tu dominio",
            "Precios para cada cliente", "Cada quien, lo suyo", "Varias, sin mezclarse",
        ):
            with self.subTest(need=need):
                self.assertIn(need, home)
        for state in ("Disponible hoy", "En construcción", "Próximamente"):
            with self.subTest(state=state):
                self.assertIn(state, home)
        # Lo que no está terminado nunca se presenta como disponible.
        self.assertRegex(home, r"En construcción</span><h3>Lo que terminamos</h3><ul><li>Compras y proveedores")

    def test_ssr_accessibility_and_progressive_enhancement_contract(self) -> None:
        home = self.source(HOME)
        css = self.source(CSS)
        self.assertNotIn("<script", home.lower())
        self.assertNotIn("data-controller=", home)
        for semantic in ("<section", "<article", "<h1", "<ul", "<label"):
            with self.subTest(semantic=semantic):
                self.assertIn(semantic, home)
        self.assertGreaterEqual(home.count("aria-labelledby="), 5)
        self.assertIn(":focus-visible", css)
        self.assertIn("@media (max-width: 860px)", css)
        self.assertIn("@media (max-width: 560px)", css)
        self.assertIn("min-height: 44px", css)

    def test_seo_version_and_performance_contract(self) -> None:
        home = self.source(HOME)
        base = self.source(BASE)
        css = self.source(CSS)
        self.assertIn("{% block title %}Condor — Digitaliza tu negocio, sea del tamaño que sea{% endblock %}", home)
        self.assertIn('name="description"', home)
        self.assertIn('rel="canonical"', home)
        self.assertIn("https://www.condorapp.com.co/", home)
        self.assertIn("V {{ app_version }}", base)
        self.assertNotIn("<img", home.lower())
        self.assertNotIn("http://", home.lower())
        self.assertIn("-apple-system", css)

    def test_platform_capabilities_are_listed_with_their_real_state(self) -> None:
        home = self.source(HOME)
        block = home.split('id="plataforma"', 1)[1].split('id="estado"', 1)[0]
        for capability in (
            "Tus propios flujos", "El sitio de tu empresa, por bloques",
            "Que te encuentren en internet", "Lista para una app móvil",
        ):
            with self.subTest(capability=capability):
                self.assertIn(capability, block)
        # Nada de esta sección está terminado: ninguna capacidad puede llevar la marca de «Disponible».
        self.assertNotIn("mk-chip-ok", block)
        self.assertEqual(block.count("En construcción"), 3)
        self.assertEqual(block.count("Próximamente"), 1)
        self.assertGreaterEqual(block.count("aria-labelledby="), 1)

    def test_polish_respects_reduced_motion_and_progressive_enhancement(self) -> None:
        css = self.source(CSS)
        self.assertRegex(
            css,
            r"@supports \(animation-timeline: view\(\)\)\s*\{\s*@media \(prefers-reduced-motion: no-preference\)",
        )
        self.assertIn("scroll-margin-top", css)
        self.assertIn("font-variant-numeric: tabular-nums", css)
        self.assertNotIn("transition: all", css)

    def test_claims_are_bounded(self) -> None:
        home = self.source(HOME)
        for forbidden in (
            "100% seguro", "seguridad absoluta", "garantizamos",
            "Marcela Arias", "Fabritex", "FABRITEX", "facturación electrónica",
        ):
            with self.subTest(forbidden=forbidden):
                self.assertNotIn(forbidden, home)
        self.assertIsNone(re.search(r"\bFactory\b", home))


if __name__ == "__main__":
    unittest.main()
