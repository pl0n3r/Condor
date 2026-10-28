#!/usr/bin/env python3
"""Condor #1202: offline security-gap contrasts D-040-S02..S06.

The existing Symfony/PHP test cases and configurations are LOCAL evidence only.
These checks cannot certify every browser mutation, SQL endpoint, tenant scope,
distributed limiter, session lifetime or production behavior. Integral state is
UNKNOWN unless a separate authorized audit reconciles full-system evidence.
"""
from __future__ import annotations

import re
import unittest
from pathlib import Path

ROOT = Path(__file__).resolve().parents[1]
MATRIX = ROOT / "docs/matriz-cobertura.md"
SPEC = ROOT / "ESPECIFICACIONES.md"
UNKNOWN = "UNKNOWN"
LOCAL_PARTIAL = "LOCAL_PARTIAL"

# Five independent inventory rows; the matrix is not modified by this leaf.
GAPS = {
    "D-040-S02": ("2. Política de sesión",
                  ("flags de cookies", "rotación", "revocación", "2h", "12h")),
    "D-040-S03": ("3. Protección CSRF",
                  ("CSRF", "mutaciones", "AJAX/React")),
    "D-040-S04": ("4. Consultas parametrizadas e inyección",
                  ("SQL/DQL", "allowlists", "ordenamientos")),
    "D-040-S05": ("5. Validación server-side",
                  ("DTOs", "rangos", "tenant", "mass assignment")),
    "D-040-S06": ("6. Rate limiting y defensa contra abuso",
                  ("identidad+IP", "10/15 min", "5/h", "distribuida")),
}

# Real, pre-existing source-code/acceptance-test anchors. They are *partial*:
# no synthetic test is allowed to promote the five integral coverage verdicts.
ANCHORS = {
    "D-040-S02": {
        "config/packages/framework.yaml": (
            "cookie_httponly: true", "cookie_secure: auto",
            "cookie_samesite: lax", "gc_maxlifetime: 7200",
        ),
        "tests/php/Http/SecurityHttpSessionNegativesTest.php": (
            "->getSession()->invalidate()", "assertResponseRedirects('/admin/login')",
        ),
    },
    "D-040-S03": {
        "config/packages/framework.yaml": ("csrf_protection: true",),
        "config/packages/security.yaml": (
            "enable_csrf: true", "csrf_token_id: logout",
        ),
        "tests/php/Http/SecurityHttpSessionNegativesTest.php": (
            "assertResponseStatusCodeSame(403)", "'_csrf_token'",
        ),
        "tests/php/Http/OrderIsolationControllerTest.php": (
            "HTTP_X_CSRF_TOKEN", "assertResponseStatusCodeSame(403)",
        ),
    },
    "D-040-S04": {
        "tests/php/Http/SecurityHttpSessionNegativesTest.php": (
            "fetchOne(", "['id' => $user->id()]",
        ),
        "tests/php/Http/TenantPublicCatalogSearchTest.php": (
            "?q=%25%5F", "assertResponseStatusCodeSame(400)",
        ),
    },
    "D-040-S05": {
        "tests/php/Http/OrderIsolationControllerTest.php": (
            "testTenantOrdersAreHiddenAndMutationsDenied",
            "assertResponseStatusCodeSame(404)",
            "assertResponseStatusCodeSame(403)",
            "inventory_source_id",
        ),
        "tests/php/Http/TenantPublicCatalogSearchTest.php": (
            "?pagina=1001", "?categoria=../tenant",
            "assertResponseStatusCodeSame(400)",
        ),
    },
    "D-040-S06": {
        "config/packages/security.yaml": (
            "login_throttling:", "max_attempts: 10", "interval: '15 minutes'",
        ),
        "config/packages/rate_limiter.yaml": (
            "password_reset_request_ip:", "password_reset_request_account:",
            "limit: 5", "interval: '1 hour'",
        ),
        "tests/test_password_security_http_limits.py": (
            "sixth must not reissue a token", "self::assertSame(",
        ),
    },
}

SPEC_ANCHORS = {
    "D-040-S02": ("HttpOnly", "Secure", "SameSite=Lax", "2 horas", "12 horas",
                  "logout invalida la sesión"),
    "D-040-S03": ("toda mutación autenticada", "tokens CSRF",
                  "AJAX/React", "nunca desactivar CSRF"),
    "D-040-S04": ("Doctrine ORM/DBAL", "parámetros enlazados", "allowlists"),
    "D-040-S05": ("DTOs/commands", "pertenencia al tenant", "mass assignment"),
    "D-040-S06": ("10 intentos por 15 minutos", "5 solicitudes por hora",
                  "identidad + IP", "límites específicos"),
}


def matrix_rows(document: object) -> dict[str, tuple[str, ...]]:
    """Missing, duplicate, promoted, wrong-source or malformed rows fail closed."""
    if not isinstance(document, str):
        raise ValueError("UNKNOWN: unreadable matrix")
    rows: dict[str, tuple[str, ...]] = {}
    pattern = re.compile(r"^\|\s*`(D-040-S0[2-6])`(?=\s|\W)")
    for line in document.splitlines():
        match = pattern.match(line)
        if match is None:
            continue
        key = match.group(1)
        cells = tuple(item.strip() for item in line.strip().strip("|").split("|"))
        if (key in rows or len(cells) != 5 or not all(cells)
                or cells[0] != f"`{key}` · `ESPECIFICACIONES.md`"
                or cells[1] != GAPS[key][0]
                or cells[2] != "`NO_VERIFICABLE`"
                or key not in cells[3]
                or "Falta evidencia integral" not in cells[3]
                or cells[4] != "#1"):
            raise ValueError("UNKNOWN: inconsistent gap row " + key)
        rows[key] = cells
    if set(rows) != set(GAPS):
        raise ValueError("UNKNOWN: incomplete D-040-S02..S06 inventory")
    return rows


def spec_section(document: object, number: int) -> str:
    """Extract just one normative D-040 subsection, never infer adjacent text."""
    if not isinstance(document, str):
        raise ValueError("UNKNOWN: missing specification")
    section = re.search(
        rf"(?ms)^#### {number}\. [^\n]+\n(.*?)(?=^#### \d+\. |\Z)",
        document,
    )
    if section is None or not section.group(1).strip():
        raise ValueError("UNKNOWN: missing normative D-040 section")
    return section.group(1)


def local_evidence(gap: str, sources: object) -> str:
    """Recognize exact known file evidence, never label it full compliance."""
    if gap not in GAPS or not isinstance(sources, dict):
        return UNKNOWN
    expected = ANCHORS[gap]
    if set(sources) != set(expected):
        return UNKNOWN
    for path, anchors in expected.items():
        source = sources[path]
        if not isinstance(source, str) or not source.strip():
            return UNKNOWN
        if any(anchor not in source for anchor in anchors):
            return UNKNOWN
    return LOCAL_PARTIAL


def integral_state(gap: str, matrix: object, evidence: object = None) -> str:
    """A matrix declaration or synthetic proof cannot certify integral security."""
    if gap not in GAPS:
        return UNKNOWN
    try:
        rows = matrix_rows(matrix)
    except ValueError:
        return UNKNOWN
    if rows[gap][2] != "`NO_VERIFICABLE`":
        return UNKNOWN
    # Even seemingly positive local/CI evidence lacks all route/runtime checks.
    # No implicit promotion from a claim, PR status or local file presence.
    return UNKNOWN


class BrechasCondor07Tests(unittest.TestCase):
    @classmethod
    def setUpClass(cls) -> None:
        cls.matrix = MATRIX.read_text(encoding="utf-8")
        cls.rows = matrix_rows(cls.matrix)
        cls.spec = SPEC.read_text(encoding="utf-8")

    def test_contrato_principal(self) -> None:
        """AC-01: one independently asserted gap and partial source per clause."""
        self.assertEqual(set(self.rows), set(GAPS))
        for gap, (title, required) in GAPS.items():
            with self.subTest(gap=gap):
                row = self.rows[gap]
                self.assertEqual(row[1], title)
                self.assertEqual(row[2], "`NO_VERIFICABLE`")
                self.assertIn("Falta evidencia integral", row[3])
                for term in required:
                    self.assertIn(term.casefold(), row[3].casefold())
                subsection = spec_section(self.spec, int(gap[-2:]))
                for anchor in SPEC_ANCHORS[gap]:
                    self.assertIn(anchor.casefold(), subsection.casefold())
                sources = {name: (ROOT / name).read_text(encoding="utf-8")
                           for name in ANCHORS[gap]}
                self.assertEqual(local_evidence(gap, sources), LOCAL_PARTIAL)
                self.assertEqual(integral_state(gap, self.matrix, sources), UNKNOWN)

    def test_falla_cerrado(self) -> None:
        """AC-02: no green from missing, duplicate, conflicting or broken data."""
        for gap in GAPS:
            line = next(l for l in self.matrix.splitlines()
                        if l.startswith(f"| `{gap}`"))
            cases = (
                self.matrix.replace(line + "\n", "", 1),
                self.matrix + "\n" + line,
                self.matrix.replace(line, line.replace("NO_VERIFICABLE", "CUBIERTO"), 1),
                self.matrix.replace(line, line.replace("ESPECIFICACIONES.md", "otra.md"), 1),
                self.matrix.replace(line, f"| `{gap}` | malformed |", 1),
            )
            for i, bad in enumerate(cases):
                with self.subTest(gap=gap, case=i):
                    with self.assertRaises(ValueError):
                        matrix_rows(bad)
                    self.assertEqual(integral_state(gap, bad, {"ci": "success"}), UNKNOWN)
            real_sources = {name: (ROOT / name).read_text(encoding="utf-8")
                            for name in ANCHORS[gap]}
            path = next(iter(real_sources))
            missing = dict(real_sources)
            del missing[path]
            self.assertEqual(local_evidence(gap, missing), UNKNOWN)
            corrupted = dict(real_sources)
            corrupted[path] = "untrusted self-reported success"
            self.assertEqual(local_evidence(gap, corrupted), UNKNOWN)
        with self.assertRaises(ValueError):
            matrix_rows(None)
        for invalid in (None, "", "untrusted"):
            with self.assertRaises(ValueError):
                spec_section(invalid, 2)
        self.assertEqual(local_evidence("D-040-S99", {}), UNKNOWN)
        self.assertEqual(local_evidence("D-040-S02", None), UNKNOWN)
        self.assertEqual(local_evidence("D-040-S02", {"unexpected": "success"}), UNKNOWN)
        with self.assertRaises(ValueError):
            spec_section(self.spec, 99)

    def test_sin_datos_reales(self) -> None:
        """AC-03: only repository text and fake fixtures; no network or secrets."""
        fixtures = {
            "tenant": "synthetic-tenant-01",
            "actor": "synthetic-actor-01",
            "session": "synthetic-session-01",
            "csrf": "synthetic-invalid-token",
            "ip": "198.51.100.20",  # RFC 5737 documentation-only address
        }
        self.assertTrue(all(v.startswith("synthetic-") for k, v in fixtures.items()
                            if k != "ip"))
        self.assertNotIn("@", " ".join(fixtures.values()))
        self.assertTrue(MATRIX.is_file())
        self.assertTrue(SPEC.is_file())
        all_paths = {p for paths in ANCHORS.values() for p in paths}
        self.assertTrue(all((ROOT / path).is_file() for path in all_paths))
        self.assertTrue(all(not path.startswith("/") and ".." not in Path(path).parts
                            for path in all_paths))
        for gap in GAPS:
            self.assertEqual(integral_state(gap, self.matrix, fixtures), UNKNOWN)

    def test_estados_unknown_explicitos(self) -> None:
        """AC-04 target: fabricated GREEN cannot rewrite a NO_VERIFICABLE row."""
        claims = (
            "GREEN", "success", "VALIDATED_IN_PRODUCTION",
            "offline=VERIFIED; live=HEALTHY",
        )
        for gap in GAPS:
            row = next(l for l in self.matrix.splitlines()
                       if l.startswith(f"| `{gap}`"))
            for claim in claims:
                # The evidence cell may contain optimistic text; matrix status
                # remains NO_VERIFICABLE until independent full-system proof.
                fake = self.matrix.replace(
                    row, row.replace("Veredicto:", f"claim={claim}; Veredicto:"), 1
                )
                with self.subTest(gap=gap, claim=claim):
                    self.assertEqual(matrix_rows(fake)[gap][2], "`NO_VERIFICABLE`")
                    self.assertEqual(integral_state(gap, fake, claim), UNKNOWN)
            self.assertEqual(integral_state(gap, self.matrix, {"ci": "success"}), UNKNOWN)
        self.assertEqual(integral_state("D-040-S99", self.matrix, True), UNKNOWN)


if __name__ == "__main__":
    unittest.main()
