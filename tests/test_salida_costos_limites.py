"""Pruebas del presupuesto documental de Condor #984; no consultan servicios externos."""
import re
import unittest
from pathlib import Path

DOC = Path(__file__).resolve().parents[1] / "docs" / "salida-costos-limites.md"
SERVICIOS = ("Hosting", "Correo", "Monitoreo y alertas", "Respaldos y restauración", "Dominios, DNS y TLS")
FUENTES = ("https://www.hostinger.com/co/precios", "https://www.hostinger.com/co/correo-corporativo",
           "https://uptimerobot.com/pricing/", "https://www.hostinger.com/co/tld/dominio-com-co",
           "https://resend.com/pricing")
SECRETS = re.compile(r"ghp_[A-Za-z0-9]{20,}|github_pat_[A-Za-z0-9_]{20,}|AKIA[0-9A-Z]{16}|-----BEGIN [A-Z ]*PRIVATE KEY-----")


def matriz(content):
    section = content.split("## Matriz de costos, planes, condiciones y fuentes", 1)[1]
    section = section.split("## Presupuesto ilustrativo", 1)[0]
    return [[c.strip() for c in line.strip().strip("|").split("|")]
            for line in section.splitlines()
            if line.startswith("| ") and not line.startswith("| ---") and not line.startswith("| Servicio")]


class SalidaTests(unittest.TestCase):
    def test_documento_cubre_el_alcance(self):
        content = DOC.read_text(encoding="utf-8")
        rows = matriz(content)
        self.assertGreaterEqual(len(rows), 7)
        for service in SERVICIOS:
            self.assertTrue(any(r[0] == service for r in rows), service)
        for row in rows:
            self.assertEqual(len(row), 6, row)
            self.assertTrue(all(row), row)
            self.assertIn("https://", row[4])
            self.assertEqual(row[5].strip(chr(96)), "DEL_DUEÑO")
        for url in FUENTES:
            self.assertIn(url, content)
        for expected in ("COP 29.900", "COP 3.900", "COP 91.900", "USD 20",
                         "USD 144", "48 meses", "POR_COTIZAR", "renovación", "impuestos"):
            self.assertIn(expected, content)

    def test_sin_secretos_ni_datos_reales(self):
        content = DOC.read_text(encoding="utf-8")
        self.assertIsNone(SECRETS.search(content))
        self.assertNotRegex(content, r"[a-z0-9._%+-]+@[a-z0-9.-]+\.[a-z]{2,}")
        self.assertNotRegex(content, r"\b[0-9]{3}[- .]?[0-9]{3}[- .]?[0-9]{4}\b")
        self.assertIn("no_real_customer_data=true", content)
        self.assertIn("datos sintéticos", content)

    def test_marca_decisiones_del_dueno(self):
        content = DOC.read_text(encoding="utf-8")
        self.assertTrue(all(r[5].strip(chr(96)) == "DEL_DUEÑO" for r in matriz(content)))
        for text in ("DEL_DUEÑO", "aprobar gastos", "cuentas", "medios de pago",
                     "NO_COMPRAR", "NO_APLICAR_CAMBIOS", "NO_AUTORIZA_GO_LIVE"):
            self.assertIn(text.casefold(), content.casefold())

    def test_resumen_y_gates(self):
        content = DOC.read_text(encoding="utf-8")
        for text in ("## Resumen, decisiones y reversión", "COP 41.458,33",
                     "COP 497.500", "COP 60.000", "70%", "COP 42.000",
                     "90%", "COP 54.000", "100%", "TRM", "USD 32", "POR_COTIZAR",
                     "Validar", "NO_AUTORIZA_GO_LIVE"):
            self.assertIn(text, content)
        self.assertEqual(len(matriz(content)), 7, "No omitir ni duplicar servicios de referencia")
        self.assertEqual(29900*12 + 3900*12 + 91900, 497500)
        self.assertEqual(60000*70//100, 42000)
        self.assertEqual(60000*90//100, 54000)


if __name__ == "__main__":
    unittest.main()
