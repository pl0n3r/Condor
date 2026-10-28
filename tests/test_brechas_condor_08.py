#!/usr/bin/env python3
"""Condor #1203: offline gaps D-040-S07..S11, without production claims.

Matrix evidence and synthetic adversarial cases do not establish end-to-end
security, operational rotation, audit retention, or recoverable live backups.
All five integral verdicts remain UNKNOWN pending independent evidence.
"""
from __future__ import annotations

import re
import unittest
from pathlib import Path

ROOT = Path(__file__).resolve().parents[1]
MATRIX = ROOT / "docs/matriz-cobertura.md"
GITIGNORE = ROOT / ".gitignore"

GAPS = {
    "D-040-S07": (
        "7. Secretos y variables de entorno",
        ("análisis real", "rotación", "logs/artifacts"),
    ),
    "D-040-S08": (
        "8. Headers de seguridad",
        ("CSP", "HSTS", "rutas públicas/privadas"),
    ),
    "D-040-S09": (
        "9. Logging seguro y manejo de errores",
        ("PII", "cookies", "tokens", "correlación"),
    ),
    "D-040-S10": (
        "10. Auditoría administrativa",
        ("roles", "precios", "inventarios", "retención"),
    ),
    "D-040-S11": (
        "11. Backups y restauración verificable",
        ("DB/media", "cifrado", "30 días", "restauración"),
    ),
}
UNKNOWN = "UNKNOWN"
LOCAL_PARTIAL = "LOCAL_PARTIAL"


def coverage_rows(document: str) -> dict[str, tuple[str, ...]]:
    """Fail closed on absent, duplicated, broken or contradictory matrix rows."""
    if not isinstance(document, str):
        raise ValueError("UNKNOWN: unreadable coverage matrix")
    rows: dict[str, tuple[str, ...]] = {}
    pattern = re.compile(r"^\|\s*`(D-040-S(?:07|08|09|10|11))`(?=\s|\|)")
    for line in document.splitlines():
        match = pattern.match(line)
        if match is None:
            continue
        key = match.group(1)
        cells = tuple(value.strip() for value in line.strip().strip("|").split("|"))
        expected = f"`{key}` · `ESPECIFICACIONES.md`"
        if (key in rows or len(cells) != 5 or not all(cells)
                or cells[0] != expected or cells[1] != GAPS[key][0]
                or cells[2] != "NO_VERIFICABLE"
                or key not in cells[3]):
            raise ValueError("UNKNOWN: malformed, conflicting or duplicate " + key)
        rows[key] = cells
    if set(rows) != set(GAPS):
        raise ValueError("UNKNOWN: D-040-S07..S11 inventory incomplete")
    return rows


def local_secret_exclusion(gitignore: object) -> str:
    """A .gitignore rule is only local preventive evidence, not a secret scan."""
    if not isinstance(gitignore, str):
        return UNKNOWN
    lines = {line.strip() for line in gitignore.splitlines()
             if line.strip() and not line.lstrip().startswith("#")}
    if {".env", ".env.*", "*.log"} <= lines:
        return LOCAL_PARTIAL
    return UNKNOWN


def integral_status(gap_id: str, evidence: object = None) -> str:
    """No synthetic or self-attested input certifies operational D-040 coverage.

    The required whole-system proof includes authenticated production evidence,
    diverse routes/logs/artifacts, data retention and/or restoration. This offline
    leaf cannot inspect those systems, so even an optimistic caller fails closed.
    """
    if gap_id not in GAPS:
        return UNKNOWN
    return UNKNOWN


class BrechasCondor08Tests(unittest.TestCase):
    @classmethod
    def setUpClass(cls) -> None:
        cls.matrix = MATRIX.read_text(encoding="utf-8")
        cls.rows = coverage_rows(cls.matrix)

    def test_contrato_principal(self) -> None:
        """AC-01: five independent gaps are asserted rather than omitted."""
        self.assertEqual(set(self.rows), set(GAPS))
        for gap_id, (title, required) in GAPS.items():
            with self.subTest(gap=gap_id):
                cells = self.rows[gap_id]
                self.assertEqual(cells[1], title)
                self.assertEqual(cells[2], "NO_VERIFICABLE")
                self.assertIn("Falta evidencia integral", cells[3])
                for term in required:
                    self.assertIn(term.casefold(), cells[3].casefold())
                self.assertEqual(integral_status(gap_id), UNKNOWN)

        # A gitignore inventory does not establish real scan/rotation.
        ignored = GITIGNORE.read_text(encoding="utf-8")
        self.assertEqual(local_secret_exclusion(ignored), LOCAL_PARTIAL)
        self.assertEqual(integral_status("D-040-S07", {"gitignore": ignored}), UNKNOWN)

    def test_falla_cerrado(self) -> None:
        """AC-02: missing, duplicate, malformed and self-declared proof fail."""
        line = next(line for line in self.matrix.splitlines()
                    if line.startswith("| `D-040-S07`"))
        for broken in (
            self.matrix.replace(line + "\n", ""),
            self.matrix + "\n" + line,
            self.matrix.replace(line, "| `D-040-S07` | incomplete |"),
            self.matrix.replace(line, line.replace("NO_VERIFICABLE", "VALIDADO")),
        ):
            with self.subTest(case=len(broken)):
                with self.assertRaises(ValueError):
                    coverage_rows(broken)
        for broken in (None, "", ".env\n*.log", ".env.*\n.env"):
            self.assertEqual(local_secret_exclusion(broken), UNKNOWN)
        self.assertEqual(integral_status("D-040-S99", {"pass": True}), UNKNOWN)

    def test_sin_datos_reales(self) -> None:
        """AC-03: synthetic-only fixtures, no network, secrets or customer PII."""
        fixture = {
            "tenant": "synthetic-tenant-01",
            "actor": "synthetic-admin-01",
            "route": "/synthetic/private",
            "trace": "synthetic-correlation-01",
            "backup": "synthetic-media-snapshot-01",
        }
        self.assertTrue(all("synthetic" in value for value in fixture.values()))
        for gap_id in GAPS:
            self.assertEqual(integral_status(gap_id, fixture), UNKNOWN)
        self.assertTrue(MATRIX.is_file())
        self.assertTrue(GITIGNORE.is_file())
        self.assertNotIn("@", " ".join(fixture.values()))

    def test_estados_unknown_explicitos(self) -> None:
        """AC-04 test target: none of five gaps gains integral proof by inference."""
        adversarial = (
            None,
            {},
            {"status": "success"},
            {"check": "Validar", "conclusion": "success"},
            {"review": "approved"},
            {"source": "synthetic", "all_checks": True},
            {"scan": True, "rotation": True},
            {"csp": True, "hsts": True},
            {"redacted": True, "correlation": True},
            {"audit": True, "retention_days": 30},
            {"db_backup": True, "media_backup": True,
             "encrypted": True, "isolated": True, "restore": True},
        )
        for gap_id in GAPS:
            with self.subTest(gap=gap_id):
                self.assertEqual(self.rows[gap_id][2], "NO_VERIFICABLE")
                for evidence in adversarial:
                    self.assertEqual(integral_status(gap_id, evidence), UNKNOWN)


if __name__ == "__main__":
    unittest.main()
