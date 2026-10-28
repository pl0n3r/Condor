#!/usr/bin/env python3
"""Regresiones offline de Condor #1205 (D-040-S19..D-041-S03).

La evidencia local es parcial. Ni un test sintético ni el CI acreditan
concurrencia E2E integral, seguridad de todos los slices, experiencia real,
permisos efectivos, producción o go-live. La matriz NO_VERIFICABLE no cambia.
"""
from __future__ import annotations

import re
import unittest
from pathlib import Path

ROOT = Path(__file__).resolve().parents[1]
MATRIX = ROOT / "docs/matriz-cobertura.md"
SPEC = ROOT / "ESPECIFICACIONES.md"
SOURCES = {
    "billing": ROOT / "tests/test_billing_idempotency.py",
    "security": ROOT / "tests/php/Http/SecurityHttpSessionNegativesTest.php",
    "ci": ROOT / ".github/workflows/ci.yml",
    "css": ROOT / "public/app.css",
    "shell": ROOT / "frontend/admin/AdminShell.tsx",
}
GAPS = {
    "D-040-S19": "19. Concurrencia, idempotencia y operaciones sensibles",
    "D-040-S20": "20. Pruebas y gate de seguridad",
    "D-041": "Baseline de sistema de diseño y experiencia (Fase 5)",
    "D-041-S02": "Tokens y escalas",
    "D-041-S03": "Densidad y superficies",
}
SPEC_TITLES = {
    "D-040-S19": "#### 19. Concurrencia, idempotencia y operaciones sensibles",
    "D-040-S20": "#### 20. Pruebas y gate de seguridad",
    "D-041": "### D-041 — Baseline de sistema de diseño y experiencia",
    "D-041-S02": "#### Tokens y escalas",
    "D-041-S03": "#### Densidad y superficies",
}
UNKNOWN = "UNKNOWN"
LOCAL_PARTIAL = "LOCAL_PARTIAL"


def coverage_rows(document: str) -> dict[str, tuple[str, ...]]:
    """Rechaza IDs duplicados, filas incompletas y columnas corruptas."""
    rows: dict[str, tuple[str, ...]] = {}
    for line in document.splitlines():
        match = re.match(
            r"^\|\s*\x60(D-040-S19|D-040-S20|D-041|D-041-S02|D-041-S03)\x60(?=\s|\W)",
            line,
        )
        if not match:
            continue
        key = match.group(1)
        columns = tuple(cell.strip() for cell in line.strip().strip("|").split("|"))
        expected = chr(96) + key + chr(96) + " · " + chr(96) + "ESPECIFICACIONES.md" + chr(96)
        if (key in rows or len(columns) != 5 or not all(columns)
                or columns[0] != expected or columns[1] != GAPS[key]):
            raise ValueError("UNKNOWN: malformed or duplicate " + key)
        rows[key] = columns
    if set(rows) != set(GAPS):
        raise ValueError("UNKNOWN: incomplete coverage inventory")
    return rows


def local_evidence(source: dict[str, str]) -> dict[str, str]:
    """Comprueba solo evidencia de código local, nunca cobertura integral."""
    if (set(source) != set(SOURCES) or
            any(not isinstance(value, str) or not value.strip()
                for value in source.values())):
        return {key: UNKNOWN for key in GAPS}

    billing, security, ci = source["billing"], source["security"], source["ci"]
    css, shell = source["css"], source["shell"]
    token_pairs = dict(re.findall(r"(--space-\d+)\s*:\s*(\d+)px\s*;", css))
    expected_spaces = {"--space-1": "4", "--space-2": "8",
                       "--space-3": "12", "--space-4": "16",
                       "--space-6": "24", "--space-8": "32"}
    checks = {
        "D-040-S19": all(token in billing for token in (
            "test_same_key_and_same_request_replays_recorded_result_without_second_adapter_submit",
            "test_same_key_with_changed_payload_or_unknown_record_fails_closed_without_double_submit",
            'self.assertEqual(1, observed["submits"])',
            'self.assertEqual(observed["first"], observed["second"])',
            '"billing_reconciliation_required"',
            '"billing_idempotency_conflict"',
        )),
        "D-040-S20": all(token in security for token in (
            "testExpiredSessionsAndInvalidCsrfOnMutatingRoutes",
            "self::assertResponseStatusCodeSame(403)",
            "self::assertResponseRedirects('/admin/login')",
            "new Tenant(", "new Membership(",
        )) and "run: vendor/bin/simple-phpunit" in ci,
        "D-041": all(token in css for token in (
            "--surface:", "--text:", "--border:", "--accent:", "--focus:",
        )) and "export function AdminShell(" in shell,
        "D-041-S02": all(token_pairs.get(name) == value
                             for name, value in expected_spaces.items())
                     and "--radius: 8px;" in css,
        "D-041-S03": all(token in css for token in (
            ".admin-shell {", "grid-template-columns: 240px 1fr;",
            ".sidebar a {", "@media (max-width: 860px)",
        )) and "navItems.map((item)" in shell,
    }
    return {key: LOCAL_PARTIAL if passed else UNKNOWN
            for key, passed in checks.items()}


def integral_coverage(*, clause: str, local: object,
                      e2e: object = None, production: object = None) -> str:
    """Sin prueba integral confiable, la salida siempre falla cerrada."""
    if clause not in GAPS:
        return UNKNOWN
    # Este issue no aporta atestación E2E/live de todos los componentes.
    # Ni diccionarios suministrados por llamadores ni el CI son pruebas reales.
    return UNKNOWN


class BrechasCondor10Tests(unittest.TestCase):
    @classmethod
    def setUpClass(cls) -> None:
        cls.document = MATRIX.read_text(encoding="utf-8")
        cls.rows = coverage_rows(cls.document)
        cls.spec = SPEC.read_text(encoding="utf-8")
        cls.sources = {name: path.read_text(encoding="utf-8")
                       for name, path in SOURCES.items()}
        cls.local = local_evidence(cls.sources)

    def test_contrato_principal(self) -> None:
        """AC-01: las cinco filas tienen una aserción y límite explícito."""
        self.assertEqual(set(self.rows), set(GAPS))
        for key, title in GAPS.items():
            with self.subTest(clause=key):
                columns = self.rows[key]
                self.assertEqual(columns[1], title)
                self.assertEqual(columns[2], chr(96) + "NO_VERIFICABLE" + chr(96))
                self.assertIn("Falta evidencia integral " + key, columns[3])
                self.assertIn(SPEC_TITLES[key], self.spec)
                self.assertEqual(self.local[key], LOCAL_PARTIAL)
                self.assertEqual(integral_coverage(clause=key, local=self.local[key]), UNKNOWN)

    def test_falla_cerrado(self) -> None:
        """AC-02: entradas ausentes, duplicadas y evidencia corrupta no pasan."""
        row = next(line for line in self.document.splitlines()
                   if line.startswith("| " + chr(96) + "D-040-S19" + chr(96)))
        for corrupt in (
            self.document.replace(row, ""),
            self.document + "\n" + row,
            self.document.replace(row, "| " + chr(96) + "D-040-S19" + chr(96) + " | corrupta |"),
        ):
            with self.subTest(corrupt_size=len(corrupt)):
                with self.assertRaisesRegex(ValueError, "UNKNOWN"):
                    coverage_rows(corrupt)
        for field in SOURCES:
            with self.subTest(missing=field):
                partial = dict(self.sources)
                partial.pop(field)
                self.assertEqual(set(local_evidence(partial).values()), {UNKNOWN})
        altered = dict(self.sources)
        altered["billing"] = altered["billing"].replace(
            'self.assertEqual(1, observed["submits"])', "")
        self.assertEqual(local_evidence(altered)["D-040-S19"], UNKNOWN)
        altered = dict(self.sources)
        altered["security"] = altered["security"].replace(
            "self::assertResponseStatusCodeSame(403)", "")
        self.assertEqual(local_evidence(altered)["D-040-S20"], UNKNOWN)
        altered = dict(self.sources)
        altered["css"] = altered["css"].replace("--space-1: 4px;", "--space-1: 5px;")
        self.assertEqual(local_evidence(altered)["D-041-S02"], UNKNOWN)
        altered = dict(self.sources)
        altered["css"] = altered["css"].replace("--surface:", "")
        self.assertEqual(local_evidence(altered)["D-041"], UNKNOWN)
        altered = dict(self.sources)
        altered["shell"] = altered["shell"].replace("navItems.map((item)", "")
        self.assertEqual(local_evidence(altered)["D-041-S03"], UNKNOWN)

    def test_sin_datos_reales(self) -> None:
        """AC-03: fuentes fijas, fixtures ficticios, sin red ni credenciales."""
        self.assertEqual(set(self.sources), set(SOURCES))
        self.assertTrue(all(path.is_file() for path in (MATRIX, SPEC, *SOURCES.values())))
        synthetic = {"tenant": "tenant:synthetic-only", "amount_minor": 1200,
                     "idempotency_key": "synthetic:replay:001", "roles": ["guest"]}
        self.assertIn("synthetic", synthetic["tenant"])
        self.assertNotIn("@", synthetic["tenant"])
        self.assertGreater(synthetic["amount_minor"], 0)
        self.assertEqual(integral_coverage(clause="D-040-S19", local=synthetic), UNKNOWN)

    def test_estados_unknown_explicitos(self) -> None:
        """AC-04: evidencia parcial o declarada nunca fabrica aceptación global."""
        for key in GAPS:
            with self.subTest(clause=key):
                self.assertEqual(self.rows[key][2], chr(96) + "NO_VERIFICABLE" + chr(96))
                for forged in (None, "", "passed", {"ci": "success"},
                               {"review": "APPROVED"}, {"live": True},
                               {"all_slices": True}, {"e2e": "success"}):
                    self.assertEqual(
                        integral_coverage(clause=key, local=self.local[key],
                                          e2e=forged, production=forged), UNKNOWN)
        self.assertEqual(integral_coverage(clause="D-999", local=LOCAL_PARTIAL), UNKNOWN)


if __name__ == "__main__":
    unittest.main()
