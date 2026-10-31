#!/usr/bin/env python3
"""Condor #1446: validación fail-closed de plantillas legales, no aprobación jurídica."""
from __future__ import annotations

from pathlib import Path
import re
import unittest

ROOT = Path(__file__).resolve().parents[1]
DOC = ROOT / "docs" / "privacidad" / "plantilla-empresa-cliente.md"
COMPANY = "[COMPLETAR POR LA EMPRESA]"
LAWYER = "[PENDIENTE DE REVISIÓN DEL ABOGADO]"
SECTIONS = (
    "## Plantilla A — Aviso de privacidad de la empresa",
    "## Plantilla B — Términos del servicio para la empresa cliente",
    "## Plantilla C — Contrato de tratamiento de datos por empresa cliente",
)
REQUIRED = {
    "identificacion": ("Razón social", "identificación tributaria", "Domicilio",
                       "representante legal", "Canal de contacto", "derechos de los titulares"),
    "aviso": ("Responsable propuesto", "Finalidades específicas", "Base jurídica",
              "Derechos de titulares", "Destinatarios", "Conservación",
              "política completa", "autorización"),
    "terminos": ("Prestador y partes contratantes", "Objeto, funcionalidades",
                 "Usuarios habilitados", "facturación", "terminación",
                 "Disponibilidad/SLA", "confidencialidad", "Protección de datos",
                 "Ley aplicable", "Forma de aceptación"),
    "tratamiento": ("empresa cliente como responsable", "Condor como encargado",
                    "Objeto, duración", "Finalidades e instrucciones", "Tipos de datos",
                    "Subencargados", "Incidentes", "solicitudes de titulares",
                    "devolución", "auditoría", "Anexos obligatorios"),
}
REFERENCES = ("aviso-privacidad.md", "politica-tratamiento.md",
              "terminos-condiciones.md", "canal-derechos.md",
              "registro-tratamientos.md", "retencion.md")


def validate_template(raw: object) -> dict[str, str]:
    if not isinstance(raw, str) or not raw.strip():
        raise ValueError("UNKNOWN: missing template")
    if "NO APROBADO JURÍDICAMENTE" not in raw:
        raise ValueError("UNKNOWN: missing legal warning")
    if raw.count(COMPANY) < 14 or raw.count(LAWYER) < 20:
        raise ValueError("UNKNOWN: company or lawyer fields missing")
    positions = []
    for title in SECTIONS:
        if raw.count(title) != 1:
            raise ValueError("UNKNOWN: missing/duplicate document")
        positions.append(raw.index(title))
    if positions != sorted(positions) or len(set(positions)) != 3:
        raise ValueError("UNKNOWN: ambiguous document order")
    blocks = (raw[:positions[0]], raw[positions[0]:positions[1]],
              raw[positions[1]:positions[2]], raw[positions[2]:])
    for kind, block in zip(REQUIRED, blocks):
        for field in REQUIRED[kind]:
            if field.casefold() not in block.casefold():
                raise ValueError("UNKNOWN: missing " + kind + ": " + field)
    for block in blocks[1:]:
        if "ESTADO: BORRADOR" not in block or LAWYER not in block:
            raise ValueError("UNKNOWN: draft/legal gate missing")
    for ref in REFERENCES:
        if "(" + ref + ")" not in blocks[0]:
            raise ValueError("UNKNOWN: reference absent")
        if not (ROOT / "docs" / "privacidad" / ref).is_file():
            raise ValueError("UNKNOWN: reference file missing")
    return {name: "BORRADOR_PENDIENTE_ABOGADO" for name in
            ("aviso", "terminos", "tratamiento")}


class PlantillaEmpresaClienteTests(unittest.TestCase):
    def test_criterio_principal(self) -> None:
        """AC-01: three distinct documents, fields, placeholders and legal gates."""
        self.assertTrue(DOC.is_file())
        raw = DOC.read_text(encoding="utf-8")
        self.assertEqual(set(validate_template(raw)),
                         {"aviso", "terminos", "tratamiento"})
        self.assertIn("cada empresa cliente", raw.casefold())
        self.assertIn("No recoger datos personales reales", raw)
        self.assertIn("no acreditan aprobación", raw)

    def test_falla_cerrado_si_faltan_campos(self) -> None:
        raw = DOC.read_text(encoding="utf-8")
        for bad in (None, "", raw.replace(SECTIONS[1], "", 1),
                    raw + "\n" + SECTIONS[2],
                    raw.replace("empresa cliente como responsable", ""),
                    raw.replace("NO APROBADO JURÍDICAMENTE", "", 1)):
            with self.subTest(case=str(bad)[:35]):
                with self.assertRaisesRegex(ValueError, "UNKNOWN"):
                    validate_template(bad)

    def test_sin_datos_reales_y_sin_aprobacion_ficticia(self) -> None:
        raw = DOC.read_text(encoding="utf-8")
        self.assertNotRegex(raw, r"(?im)\b[A-Z0-9._%+-]+@[A-Z0-9.-]+\.[A-Z]{2,}\b")
        self.assertNotRegex(raw, r"\b\d{3}[- .]?\d{3}[- .]?\d{4}\b")
        self.assertNotRegex(raw, r"\b\d{9,12}\b")
        for phrase in ("aprobado jurídicamente", "autorizado por abogado",
                       "contrato celebrado"):
            self.assertNotRegex(raw, "(?i)^" + re.escape(phrase) + "$")
        self.assertIn("NO APROBADO JURÍDICAMENTE", raw)
        self.assertIn(LAWYER, raw)


if __name__ == "__main__":
    unittest.main()
