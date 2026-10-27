"""AC-01..AC-04 del issue #985: runbook offline de soporte empresarial."""
from __future__ import annotations

import re
import unittest
from pathlib import Path

ROOT = Path(__file__).resolve().parents[1]
DOC = ROOT / "docs" / "salida-soporte-operacion.md"

# Solo se inspecciona el documento; las cadenas sintéticas de prueba no se escriben allí.
SENSITIVE = (
    re.compile(r"-----BEGIN [A-Z ]*PRIVATE KEY-----", re.IGNORECASE),
    re.compile(r"ghp_[A-Za-z0-9]{20,}"),
    re.compile(r"github_pat_[A-Za-z0-9_]{20,}"),
    re.compile(r"AKIA[0-9A-Z]{16}"),
    re.compile(r"(?i)(?:password|api[_-]?key|secret|token)\s*[:=]\s*['\"]?[A-Za-z0-9+/]{18,}"),
    re.compile(r"\b[A-Za-z0-9._%+-]+@[A-Za-z0-9.-]+\.[A-Za-z]{2,}\b"),
    re.compile(r"(?<!\d)\+57[-\s]?[0-9]{10}(?!\d)"),
)


class SalidaTests(unittest.TestCase):
    @classmethod
    def setUpClass(cls) -> None:
        cls.text = DOC.read_text(encoding="utf-8")

    def test_documento_cubre_el_alcance(self) -> None:
        headings = (
            "Propósito, alcance y límites", "Canales, horarios y guardia",
            "Responsables y autoridad", "Severidades y objetivos de primera respuesta",
            "Procedimiento: recepción, triaje, diagnóstico y escalamiento",
            "Plantillas de comunicación", "Registro de evidencias",
            "Rutina diaria y ensayo sintético", "Puertas del OWNER",
        )
        for title in headings:
            with self.subTest(section=title):
                self.assertIn(title, self.text)
        for code in ("S1", "S2", "S3", "S4"):
            with self.subTest(severity=code):
                self.assertRegex(self.text, rf"(?m)^\| \x60{code}\x60 ")
        for target in ("30 min", "2 h hábiles", "1 día hábil", "3 días hábiles"):
            with self.subTest(target=target):
                self.assertIn(target, self.text)
        for topic in ("America/Bogota", "horario", "escalamiento",
                      "acuse de recibo", "cierre", "guía", "handoff",
                      "responsable", "SRE", "seguridad", "soporte",
                      "/ayuda", "/admin/ayuda", "/faq"):
            with self.subTest(topic=topic):
                self.assertIn(topic.casefold(), self.text.casefold())
        self.assertIn("propuestas no activadas", self.text)
        self.assertIn("no representan", self.text.lower().replace("no representa", "no representan"))

    def test_sin_secretos_ni_datos_reales(self) -> None:
        for pattern in SENSITIVE:
            with self.subTest(pattern=pattern.pattern):
                self.assertIsNone(pattern.search(self.text), "Dato sensible en runbook")
        self.assertNotIn("https://github.com/", self.text, "Evitar links con payloads públicos")
        for forbidden in ("customer_id", "tenant_id", "ip_address", "phone_number"):
            self.assertNotIn(f"\x60{forbidden}\x60", self.text)
        self.assertIn("No almacenar transcripciones", self.text)
        self.assertIn("solo", self.text.lower())
        # Las detecciones no deben quedar deshabilitadas por regex que nunca coincide.
        samples = ("ghp_" + "A" * 32, "usuario" + "@" + "example.invalid",
                   "-----BEGIN " + "PRIVATE KEY-----")
        for synthetic in samples:
            with self.subTest(synthetic_type=synthetic[:4]):
                self.assertTrue(any(p.search(synthetic) for p in SENSITIVE))

    def test_marca_decisiones_del_dueno(self) -> None:
        self.assertGreaterEqual(self.text.count("PENDIENTE_OWNER"), 8)
        for subject in ("canal y cuenta", "titulares", "horario y SLA",
                        "presupuesto y proveedores", "privacidad y legal",
                        "activación"):
            with self.subTest(subject=subject):
                self.assertIn("PENDIENTE_OWNER — " + subject, self.text)
        for forbidden_assertion in ("soporte ya activado", "SLA vigente garantizado",
                                    "clientes reales atendidos"):
            self.assertNotIn(forbidden_assertion, self.text.lower())
        self.assertIn("única autoridad", self.text)
        self.assertIn("no existe ahora mesa de ayuda", self.text)

    def test_evidencia_cierre_y_sin_go_live(self) -> None:
        for field in ("incident_ref", "severity", "observed_at_utc", "updated_at_utc",
                      "release_sha", "closure_receipt", "owner_role", "next_action"):
            with self.subTest(field=field):
                self.assertIn(f"\x60{field}\x60", self.text)
        for state in ("UNKNOWN", "HEALTHY", "DEGRADED", "TRANSIENT",
                      "DOCUMENTED_FOR_REVIEW", "VALIDATED_IN_PRODUCTION"):
            self.assertIn(state, self.text)
        self.assertIn("nunca \x60VALIDATED_IN_PRODUCTION\x60", self.text)
        self.assertIn("No alterar producción", self.text)
        self.assertIn("no soporte activado", self.text)
        self.assertIn("cierre del incidente", self.text.lower())
        self.assertIn("no", self.text.lower())


if __name__ == "__main__":
    unittest.main()
