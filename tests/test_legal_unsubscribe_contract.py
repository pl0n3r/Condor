#!/usr/bin/env python3
"""AC-01 #789: contrato estático de enlaces de baja en email comercial.

Los mensajes transaccionales se exceptúan solo por ruta explícita, nunca
por palabras vagas que pudieran ocultar correos de marketing.
"""
from __future__ import annotations

import re
import unittest
from html.parser import HTMLParser
from pathlib import Path

ROOT = Path(__file__).resolve().parents[1]
TEMPLATES = ROOT / "templates"
POLICY = ROOT / "docs/legal/baja-comunicaciones.md"

# Exenciones PREVISTAS, no archivos afirmados como existentes. Si se crea otra
# plantilla transaccional deberá añadirse aquí con justificación explícita.
TRANSACTIONAL_EXEMPTIONS = {
    "emails/password_reset.html.twig": "Recuperación de contraseña solicitada",
    "emails/activation_invitation.html.twig": "Invitación / activación de cuenta",
}

POLICY_TERMS = (
    "BORRADOR NO APROBADO JURÍDICAMENTE",
    "comercial",
    "Cancelar suscripción",
    "sin iniciar sesión",
    "2 días hábiles",
    "POR DEFINIR POR EL DUEÑO",
    "Recuperación/restablecimiento de contraseña",
    "Invitaciones/activación de cuenta",
)


def mail_templates() -> list[Path]:
    return sorted(
        path for path in TEMPLATES.rglob("*.twig")
        if path.is_file()
        and any(word in path.relative_to(TEMPLATES).as_posix().lower()
                for word in ("email", "mail"))
    )


class _UnsubscribeLinkParser(HTMLParser):
    """Extrae enlaces reales; no toma HTML comentado por una plantilla."""

    def __init__(self) -> None:
        super().__init__(convert_charrefs=True)
        self.found = False
        self._anchor: dict[str, object] | None = None

    def handle_starttag(self, tag: str, attrs: list[tuple[str, str | None]]) -> None:
        if tag != "a":
            return
        attributes = {name.lower(): value or "" for name, value in attrs}
        style = attributes.get("style", "")
        hidden = (
            "hidden" in attributes
            or attributes.get("aria-hidden", "").lower() == "true"
            or re.search(r"(?:^|;)\s*(?:display\s*:\s*none|visibility\s*:\s*hidden)\b", style,
                         flags=re.IGNORECASE) is not None
        )
        self._anchor = {
            "href": attributes.get("href", ""),
            "label": [attributes.get("aria-label", "")],
            "hidden": hidden,
        }

    def handle_data(self, data: str) -> None:
        if self._anchor is not None:
            self._anchor["label"].append(data)

    def handle_endtag(self, tag: str) -> None:
        if tag != "a" or self._anchor is None:
            return
        anchor = self._anchor
        self._anchor = None
        href = str(anchor["href"]).strip()
        label = " ".join(anchor["label"])
        unsafe_href = (
            href.lower().startswith(("javascript:", "data:", "mailto:", "#"))
            or re.match(r"(?:https?://[^/]+)?/login(?:[/?#]|$)", href,
                        flags=re.IGNORECASE) is not None
        )
        if (
            not anchor["hidden"]
            and not unsafe_href
            and re.search(r"(?:unsubscribe|baja)", href, flags=re.IGNORECASE)
            and re.search(r"(?:unsubscribe|baja|suscripci[oó]n)", label,
                          flags=re.IGNORECASE)
        ):
            self.found = True


def has_unsubscribe_link(source: str) -> bool:
    parser = _UnsubscribeLinkParser()
    parser.feed(source)
    parser.close()
    return parser.found


class LegalBajaComunicacionesTests(unittest.TestCase):
    def test_acceptance(self) -> None:
        self.assertTrue(POLICY.is_file(), f"Falta borrador: {POLICY}")
        self.assertTrue(TEMPLATES.is_dir(), "Falta directorio templates/")
        document = POLICY.read_text(encoding="utf-8")
        for requirement in POLICY_TERMS:
            with self.subTest(politica=requirement):
                self.assertIn(requirement.lower(), document.lower())

        # Las exenciones transaccionales quedan nombradas expresamente;
        # no se asume que esos ejemplos estén desplegados.
        self.assertEqual(2, len(TRANSACTIONAL_EXEMPTIONS))
        for path, reason in TRANSACTIONAL_EXEMPTIONS.items():
            with self.subTest(exencion=path):
                self.assertTrue(reason.strip())
                self.assertTrue(path.endswith(".html.twig"))

        for path in mail_templates():
            relative = path.relative_to(TEMPLATES).as_posix()
            with self.subTest(template=relative):
                if relative in TRANSACTIONAL_EXEMPTIONS:
                    continue
                self.assertTrue(
                    has_unsubscribe_link(path.read_text(encoding="utf-8")),
                    f"{relative}: todo correo comercial necesita enlace de baja "
                    "visible sin inicio de sesión; no se permite excepción implícita",
                )

    def test_rechaza_marketing_sin_enlace(self) -> None:
        self.assertFalse(has_unsubscribe_link(
            '<p>Oferta del mes</p><a href="/login">Ingresa</a>'
        ))
        self.assertFalse(has_unsubscribe_link(
            '<p>Campaña</p><span>Para cancelar, contáctenos</span>'
        ))

    def test_admite_enlace_explicito_sin_login(self) -> None:
        self.assertTrue(has_unsubscribe_link(
            '<p>Novedades</p>'
            '<a href="{{ unsubscribe_url }}">Cancelar suscripción</a>'
        ))

    def test_rechaza_enlace_sin_nombre_visible_u_oculto(self) -> None:
        self.assertFalse(has_unsubscribe_link(
            '<a href="/unsubscribe">Ir al inicio</a>'
        ))
        self.assertFalse(has_unsubscribe_link(
            '<a href="/unsubscribe" style="display:none">Cancelar suscripción</a>'
        ))
        self.assertFalse(has_unsubscribe_link(
            '<a href="/unsubscribe" aria-hidden="true">Dar de baja</a>'
        ))
        self.assertFalse(has_unsubscribe_link(
            '<!-- <a href="/unsubscribe">Cancelar suscripción</a> -->'
        ))
        self.assertFalse(has_unsubscribe_link(
            '<a href="/unsubscribe">Más información</a>'
        ))
        self.assertFalse(has_unsubscribe_link(
            '<a href="/login?next=/unsubscribe">Cancelar suscripción</a>'
        ))
        self.assertFalse(has_unsubscribe_link(
            '<a href="javascript:go(\'/unsubscribe\')">Cancelar suscripción</a>'
        ))

    def test_admite_nombre_accesible_y_contenido_anidado(self) -> None:
        self.assertTrue(has_unsubscribe_link(
            '<a href="/unsubscribe" aria-label="Dar de baja"><svg></svg></a>'
        ))
        self.assertTrue(has_unsubscribe_link(
            '<a href="/unsubscribe"><span>Cancelar suscripción</span></a>'
        ))


if __name__ == "__main__":
    unittest.main()
