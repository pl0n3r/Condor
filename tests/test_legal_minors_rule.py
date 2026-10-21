#!/usr/bin/env python3
"""Condor #790: guard contractual para datos de menores en superficies públicas.

Inspección estática de plantillas Twig; no consulta personas, bases de datos,
ni producción. La validación jurídica permanece pendiente.
"""
from __future__ import annotations

import html
import re
import unicodedata
import unittest
from pathlib import Path

ROOT = Path(__file__).resolve().parents[1]
POLICY = ROOT / "docs/legal/regla-menores.md"
TEMPLATES = ROOT / "templates"
PUBLIC_DIRECTORIES = (
    "home", "marketing", "configurator", "pricing", "security", "cms"
)
PUBLIC_FILES = ("knowledge/help.html.twig",)
FIELDS = re.compile(
    r"<(input|select|textarea)\b[^>]*>", flags=re.IGNORECASE | re.DOTALL
)
LABELS = re.compile(
    r"<label\b[^>]*>(.*?)</label>", flags=re.IGNORECASE | re.DOTALL
)
ATTRS = re.compile(
    r'\b(name|id|placeholder|aria-label|autocomplete|data-field)'
    r'\s*=\s*(["\'])(.*?)\2', flags=re.IGNORECASE | re.DOTALL
)
AGE_LABEL = re.compile(
    r"\b(?:edad|age|dob)\b|"
    r"\bfecha\s+(?:de\s+)?nacimiento\b|"
    r"\bdate\s+of\s+birth\b|"
    r"\ba[nñ]o\s+de\s+nacimiento\b", flags=re.IGNORECASE
)
POLICY_RULES = {
    "destinatarios": r"no\s+est[aá]\s+dirigida\s+a\s+menores\s+de\s+18\s+a[nñ]os",
    "minimizacion": r"no\s+se\s+recolectar[aá]n\s+intencionalmente\s+datos",
    "supresion": r"si\s+se\s+detectan\s+datos[^.\n]*menores[^.\n]*se\s+eliminar[aá]n",
    "ley": r"ley\s+1581\s+de\s+2012",
    "decreto": r"decreto\s+1377\s+de\s+2013",
    "decreto_unico": r"decreto\s+1074\s+de\s+2015",
    "borrador": r"borrador\s+no\s+aprobado\s+jur[ií]dicamente",
}


def public_templates() -> list[Path]:
    paths = [
        file
        for directory in PUBLIC_DIRECTORIES
        for file in (TEMPLATES / directory).rglob("*.twig")
        if file.is_file()
    ]
    paths += [
        TEMPLATES / relative
        for relative in PUBLIC_FILES
        if (TEMPLATES / relative).is_file()
    ]
    return sorted(set(paths))


def normalized(value: str) -> str:
    ascii_value = unicodedata.normalize("NFKD", html.unescape(value))
    return "".join(char for char in ascii_value if not unicodedata.combining(char))


def asks_birth_or_age(value: str) -> bool:
    # Detectar campos compuestos sin confundir "page", "message", etc. con age.
    value = normalized(value)
    words = re.sub(r"(?<=[a-z])(?=[A-Z])", "_", value)
    words = re.split(r"[^a-zA-Z0-9]+", words.lower())
    tokens = set(words) - {""}
    collapsed = "".join(words)
    return (
        bool(tokens & {"edad", "age", "dob", "birthdate"})
        or ("birth" in tokens and ("date" in tokens or "year" in tokens))
        or ("fecha" in tokens and "nacimiento" in tokens)
        or any(term in collapsed for term in (
            "fechanacimiento", "birthdate", "dateofbirth", "anonacimiento"
        ))
    )


def disallowed_fields(template: str) -> list[str]:
    found: list[str] = []
    for tag in FIELDS.finditer(template):
        for match in ATTRS.finditer(tag.group(0)):
            key, value = match.group(1), match.group(3)
            if asks_birth_or_age(value):
                found.append(f"{tag.group(1)}[{key}={value[:90]}]")
    for match in LABELS.finditer(template):
        # Solo texto visible de la etiqueta (no nombres CSS o referencias Twig).
        visible = re.sub(r"<[^>]+>", " ", match.group(1))
        visible = normalized(html.unescape(visible))
        if AGE_LABEL.search(visible):
            found.append("label: " + " ".join(visible.split())[:100])
    return found


class LegalMenoresTests(unittest.TestCase):
    def test_acceptance(self) -> None:
        self.assertTrue(POLICY.is_file(), f"Falta borrador {POLICY}")
        self.assertTrue(TEMPLATES.is_dir(), "Falta templates/")
        document = POLICY.read_text(encoding="utf-8")
        for requirement, expression in POLICY_RULES.items():
            with self.subTest(regla=requirement):
                self.assertRegex(document.lower(), expression)

        pages = public_templates()
        self.assertGreaterEqual(
            len(pages), 10, "Cobertura de plantillas públicas inesperadamente vacía"
        )
        for page in pages:
            with self.subTest(template=page.relative_to(ROOT).as_posix()):
                hits = disallowed_fields(page.read_text(encoding="utf-8"))
                self.assertEqual(
                    [], hits,
                    f"{page.relative_to(ROOT)} solicita edad o fecha de nacimiento",
                )

    def test_rechaza_edad_fecha_nacimiento_y_dob(self) -> None:
        cases = (
            '<form><input name="edad" type="number"></form>',
            '<input name="birthDate" type="date">',
            '<input id="date_of_birth" type="date">',
            '<select name="fecha_nacimiento"></select>',
            '<label for="dato">¿Cuál es tu edad?</label><input id="dato">',
            '<textarea aria-label="Fecha de nacimiento"></textarea>',
            '<input name="dob" type="text">',
        )
        for sample in cases:
            with self.subTest(sample=sample):
                self.assertTrue(disallowed_fields(sample))

    def test_no_confunde_campos_neutros(self) -> None:
        sample = (
            '<form><input name="page" value="1">'
            '<input name="message" placeholder="Escribe tu mensaje">'
            '<input type="email" name="email">'
            '<label for="company">Empresa</label>'
            '<input id="company" name="company"></form>'
        )
        self.assertEqual([], disallowed_fields(sample))


if __name__ == "__main__":
    unittest.main()
