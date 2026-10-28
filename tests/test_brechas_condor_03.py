#!/usr/bin/env python3
"""Contratos offline de Condor #1198: brechas AGENTES-05-04..06-02.

Las comprobaciones del repositorio son evidencia LOCAL_PARTIAL, no una
atestación de integración de todos los módulos, E2E de interfaz, auditoría
exhaustiva, historial de releases ni versión desplegada en producción.
"""
from __future__ import annotations

import re
import unittest
from pathlib import Path

ROOT = Path(__file__).resolve().parents[1]
MATRIX = ROOT / "docs/matriz-cobertura.md"
SOURCES = {
    "agents": ROOT / "AGENTES.md",
    "ci": ROOT / ".github/workflows/ci.yml",
    "version": ROOT / "config/version.php",
}
GAPS = {
    "AGENTES-05-04": "5. CI: Factory común + extensión Condor — contratos e integración;",
    "AGENTES-05-05": "5. CI: Factory común + extensión Condor — TypeScript/build;",
    "AGENTES-05-06": "5. CI: Factory común + extensión Condor — auditorías npm/Composer;",
    "AGENTES-06-01": "6. Versionado y entrega — cada deploy productivo identificable usa versión humana;",
    "AGENTES-06-02": "6. Versionado y entrega — incremento normal pre-1.0: patch;",
}
UNKNOWN = "UNKNOWN"
LOCAL_PARTIAL = "LOCAL_PARTIAL"
VERSION_PATTERN = re.compile(r"['\"]version['\"]\s*=>\s*['\"](\d+)\.(\d+)\.(\d+)['\"]")


def coverage_rows(document: str) -> dict[str, tuple[str, ...]]:
    """Rechaza silencios, duplicados y falsas filas de cobertura."""
    rows: dict[str, tuple[str, ...]] = {}
    for line in document.splitlines():
        match = re.match(r"^\|\s*\x60(AGENTES-(?:05-0[456]|06-0[12]))\x60\s*·", line)
        if not match:
            continue
        key = match.group(1)
        columns = tuple(part.strip() for part in line.strip().strip("|").split("|"))
        expected = "\x60" + key + "\x60 · \x60AGENTES.md\x60"
        if (key in rows or len(columns) != 5 or not all(columns)
                or columns[0] != expected or columns[1] != GAPS[key]):
            raise ValueError("UNKNOWN: fila corrupta o repetida: " + key)
        rows[key] = columns
    if set(rows) != set(GAPS):
        raise ValueError("UNKNOWN: faltan brechas AGENTES")
    return rows


def version_triplet(text: str) -> tuple[int, int, int] | None:
    """Solo analiza la versión humana local; nunca infiere un deploy."""
    matches = VERSION_PATTERN.findall(text)
    if len(matches) != 1:
        return None
    numbers = tuple(int(value) for value in matches[0])
    return numbers if len(numbers) == 3 else None


def patch_transition(before: object, after: object) -> str:
    """Caso sintético: patch pre-1.0. No acredita historial de releases."""
    if (not isinstance(before, tuple) or not isinstance(after, tuple)
            or len(before) != 3 or len(after) != 3
            or any(type(value) is not int or value < 0
                   for value in (*before, *after))):
        return UNKNOWN
    if before[0] != 0 or after[0] != 0:
        return UNKNOWN
    return (LOCAL_PARTIAL if (after[0] == before[0]
                              and after[1] == before[1]
                              and after[2] == before[2] + 1)
            else UNKNOWN)


def local_evidence(source: dict[str, str]) -> dict[str, str]:
    """Prueba presencia de controles locales; lo ausente es UNKNOWN."""
    if (set(source) != set(SOURCES)
            or any(not isinstance(value, str) or not value.strip()
                   for value in source.values())):
        return {key: UNKNOWN for key in GAPS}
    agents, ci, version_text = source["agents"], source["ci"], source["version"]
    parsed = version_triplet(version_text)
    evidence = {
        "AGENTES-05-04": all(token in ci for token in (
            "name: Pruebas de contrato e integración",
            "python3 -m unittest discover -s tests/integration",
            "vendor/bin/simple-phpunit",
        )),
        "AGENTES-05-05": all(token in ci for token in (
            "name: Frontend TypeScript / build",
            "npm run typecheck",
            "npm run build:admin",
            "npm run test:e2e",
        )),
        "AGENTES-05-06": all(token in ci for token in (
            "name: Auditoría de dependencias npm",
            "name: Auditoría de dependencias Composer",
            "npm audit --audit-level=high",
            "composer audit --locked --no-interaction",
        )),
        "AGENTES-06-01": (
            parsed is not None and "config/version.php" in agents
            and "cada deploy productivo identificable usa versión humana" in agents
            and "versión y SHA son identidades complementarias" in agents
        ),
        "AGENTES-06-02": (
            parsed is not None and parsed[0] == 0
            and "incremento normal pre-1.0: patch" in agents
            and patch_transition(parsed, (parsed[0], parsed[1], parsed[2] + 1))
                == LOCAL_PARTIAL
        ),
    }
    return {key: LOCAL_PARTIAL if present else UNKNOWN
            for key, present in evidence.items()}


def integral_coverage(*, clause: str, local: object,
                      tests: object = None, release: object = None) -> str:
    """Afirmaciones auto-declaradas no suplantan pruebas E2E/live externas."""
    if clause not in GAPS:
        return UNKNOWN
    return UNKNOWN


class BrechasCondor03Tests(unittest.TestCase):
    @classmethod
    def setUpClass(cls) -> None:
        cls.document = MATRIX.read_text(encoding="utf-8")
        cls.rows = coverage_rows(cls.document)
        cls.sources = {key: path.read_text(encoding="utf-8")
                       for key, path in SOURCES.items()}
        cls.local = local_evidence(cls.sources)

    def test_contrato_principal(self) -> None:
        """AC-01: las cinco filas quedan ejercitadas sin declarar cobertura total."""
        self.assertEqual(set(self.rows), set(GAPS))
        for key, title in GAPS.items():
            with self.subTest(clause=key):
                row = self.rows[key]
                self.assertEqual(row[1], title)
                self.assertEqual(row[2], "\x60NO_VERIFICABLE\x60")
                self.assertIn("Falta evidencia integral", row[3])
                self.assertIn(key, row[3])
                self.assertEqual(self.local[key], LOCAL_PARTIAL)
                self.assertEqual(integral_coverage(clause=key, local=self.local[key]),
                                 UNKNOWN)

    def test_falla_cerrado(self) -> None:
        """AC-02: rechaza inventario incompleto, evidencia rota y falsa versión."""
        original = next(line for line in self.document.splitlines()
                        if line.startswith("| \x60AGENTES-05-04\x60"))
        for broken in (self.document.replace(original, ""),
                       self.document + "\n" + original,
                       self.document.replace(original,
                           "| \x60AGENTES-05-04\x60 | fila corrupta |")):
            with self.subTest(kind=len(broken)):
                with self.assertRaisesRegex(ValueError, "UNKNOWN"):
                    coverage_rows(broken)
        for key in SOURCES:
            with self.subTest(missing_source=key):
                altered = dict(self.sources)
                altered.pop(key)
                self.assertEqual(set(local_evidence(altered).values()), {UNKNOWN})
        mutations = {
            "AGENTES-05-04": ("ci", "vendor/bin/simple-phpunit"),
            "AGENTES-05-05": ("ci", "npm run build:admin"),
            "AGENTES-05-06": ("ci", "composer audit --locked --no-interaction"),
            "AGENTES-06-01": ("agents", "versión y SHA son identidades complementarias"),
            "AGENTES-06-02": ("agents", "incremento normal pre-1.0: patch"),
        }
        for clause, (source_key, token) in mutations.items():
            with self.subTest(clause=clause):
                altered = dict(self.sources)
                self.assertIn(token, altered[source_key])
                altered[source_key] = altered[source_key].replace(token, "", 1)
                self.assertEqual(local_evidence(altered)[clause], UNKNOWN)
        versionless = dict(self.sources)
        versionless["version"] = "<?php return ['version' => 'UNKNOWN'];"
        self.assertEqual(local_evidence(versionless)["AGENTES-06-01"], UNKNOWN)
        self.assertEqual(local_evidence(versionless)["AGENTES-06-02"], UNKNOWN)
        for before, after in (
            ((0, 1, 1), (0, 1, 1)),
            ((0, 1, 1), (0, 2, 0)),
            ((0, 1, 1), (1, 0, 0)),
            ((0, 1, 1), (0, 1, 3)),
            ((0, 1, 1), ("0", 1, 2)),
        ):
            self.assertEqual(patch_transition(before, after), UNKNOWN)
        self.assertEqual(patch_transition((0, 1, 1), (0, 1, 2)),
                         LOCAL_PARTIAL)

    def test_sin_datos_reales(self) -> None:
        """AC-03: todo permanece en archivos públicos y fixtures sintéticos."""
        self.assertTrue(all(path.is_file() for path in (MATRIX, *SOURCES.values())))
        fixture = {"deployment_id": "synthetic:release:001",
                   "version_before": (0, 1, 4),
                   "version_after": (0, 1, 5),
                   "ui": "synthetic:offline-only"}
        self.assertIn("synthetic", fixture["deployment_id"])
        self.assertNotIn("@", fixture["deployment_id"])
        self.assertEqual(patch_transition(fixture["version_before"],
                                          fixture["version_after"]), LOCAL_PARTIAL)
        self.assertEqual(integral_coverage(clause="AGENTES-06-01",
                                           local=fixture), UNKNOWN)

    def test_estados_unknown_explicitos(self) -> None:
        """AC-04: CI verde declarado y claims de deploy nunca vuelven CUBIERTO."""
        for key in GAPS:
            with self.subTest(clause=key):
                self.assertEqual(self.rows[key][2], "\x60NO_VERIFICABLE\x60")
                for forged in (None, "", "success", {"ci": "success"},
                               {"ui": "passed"}, {"audit": "passed"},
                               {"live": True}, {"release": "0.1.195"},
                               {"e2e": "passed"}):
                    self.assertEqual(integral_coverage(
                        clause=key, local=self.local[key], tests=forged,
                        release=forged), UNKNOWN)
        self.assertEqual(integral_coverage(clause="AGENTES-99-99",
                                           local=LOCAL_PARTIAL), UNKNOWN)


if __name__ == "__main__":
    unittest.main()
