"""Contrato documental del ensayo de importación piloto; sin conexión ni datos reales."""

import re
import unittest
from pathlib import Path

ROOT = Path(__file__).resolve().parents[1]
DOC = ROOT / "docs" / "salida-importacion-piloto.md"
TEST = Path(__file__).resolve()

# Firmas estructurales, sin ejemplos con credenciales ni PII persistidos en el repo.
SENSITIVE = (
    re.compile(r"(?:ghp_|github_pat_)[A-Za-z0-9_]{20,}"),
    re.compile(r"AKIA[0-9A-Z]{16}"),
    re.compile(r"-----BEGIN (?:RSA |EC |OPENSSH )?PRIVATE KEY-----"),
    re.compile(r"\b(?:[\w.+-]+)@(?:[\w-]+\.)+[A-Za-z]{2,}\b"),
    re.compile(r"(?<!\d)(?:\+?57[ -]?)?3\d{2}[ -]?\d{3}[ -]?\d{4}(?!\d)"),
)


def leak_flags(text):
    return [expression.pattern for expression in SENSITIVE if expression.search(text)]


class SalidaTests(unittest.TestCase):
    def test_documento_cubre_el_alcance(self):
        self.assertTrue(DOC.is_file(), "debe existir el plan")
        content = DOC.read_text(encoding="utf-8").casefold()
        sections = (
            "propósito y alcance", "formatos de entrada", "responsables",
            "procedimiento y verificaciones", "evidencia requerida",
        )
        for section in sections:
            with self.subTest(section=section):
                self.assertIn(section, content)
        requirements = (
            "catálogo", "clientes", "inventario", "csv", "utf-8", "sha-256",
            "tenant", "validación", "duplicad", "idempoten", "dry-run",
            "aislamiento", "sintético", "rollback", "reversión", "restore",
            "backup", "abort", "conteos", "responsable", "evidencia",
            "datos.yml", "d-054", "privacidad", "autorización",
        )
        for keyword in requirements:
            with self.subTest(keyword=keyword):
                self.assertIn(keyword, content)
        for dataset in ("catálogo", "clientes", "inventario"):
            self.assertRegex(content, rf"(?m)^- \*\*{dataset}:\*\*")
        self.assertIn("no importar", content)
        self.assertIn("no activar live", content)

    def test_sin_secretos_ni_datos_reales(self):
        plan = DOC.read_text(encoding="utf-8")
        source = TEST.read_text(encoding="utf-8")
        for label, content in (("documento", plan), ("prueba", source)):
            with self.subTest(file=label):
                self.assertFalse(leak_flags(content), f"firma sensible en {label}")
        self.assertIn("sin datos reales", plan.casefold())
        self.assertIn("descartable", plan.casefold())
        self.assertIn("no recibir ni almacenar archivos reales", plan.casefold())
        # La detección negativa usa valores construidos en ejecución y ficticios.
        synthetic_email = "contacto" + "@" + "example" + ".invalid"
        synthetic_token = "ghp" + "_" + ("x" * 24)
        self.assertTrue(leak_flags(synthetic_email))
        self.assertTrue(leak_flags(synthetic_token))

    def test_marca_decisiones_del_dueno(self):
        content = DOC.read_text(encoding="utf-8").casefold()
        required = (
            "decisión del dueño", "decisión explícita del dueño", "cuentas",
            "gasto", "proveedores", "revisión legal", "empresa piloto",
            "datos reales", "go-live", "no habilitada", "sin respuesta",
            "backup", "ejecución real", "no importar",
        )
        for keyword in required:
            with self.subTest(keyword=keyword):
                self.assertIn(keyword, content)
        self.assertRegex(content, r"\*\*decisión del dueño: pendiente\*\*")
        self.assertRegex(content, r"\*\*pendiente exclusivamente del dueño:\*\*")

    def test_integracion_validar(self):
        """AC-04: contrato local y evidencia CI separados; CI debe ejecutarse en GitHub."""
        ci = (ROOT / ".github" / "workflows" / "ci.yml").read_text(encoding="utf-8")
        self.assertRegex(ci, r"(?m)^\s+name: Validar\s*$")
        self.assertIn("python3 scripts/validar_documentacion.py", ci)
        self.assertTrue(DOC.exists())
        self.assertTrue(TEST.exists())


if __name__ == "__main__":
    unittest.main()
