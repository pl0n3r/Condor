import re
import unittest
from pathlib import Path

DOC = Path(__file__).resolve().parents[1] / "docs" / "lista-salida-produccion.md"
AREAS = (
    "Respaldos y restauración", "Monitoreo y alertas", "Dominios, DNS y TLS", "Secretos y accesos",
    "Costos y límites", "Privacidad y legal", "Soporte y operación", "Migración de datos",
    "Reversión y apagado", "Seguridad", "Accesibilidad y rendimiento", "Smoke de producción",
)
REQUIREMENTS = (
    "facturación y planes", "dominio personalizado por empresa",
    "aviso de privacidad y términos por empresa", "soporte a empresas clientes",
    "primera empresa piloto", "RR. HH.", "restauración", "alertas",
    "reversión", "apagado", "accesibilidad", "smoke",
)
STATES = ("LISTO", "EN_CURSO", "SIN_COBERTURA", "DEL_DUEÑO", "NO_APLICA")
SECRET = re.compile(r"ghp_[A-Za-z0-9]{20,}|github_pat_[A-Za-z0-9_]{20,}|BEGIN [A-Z ]*PRIVATE KEY|AKIA[0-9A-Z]{16}")


def rows():
    out = []
    for line in DOC.read_text(encoding="utf-8").splitlines():
        if line.startswith("| ") and not line.startswith("| ---") and not line.startswith("| Área"):
            out.append([c.strip() for c in line.strip().strip("|").split("|")])
    return out


class ListaSalidaTests(unittest.TestCase):
    def test_cubre_todo_el_alcance(self):
        found = {r[0] for r in rows()}
        for area in AREAS:
            self.assertIn(area, found, area)
        content = DOC.read_text(encoding="utf-8").casefold()
        for requirement in REQUIREMENTS:
            self.assertIn(requirement.casefold(), content, requirement)

    def test_sin_cobertura_tiene_issue(self):
        for r in rows():
            state = r[2].strip("`")
            link = re.fullmatch(
                r"\[#(?P<n>\d+)\]\(https://github\.com/pl0n3r/Condor/issues/(?P=n)\)",
                r[4],
            )
            self.assertIsNotNone(link, r[1])
            if state == "SIN_COBERTURA":
                self.assertIn(int(link.group("n")), range(981, 988), r[1])
            if state == "EN_CURSO":
                self.assertEqual(int(link.group("n")), 616, r[1])

    def test_formato_y_estados(self):
        for r in rows():
            self.assertEqual(len(r), 5, r)
            self.assertIn(r[2].strip("`"), STATES, r[1])
            self.assertTrue(r[0] and r[1] and r[3], r[1])
            self.assertIn(r[0], AREAS + ("Pagos y correo", "Salida en vivo", "Facturación y planes"), r[0])

    def test_resumen_final(self):
        text = DOC.read_text(encoding="utf-8")
        self.assertIn("## Resumen", text)
        self.assertIn(f"Total de filas: {len(rows())}", text)
        for state in STATES:
            n = sum(1 for r in rows() if r[2].strip("`") == state)
            self.assertIn(f"- {state}: {n}", text)
        self.assertIsNone(SECRET.search(text))
        self.assertIn("no autoriza el go-live", text)


if __name__ == "__main__":
    unittest.main()
