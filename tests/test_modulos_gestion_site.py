import re
import unittest
from pathlib import Path

ROOT = Path(__file__).resolve().parents[1]
TEMPLATES = ROOT / "templates"
PAGE = TEMPLATES / "marketing" / "modulos.html.twig"

# Los 16 módulos de docs/gestion-empresarial-integral.md, con el nombre que ve quien visita el sitio.
MODULES = (
    "Talento humano", "Contratación laboral", "Nómina", "Seguridad social",
    "Seguridad y salud en el trabajo", "Cumplimiento legal", "Riesgos empresariales", "Calidad",
    "Finanzas y contabilidad", "Compras y proveedores", "Activos empresariales", "Gestión documental",
    "Operaciones", "Seguridad de la información", "Gobierno corporativo", "Servicio al cliente",
)
PEOPLE_MODULES = ("Talento humano", "Contratación laboral", "Nómina", "Seguridad social")


def text(path: Path) -> str:
    return path.read_text(encoding="utf-8")


def cards() -> dict[str, str]:
    html = text(PAGE)
    found = {}
    for card in re.findall(r'<article class="mk-need">.*?</article>', html, flags=re.DOTALL):
        name = re.search(r"<h3>(.*?)</h3>", card).group(1)
        assert name not in found, f"Tarjeta duplicada: {name}"
        found[name] = card
    return found


class ModulosGestionSiteTests(unittest.TestCase):
    def test_all_sixteen_management_modules_are_listed(self) -> None:
        found = cards()
        self.assertEqual(set(found), set(MODULES))
        self.assertEqual(len(found), 16)

    def test_no_module_is_presented_as_available(self) -> None:
        html = text(PAGE)
        self.assertNotIn("mk-chip-ok", html)
        self.assertNotIn("Disponible", html)
        for name, card in cards().items():
            with self.subTest(module=name):
                self.assertIn('<span class="mk-chip mk-chip-warn">En construcción</span>', card)

    def test_people_modules_state_the_real_data_limit(self) -> None:
        found = cards()
        for name in PEOPLE_MODULES:
            with self.subTest(module=name):
                self.assertIn("Datos reales de personas: pendientes de una decisión y de revisión jurídica.", found[name])
        for name in set(MODULES) - set(PEOPLE_MODULES):
            with self.subTest(module=name):
                self.assertNotIn("Datos reales de personas", found[name])

    def test_page_is_reachable_from_nav_footer_and_home(self) -> None:
        controller = text(ROOT / "src" / "Http" / "Controller" / "MarketingController.php")
        self.assertIn("Route('/modulos', name: 'app_marketing_modulos', methods: ['GET'])", controller)
        link = "path('app_marketing_modulos')"
        for relative in ("marketing/_nav.html.twig", "marketing/_cta.html.twig", "home/index.html.twig"):
            with self.subTest(template=relative):
                self.assertIn(link, text(TEMPLATES / relative))
        self.assertIn("== 'modulos'", text(TEMPLATES / "marketing" / "_nav.html.twig"))


if __name__ == "__main__":
    unittest.main()
