"""Condor #981: aceptación documental segura del respaldo y restore, sin efectos."""
from pathlib import Path
import re
import unittest

ROOT = Path(__file__).resolve().parents[1]
DOC = ROOT / "docs" / "salida-respaldo-restauracion.md"
DRILL = ROOT / ".github" / "workflows" / "recovery-production.yml"
CI = ROOT / ".github" / "workflows" / "ci.yml"

LEAK = re.compile(
    r"(?:mysql|mariadb|postgres|postgresql)://[^\s)]+"
    r"|(?:ghp_[A-Za-z0-9]{20,}|github_pat_[A-Za-z0-9_]{20,})"
    r"|(?:AKIA[0-9A-Z]{16})|-----BEGIN [A-Z ]*PRIVATE KEY-----"
    r"|\b[\w.+-]+@[\w.-]+\.[A-Za-z]{2,}\b",
    re.IGNORECASE,
)

def read_runbook():
    return DOC.read_text(encoding="utf-8")

class SalidaTests(unittest.TestCase):
    def test_documento_cubre_el_alcance(self):
        self.assertTrue(DOC.is_file(), "Falta el runbook de salida")
        text = read_runbook()
        required = (
            "## Propósito, alcance y responsabilidades",
            "## Arquitectura comprobada y brechas",
            "## Frecuencia, retención, cifrado, RPO y RTO propuestos",
            "## Pasos de implementación futura",
            "## Evidencia mínima para desbloquear salida a producción",
            "## DECISIÓN DEL DUEÑO",
            "base de datos", "archivos", "cada 5 minutos", "30 días",
            "900 segundos", "3600 segundos", "AES-256-CBC/PBKDF2",
            "SHA-256", "Object Lock COMPLIANCE", "ControlBot",
            "FactoryRunner", "OPERADOR", "workflow_dispatch",
            "ensayo repetible", "descartables", "/health", "smoke",
            "scripts/backup-database.sh", "scripts/verify-backup-restore.sh",
            "docs/recovery-production.md", ".github/workflows/recovery-production.yml",
            "SIN DECIDIR", "NO VERIFICADO",
        )
        for token in required:
            with self.subTest(token=token):
                self.assertIn(token, text)
        self.assertIn("incident_at - recovery_point_at", text)
        self.assertIn("completed_at - started_at", text)
        self.assertIn("RPO_EXCEEDED/RTO_EXCEEDED", text)
        self.assertIn("archivo", text.casefold())
        self.assertIn("RPO observado", text)
        self.assertNotIn("backups automáticos activos: sí", text.casefold())

    def test_sin_secretos_ni_datos_reales(self):
        text = read_runbook()
        self.assertIsNone(LEAK.search(text), "No publicar secretos, DSN ni correos")
        for token in ("NO AUTORIZADO", "NO VERIFICADO", "sin datos reales",
                      "No registrar", "sin tocar producción"):
            with self.subTest(token=token):
                self.assertIn(token.casefold(), text.casefold())
        for fake in ("mysql://host/db", "a" + "@example.test",
                     "-----BEGIN TEST PRIVATE KEY-----"):
            with self.subTest(negative=fake):
                self.assertIsNotNone(LEAK.search(fake))

    def test_marca_decisiones_del_dueno(self):
        text = read_runbook()
        self.assertGreaterEqual(text.count("DECISIÓN DEL DUEÑO:"), 5)
        for item in ("proveedor y cuenta", "gasto y capacidad",
                     "retención y privacidad", "agenda y responsables",
                     "restauración productiva y go-live"):
            with self.subTest(item=item):
                self.assertIn("DECISIÓN DEL DUEÑO: " + item, text)
        self.assertIn("autorización humana separada", text)
        self.assertIn("ControlBot apagado", text)
        self.assertIn("no se ejecutan agendas", text.casefold())

    def test_preparacion_para_validar(self):
        self.assertTrue(CI.is_file())
        self.assertTrue(DRILL.is_file())
        workflow = DRILL.read_text(encoding="utf-8")
        self.assertRegex(workflow, r"(?m)^\s*workflow_dispatch:")
        self.assertNotRegex(workflow, r"(?m)^\s*schedule:")
        self.assertIn("Validar", CI.read_text(encoding="utf-8"))
        self.assertIn("Validar en success sobre el HEAD exacto", read_runbook())
        self.assertIn("No se invoca .github/workflows/recovery-production.yml", read_runbook())

if __name__ == "__main__":
    unittest.main()
