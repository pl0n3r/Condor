"""Aceptación Condor #987: presupuestos estáticos reales sin producción."""
from __future__ import annotations

from pathlib import Path
import re
import unittest

ROOT = Path(__file__).resolve().parents[1]
DOC = ROOT / "docs/salida-rendimiento-a11y.md"
CI = ROOT / ".github/workflows/ci.yml"
CI_ACCESSIBILITY = ROOT / "tests/test_ci_public_site_accessibility.py"
SCREENS = {"Inicio público", "Producto público", "Acceso administrativo", "Panel administrativo"}
RESOURCES = {
    "Inicio público": {"templates/home/index.html.twig": 24576, "public/marketing.css": 65536},
    "Producto público": {"templates/marketing/producto.html.twig": 20480, "public/marketing.css": 65536},
    "Acceso administrativo": {"templates/security/login.html.twig": 8192},
    "Panel administrativo": {
        "templates/admin/index.html.twig": 8192,
        "public/build/admin.js": 393216,
        "public/build/admin.css": 32768,
    },
}
LEAK = re.compile(
    r"ghp_[A-Za-z0-9]{20,}|github_pat_[A-Za-z0-9_]{20,}"
    r"|AKIA[0-9A-Z]{16}|-----BEGIN [A-Z ]*PRIVATE KEY-----"
    r"|(?:mysql|mariadb|postgres(?:ql)?)://[^\s)]+"
    r"|\b[\w.+-]+@[\w.-]+\.[A-Za-z]{2,}\b", re.IGNORECASE
)


def document() -> str:
    return DOC.read_text(encoding="utf-8")


def rows_between(content: str, before: str, after: str) -> list[list[str]]:
    section = content.split(before, 1)[1].split(after, 1)[0]
    return [
        [item.strip() for item in line.strip().strip("|").split("|")]
        for line in section.splitlines()
        if line.startswith("| ") and not line.startswith("| ---")
        and not line.startswith("| Pantalla")
    ]


def verify_static_budget(path: Path, limit: int) -> int:
    if not path.is_file():
        raise AssertionError(f"Falta recurso: {path}")
    observed = path.stat().st_size
    if observed > limit:
        raise AssertionError(f"Presupuesto excedido ({observed} > {limit}): {path}")
    return observed


class SalidaTests(unittest.TestCase):
    def test_documento_cubre_el_alcance(self) -> None:
        self.assertTrue(DOC.is_file())
        content = document()
        for token in (
            "## Propósito, alcance y responsables",
            "## Presupuesto automático del código en CI",
            "## Presupuesto de navegador y accesibilidad previo a live",
            "## Pasos y evidencia requerida", "## DECISIÓN DEL DUEÑO",
            "p75", "LCP", "INP", "CLS", "WCAG 2.2 AA",
            "Inicio público", "Producto público",
            "Acceso administrativo", "Panel administrativo",
            "NO VERIFICADO", "sin datos reales",
        ):
            with self.subTest(token=token):
                self.assertIn(token, content)

        resource_rows = rows_between(
            content, "## Presupuesto automático del código en CI",
            "## Presupuesto de navegador y accesibilidad previo a live",
        )
        actual: dict[str, dict[str, int]] = {name: {} for name in SCREENS}
        self.assertEqual(len(resource_rows), sum(map(len, RESOURCES.values())))
        for row in resource_rows:
            self.assertEqual(len(row), 4, row)
            screen, source, metric, number = row
            self.assertIn(screen, SCREENS)
            self.assertRegex(source, r"^\x60[\w./-]+\x60$")
            path = source.strip(chr(96))
            self.assertIn(path, RESOURCES[screen])
            self.assertEqual(metric, "bytes")
            self.assertRegex(number, r"^[1-9][0-9]*$")
            maximum = int(number)
            self.assertLessEqual(maximum, RESOURCES[screen][path])
            self.assertNotIn(path, actual[screen], path)
            actual[screen][path] = maximum
            verify_static_budget(ROOT / path, maximum)
        self.assertEqual(actual, RESOURCES)

        browser_rows = rows_between(
            content, "## Presupuesto de navegador y accesibilidad previo a live",
            "## Pasos y evidencia requerida",
        )
        self.assertEqual(len(browser_rows), len(SCREENS))
        seen = set()
        for row in browser_rows:
            self.assertEqual(len(row), 8, row)
            screen, lcp, inp, cls, normal, large, axe, keyboard = row
            self.assertIn(screen, SCREENS)
            self.assertNotIn(screen, seen)
            seen.add(screen)
            self.assertLessEqual(int(lcp), 2500)
            self.assertLessEqual(int(inp), 200)
            self.assertLessEqual(float(cls), 0.10)
            self.assertEqual(normal, "4.5:1")
            self.assertEqual(large, "3:1")
            self.assertEqual(int(axe), 0)
            self.assertEqual(keyboard, "100%")
        self.assertEqual(seen, SCREENS)
        with self.assertRaises(AssertionError):
            verify_static_budget(ROOT / "public/marketing.css", 1)

    def test_sin_secretos_ni_datos_reales(self) -> None:
        content = document()
        self.assertIsNone(LEAK.search(content))
        self.assertIsNone(LEAK.search(Path(__file__).read_text(encoding="utf-8")))
        for marker in ("sin datos reales", "NO VERIFICADO", "NO AUTORIZADAS",
                       "no se ejecuta ninguna prueba contra producción",
                       "no equivalen a tamaño de transferencia comprimida"):
            self.assertIn(marker.casefold(), content.casefold())
        self.assertRegex("ghp_" + "A" * 32, LEAK)

    def test_marca_decisiones_del_dueno(self) -> None:
        content = document()
        self.assertGreaterEqual(content.count("DECISIÓN DEL DUEÑO:"), 4)
        for item in ("costos", "credenciales de prueba", "go-live", "dominio real",
                     "responsable", "SIN DECIDIR", "NO AUTORIZADAS"):
            self.assertIn(item.casefold(), content.casefold())
        self.assertIn("autorización humana", content.casefold())

    def test_presupuestos_en_ci(self) -> None:
        """Factory acceptance mide límites; CI ejecuta el baseline público de a11y."""
        ci = CI.read_text(encoding="utf-8")
        alias = CI_ACCESSIBILITY.read_text(encoding="utf-8")
        content = document()
        self.assertIn("factory-ci:", ci)
        self.assertIn("name: Validar", ci)
        self.assertIn("python3 -m unittest discover -s tests -p 'test_ci_*.py'", ci)
        self.assertIn("from test_public_site_accessibility import AccesibilidadSitioPublicoTests", alias)
        self.assertIn("tests/test_ci_public_site_accessibility.py", content)
        self.assertIn("tests/test_salida_rendimiento_a11y.py::SalidaTests::test_documento_cubre_el_alcance", content)
        self.assertIn("Criterios de aceptación", content)
        self.assertIn("Lighthouse o axe", content)
        self.assertIn("no hay recibo de laboratorio integral", content)


if __name__ == "__main__":
    unittest.main()
