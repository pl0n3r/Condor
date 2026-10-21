"""AC-01 de Condor #786: borrador de reembolsos sin fingir aprobación jurídica.

Control offline determinista: no certifica la validez jurídica final, ni un
canal de soporte, ni pagos, ni un despliegue en producción.
"""
from __future__ import annotations

import re
import unittest
from pathlib import Path

ROOT = Path(__file__).resolve().parents[1]
POLICY = ROOT / "docs/legal/politica-reembolsos.md"


def check_policy(document: str) -> list[str]:
    """Identifica salvaguardas legales y operativas documentales ausentes."""
    text = document.lower()
    errors: list[str] = []
    required = {
        "estado borrador": r"documentado, no aprobado jurídicamente",
        "ley de consumidor": r"ley 1480 de 2011",
        "artículo retracto": r"artículo 47",
        "ejercicio retracto": r"cinco\s*\(5\)\s*días hábiles",
        "excepción servicios iniciados": r"prestación de servicios que haya comenzado con acuerdo del consumidor",
        "restitución 15 días": r"quince\s*\(15\)\s*días calendario",
        "ley de devolución": r"ley 2439 de 2024",
        "control constitucional": r"c-192\s*(?:de\s*)?2026",
        "solicitud": r"cómo solicitar",
        "respuesta": r"tiempo de respuesta",
        "radicado": r"número de radicado, fecha y hora",
        "reversión distinta": r"reversión de pago",
        "B2B vs consumidor": r"\bb2b\b",
        "autoridad y aprobación": r"revisión y aprobación jurídica",
    }
    for name, pattern in required.items():
        if re.search(pattern, text, re.UNICODE) is None:
            errors.append(name)
    for name, section in (
        ("alcance", "## 1. Alcance"),
        ("retracto", "## 2. Derecho"),
        ("plazos", "## 3. Plazo"),
        ("trámite", "## 4. Cómo"),
        ("pendientes", "## 6. Aprobaciones"),
        ("fuentes", "## 7. Fuentes"),
    ):
        if section not in document:
            errors.append(name)
    if document.count("POR DEFINIR POR EL DUEÑO") < 5:
        errors.append("pendientes comerciales y contacto")
    if not re.search(r"canal oficial.{0,80}por definir por el dueño", text):
        errors.append("contacto real no especificado")
    if not re.search(r"tiempo de respuesta.{0,100}por definir por el dueño", text):
        errors.append("SLA de respuesta sin decisión")
    if re.search(r"(?:retracto|devolución del dinero).{0,60}(?:treinta\s*\(30\)|30\s*días)", text):
        errors.append("plazo histórico de 30 días presentado como actual")
    if "no afirma" not in text or "no se publica" not in text:
        errors.append("no prometer capacidades inexistentes")
    return errors


class LegalReembolsosTests(unittest.TestCase):
    def test_acceptance(self) -> None:
        self.assertTrue(POLICY.is_file(), f"Falta {POLICY}")
        document = POLICY.read_text(encoding="utf-8")
        self.assertEqual([], check_policy(document))

    def test_missing_legal_disclaimer_is_detected(self) -> None:
        document = POLICY.read_text(encoding="utf-8")
        self.assertIn("estado borrador", check_policy(document.replace(
            "documentado, no aprobado jurídicamente", "aprobado y vigente",
        )))

    def test_missing_reimbursement_deadline_is_detected(self) -> None:
        document = POLICY.read_text(encoding="utf-8")
        self.assertIn("restitución 15 días", check_policy(document.replace(
            "quince (15) días calendario", "plazo variable",
        )))

    def test_missing_owner_contact_and_sla_are_detected(self) -> None:
        document = POLICY.read_text(encoding="utf-8")
        modified = document.replace(
            "**Canal oficial de solicitudes: POR DEFINIR POR EL DUEÑO.**",
            "Canal oficial de solicitudes: contacte a soporte.",
        ).replace(
            "**Tiempo de respuesta a la solicitud:** **POR DEFINIR POR EL DUEÑO**",
            "**Tiempo de respuesta a la solicitud:** inmediato",
        )
        problems = check_policy(modified)
        self.assertIn("contacto real no especificado", problems)
        self.assertIn("SLA de respuesta sin decisión", problems)


if __name__ == "__main__":
    unittest.main()
