"""Contrato #983: inventario documental de secretos y accesos sin valores."""
import re
import unittest
from pathlib import Path

ROOT = Path(__file__).resolve().parents[1]
DOC = ROOT / "docs" / "salida-secretos-accesos.md"
TEST = Path(__file__)
REQUIRED = (
    "DATABASE_URL", "APP_SECRET", "MAILER_DSN", "SENTRY_DSN",
    "DEPLOY_SSH_KEY", "HOSTINGER_KNOWN_HOSTS", "HOSTINGER_SSH_HOST",
    "HOSTINGER_SSH_USER", "HOSTINGER_SSH_PORT", "HOSTINGER_RELEASE_ROOT",
    "GITHUB_TOKEN", "CONDOR_SMOKE_EMAIL", "CONDOR_SMOKE_PASSWORD",
    "CONDOR_AUTH_SMOKE_ENABLED", "CONDOR_RECOVERY_ENCRYPTION_PASSPHRASE",
    "RECOVERY_DISPOSABLE_DATABASE_URL", "S3-compatible", "Cuenta Hostinger",
    "Cuenta GitHub", "Cuenta de correo y Sentry",
)
PROHIBITED = (
    re.compile(r"\b(?:ghp|gho|ghu|ghs|ghr)_[A-Za-z0-9]{20,}\b"),
    re.compile(r"\bgithub_pat_[A-Za-z0-9_]{20,}\b"),
    re.compile(r"\bAKIA[0-9A-Z]{16}\b"),
    re.compile(r"-----BEGIN [A-Z ]*PRIVATE KEY-----"),
    re.compile(r"(?im)^\s*(?:DATABASE_URL|APP_SECRET|MAILER_DSN|SENTRY_DSN|DEPLOY_SSH_KEY|CONDOR_SMOKE_PASSWORD)\s*=\s*\S+"),
    re.compile(r"\b[a-zA-Z0-9._%+-]+@[a-zA-Z0-9.-]+\.[a-zA-Z]{2,}\b"),
    re.compile(r"(?i)\b(?:mysql|mariadb|postgres|smtp)://[^\s/]+:[^\s@]+@"),
)


def inventario():
    contenido = DOC.read_text(encoding="utf-8")
    bloque = contenido.split("## Inventario de secretos y accesos (sin valores)", 1)[1]
    bloque = bloque.split("\n## ", 1)[0]
    filas = [line for line in bloque.splitlines() if line.startswith("| ")]
    return [[celda.strip() for celda in fila.strip().strip("|").split("|")]
            for fila in filas if not fila.startswith("| ---")]


class SalidaTests(unittest.TestCase):
    def test_documento_cubre_el_alcance(self):
        self.assertTrue(DOC.is_file())
        contenido = DOC.read_text(encoding="utf-8")
        for seccion in ("## Propósito, alcance y autoridad", "## Inventario de secretos y accesos",
                        "## Procedimiento propuesto", "## Evidencia requerida",
                        "## Puertas y decisiones exclusivas del dueño"):
            with self.subTest(seccion=seccion):
                self.assertIn(seccion, contenido)
        filas = inventario()
        self.assertGreaterEqual(len(filas), 12)
        self.assertEqual(filas[0], ["Nombre o acceso", "Propósito", "Dónde vive / custodia prevista",
                                    "Responsable", "Rotación planificada", "Revocación planificada"])
        for fila in filas[1:]:
            self.assertEqual(len(fila), 6, fila)
            self.assertTrue(all(fila), fila)
            self.assertIn("DUEÑO", fila[3], fila)
        for nombre in REQUIRED:
            with self.subTest(nombre=nombre):
                self.assertIn(nombre, contenido)

    def test_sin_secretos_ni_datos_reales(self):
        for ruta in (DOC, TEST):
            contenido = ruta.read_text(encoding="utf-8")
            for patron in PROHIBITED:
                with self.subTest(ruta=ruta.name, patron=patron.pattern):
                    self.assertIsNone(patron.search(contenido))
        contenido = DOC.read_text(encoding="utf-8")
        self.assertIn("sin valores", contenido.casefold())
        self.assertIn("nunca copiar valores", contenido.casefold())
        self.assertIn("no se hicieron cambios operativos", contenido.casefold())

    def test_marca_decisiones_del_dueno(self):
        contenido = DOC.read_text(encoding="utf-8")
        self.assertGreaterEqual(contenido.count("PENDIENTE DE DECISIÓN DEL DUEÑO"), 5)
        for termino in ("go-live", "gastos", "cuentas", "rotación", "revocación",
                        "datos reales", "MFA", "responsables", "señales", "rollback"):
            with self.subTest(termino=termino):
                self.assertIn(termino.casefold(), contenido.casefold())
        self.assertIn("no autoriza", contenido.casefold())
        self.assertIn("fuera de alcance", contenido.casefold())


if __name__ == "__main__":
    unittest.main()
