import re
import unittest
from pathlib import Path

DOC = Path(__file__).resolve().parents[1] / "docs" / "lista-salida-produccion.md"
AREAS = (
    "Respaldos y restauración", "Monitoreo y alertas", "Dominios, DNS y TLS", "Secretos y accesos",
    "Costos y límites", "Privacidad y legal", "Soporte y operación", "Migración de datos",
    "Reversión y apagado", "Seguridad", "Accesibilidad y rendimiento", "Smoke de producción",
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

    def test_sin_cobertura_tiene_issue(self):
        for r in rows():
            if r[2].strip("`") == "SIN_COBERTURA":
                self.assertRegex(r[4], r"\[#\d+\]\(https://github\.com/pl0n3r/Condor/issues/\d+\)", r[1])
            if r[2].strip("`") == "EN_CURSO":
                self.assertNotRegex(r[4], r"\(https://github\.com/pl0n3r/Condor/issues/(1|265|322|802)\)", r[1])

    def test_formato_y_estados(self):
        for r in rows():
            self.assertEqual(len(r), 5, r)
            self.assertIn(r[2].strip("`"), STATES, r[1])
            self.assertTrue(r[3], r[1])

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
