"""Contrato de documentación del runbook de salida #982 (sin red ni secretos)."""
from __future__ import annotations

import re
import unittest
from pathlib import Path

ROOT = Path(__file__).resolve().parents[1]
DOCUMENT = ROOT / "docs" / "salida-reversion-apagado.md"

SECRET = re.compile(
    r"ghp_[A-Za-z0-9]{20,}|github_pat_[A-Za-z0-9_]{20,}|"
    r"AKIA[0-9A-Z]{16}|-----BEGIN [A-Z ]*PRIVATE KEY-----|"
    r"(?i:password|token|secret)\s*[:=]\s*['\"][^'\"\s]{8,}['\"]"
)
PERSONAL_DATA = re.compile(
    r"(?i)(?:[A-Za-z0-9._%+-]+@[A-Za-z0-9.-]+\.[A-Za-z]{2,}|"
    r"\b(?:c[ée]dula|documento de identidad|tel[ée]fono)\s*[:=]\s*[0-9]{6,}\b)"
)


class SalidaTests(unittest.TestCase):
    def setUp(self) -> None:
        self.assertTrue(DOCUMENT.is_file(), "Falta el runbook")
        self.documento = DOCUMENT.read_text(encoding="utf-8")
        self.min = self.documento.casefold()

    def test_documento_cubre_el_alcance(self) -> None:
        for frase in (
            "reversión de código", "rollback", "migraciones aditivas",
            "forward-fix", "restauración", "modo mantenimiento",
            "apagado global", "tenant", "aislamiento", "comunicación",
            "responsables", "evidencia", "health", "smoke", "503",
            "retry-after", "rpo", "rto", "abortar",
        ):
            self.assertIn(frase.casefold(), self.min, frase)
        for enlace in (
            "LIVE-MIGRATION-POLICY.md", "recovery-production.md",
            "LIVE-UPTIME-RUNBOOK.md", "LIVE-AUTH-SMOKE-RUNBOOK.md",
            "ops/factory/rollback", "scripts/verify-backup-restore.sh",
        ):
            self.assertIn(enlace.casefold(), self.min, enlace)
        self.assertIn("no deshace automáticamente una migración", self.min)
        self.assertIn("base descartable", self.min)
        self.assertIn("no afirmar apagado por tenant", self.min)

    def test_sin_secretos_ni_datos_reales(self) -> None:
        self.assertIsNone(SECRET.search(self.documento), "Posible secreto/credencial")
        self.assertIsNone(PERSONAL_DATA.search(self.documento), "Posible dato personal")
        self.assertNotIn("datos de clientes reales", self.min.replace("no es evidencia", ""))
        self.assertNotIn("curl -u", self.min)
        self.assertNotIn("password=", self.min)
        self.assertNotIn("DATABASE_URL=", self.documento)

    def test_marca_decisiones_del_dueno(self) -> None:
        self.assertGreaterEqual(self.documento.count("DEL_DUEÑO"), 4)
        for decision in ("go-live", "gastos", "datos reales", "hostinger",
                         "restauración", "comunicaciones", "mantenimiento real"):
            self.assertIn(decision.casefold(), self.min, decision)
        self.assertIn("no autoriza go-live", self.min)
        self.assertIn("aprobados por el dueño", self.min)
        self.assertIn("no tocar", self.min) if "no tocar" in self.min else self.assertIn("no autoriza", self.min)

    def test_validar_sobre_head_exacto(self) -> None:
        self.assertRegex(self.documento, r"(?i)AC-04.*validar")
        self.assertIn("head exacto", self.min)
        self.assertIn("terminal", self.min)
        self.assertIn("success", self.min)
        self.assertIn("reserva v3", self.min)
        self.assertIn("squash", self.min)
        self.assertIn("no prueba operativa", self.min)


if __name__ == "__main__":
    unittest.main()
