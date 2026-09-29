import unittest
from pathlib import Path

ROOT = Path(__file__).resolve().parents[1]
HOME = ROOT / "templates" / "home" / "index.html.twig"
BASE = ROOT / "templates" / "base.html.twig"
CSS = ROOT / "public" / "app.css"


class HomeCommercialNarrativeTests(unittest.TestCase):
    def source(self, path: Path) -> str:
        return path.read_text(encoding="utf-8")

    def test_value_proposition_ctas_and_no_prices(self) -> None:
        home = self.source(HOME)
        self.assertIn("Tu empresa, conectada.", home)
        self.assertIn(
            "Tu empresa no necesita más software. Necesita que todo funcione junto.",
            home,
        )
        self.assertGreaterEqual(home.count("Solicitar una demo"), 3)
        self.assertIn("Ver cómo funciona", home)
        self.assertIn("Ingresar", home)
        self.assertNotIn(">Precios<", home)
        self.assertNotIn("$", home)
        self.assertNotIn("COP", home)

    def test_operational_story_matches_real_product(self) -> None:
        home = self.source(HOME)
        for term in (
            "Inventario", "Ventas", "Producción", "E-commerce",
            "Compras y proveedores", "Clientes", "Reportes",
            "SKU", "kardex", "mayoristas", "descuentos por cantidad",
            "Proveedor", "Materia prima", "Producto terminado",
            "B2B + B2C", "Multiempresa",
            "Colombia-first. Global-ready.",
        ):
            with self.subTest(term=term):
                self.assertIn(term, home)
        self.assertIn("aislados por empresa", home)
        self.assertIn("relaciones entre empresas son explícitas", home)
        self.assertIn("no se presume", home)

    def test_ssr_accessibility_and_progressive_enhancement_contract(self) -> None:
        home = self.source(HOME)
        css = self.source(CSS)
        self.assertNotIn("<script", home.lower())
        self.assertNotIn("data-controller=", home)
        for semantic in ("<header", "<nav", "<section", "<article", "<ol", "<h1"):
            with self.subTest(semantic=semantic):
                self.assertIn(semantic, home)
        self.assertGreaterEqual(home.count("aria-labelledby="), 8)
        self.assertIn(":focus-visible", css)
        self.assertIn("@media (max-width: 860px)", css)
        self.assertIn("@media (max-width: 560px)", css)
        self.assertIn("min-height: 44px", css)

    def test_seo_version_and_performance_contract(self) -> None:
        home = self.source(HOME)
        base = self.source(BASE)
        css = self.source(CSS)
        self.assertIn("{% block title %}Condor — Tu empresa, conectada{% endblock %}", home)
        self.assertIn('name="description"', home)
        self.assertIn('rel="canonical"', home)
        self.assertIn("https://www.condorapp.com.co/", home)
        self.assertIn("V {{ app_version }}", base)
        self.assertNotIn("<img", home.lower())
        self.assertNotIn("http://", home.lower())
        self.assertIn("-apple-system", css)

    def test_factory_security_and_visual_claims_are_bounded(self) -> None:
        home = self.source(HOME)
        for required in (
            "Roles, permisos y separación por empresa",
            "Auditoría y flujos verificables",
            "Backups, restore drills y recuperación de cuenta",
            "fábrica de software con CI, revisión, releases y validación de producción",
            "Ejemplo conceptual",
            "Esta portada no recoge datos personales",
        ):
            with self.subTest(required=required):
                self.assertIn(required, home)
        for forbidden in (
            "100% seguro", "seguridad absoluta", "garantizamos",
            "Marcela Arias", "FABRITEX", "dashboard ficticio",
        ):
            with self.subTest(forbidden=forbidden):
                self.assertNotIn(forbidden, home)


if __name__ == "__main__":
    unittest.main()
