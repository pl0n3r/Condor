#!/usr/bin/env python3
"""#1201: five synthetic, fail-closed contracts D-013..D-040-S01.

LOCAL_PARTIAL proves only controls visible in this checkout. It cannot attest
the automatic Sonar relay, a live deployment, E2E rehash or completed Phase 4.
"""
from __future__ import annotations

import re
import unittest
from pathlib import Path

ROOT = Path(__file__).resolve().parents[1]
MATRIX = ROOT / "docs/matriz-cobertura.md"
UNKNOWN = "UNKNOWN"
PARTIAL = "LOCAL_PARTIAL"
TICK = chr(96)
GAPS = {
    "D-013": "Relay detallado de Sonar en Pull Requests",
    "D-034": "Seguridad, tiempo, errores y contratos de aplicación",
    "D-039": "Identidad visible de versión",
    "D-040": "Baseline integral de seguridad y confiabilidad (Fase 4 cerrada)",
    "D-040-S01": "1. Autenticación segura",
}
SOURCES = {
    "relay": ".github/workflows/sonar-annotation-relay.yml",
    "security": "config/packages/security.yaml",
    "framework": "config/packages/framework.yaml",
    "version": "config/version.php",
    "appversion": "src/Shared/Version/AppVersion.php",
    "home": "src/Http/Controller/HomeController.php",
    "login_controller": "src/Http/Controller/SecurityController.php",
    "home_template": "templates/base.html.twig",
    "login_template": "templates/security/login.html.twig",
    "user": "src/Domain/Identity/Entity/User.php",
    "password": "src/Http/Controller/PasswordSecurityController.php",
    "change": "src/Application/Identity/ChangeOwnPassword.php",
    "password_test": "tests/php/Http/PasswordSecurityControllerTest.php",
    "session_test": "tests/php/Http/SecurityHttpSessionNegativesTest.php",
}


def coverage_rows(document: object) -> dict[str, tuple[str, ...]]:
    """Reject missing, duplicate, promoted or contradictory matrix rows."""
    if not isinstance(document, str) or not document.strip():
        raise ValueError("UNKNOWN: matrix missing")
    found = {}
    for line in document.splitlines():
        if not line.startswith("| " + TICK + "D-"):
            continue
        cells = tuple(cell.strip() for cell in line.strip().strip("|").split("|"))
        if not cells:
            continue
        match = re.fullmatch(
            re.escape(TICK) + r"(D-\d{3}(?:-S\d{2})?)" + re.escape(TICK)
            + r" · " + re.escape(TICK) + r"ESPECIFICACIONES\.md"
            + re.escape(TICK), cells[0],
        )
        if not match or match.group(1) not in GAPS:
            continue
        key = match.group(1)
        if (key in found or len(cells) != 5 or not all(cells)
                or cells[1] != GAPS[key]
                or cells[2] != TICK + "NO_VERIFICABLE" + TICK
                or key not in cells[3] or not cells[4].startswith("#")):
            raise ValueError("UNKNOWN: invalid row " + key)
        found[key] = cells
    if set(found) != set(GAPS):
        raise ValueError("UNKNOWN: missing gap IDs")
    return found


def synthetic_authorization(actor: object, tenant: object, claim: object) -> str:
    """Strict fixture, not a substitute for Symfony authorization."""
    if (not isinstance(actor, str) or not actor.startswith("synthetic:")
            or not isinstance(tenant, str) or not tenant.startswith("synthetic:")
            or not isinstance(claim, dict)
            or set(claim) != {"tenant", "active", "can_write"}
            or claim["tenant"] != tenant or claim["active"] is not True
            or claim["can_write"] is not True):
        return UNKNOWN
    return PARTIAL


def synthetic_relay(check: object, prior_comment: object) -> tuple[str, str]:
    """Select POST/PATCH offline without API calls or network."""
    if (not isinstance(check, dict)
            or set(check) != {"name", "status", "head_sha", "annotations"}
            or check["name"] != "SonarCloud Code Analysis"
            or check["status"] != "completed"
            or not isinstance(check["head_sha"], str)
            or re.fullmatch(r"[0-9a-f]{40}", check["head_sha"]) is None
            or not isinstance(check["annotations"], list)
            or any(not isinstance(row, dict)
                   or set(row) != {"path", "message"}
                   or not isinstance(row["path"], str) or not row["path"]
                   or not isinstance(row["message"], str) or not row["message"]
                   for row in check["annotations"])
            or prior_comment is not None and not isinstance(prior_comment, str)):
        return UNKNOWN, UNKNOWN
    marker = "<!-- condor-sonar-annotations -->"
    return PARTIAL, "PATCH" if prior_comment is not None and marker in prior_comment else "POST"


def version_from_file(text: object) -> str | None:
    if not isinstance(text, str):
        return None
    versions = re.findall(r"['\"]version['\"]\s*=>\s*['\"](\d+\.\d+\.\d+)['\"]", text)
    return versions[0] if len(versions) == 1 else None


def synthetic_version_views(version: object, public: object, admin: object) -> str:
    if (not isinstance(version, str) or re.fullmatch(r"\d+\.\d+\.\d+", version) is None
            or public != "V " + version or admin != public):
        return UNKNOWN
    return PARTIAL


def local_controls(source: object) -> dict[str, str]:
    """Local evidence only; UNKNOWN is not a missing assertion."""
    if (not isinstance(source, dict) or set(source) != set(SOURCES)
            or any(not isinstance(item, str) or not item.strip()
                   for item in source.values())):
        return dict.fromkeys(GAPS, UNKNOWN)
    relay, security = source["relay"], source["security"]
    framework, password = source["framework"], source["password"]
    version, version_code = version_from_file(source["version"]), source["appversion"]
    home, login = source["home"], source["login_controller"]
    public_view, admin_view = source["home_template"], source["login_template"]
    user, change = source["user"], source["change"]
    password_test, session_test = source["password_test"], source["session_test"]
    relay_partial = all(s in relay for s in (
        "condor-sonar-annotations", "SonarCloud Code Analysis",
        "annotations?per_page=100&page=", '"PATCH"', '"POST"',
        "if existing:", "len(annotations)", "annotations[:50]", "details_url",
    ))
    security_partial = (
        all(s in security for s in (
            "password_hashers:", "PasswordAuthenticatedUserInterface: 'auto'",
            "form_login:", "enable_csrf: true", "login_throttling:",
            "max_attempts: 10", "ROLE_PLATFORM_OWNER", "ROLE_USER",
        ))
        and all(s in framework for s in (
            "csrf_protection: true", "cookie_httponly: true",
            "cookie_secure: auto", "cookie_samesite: lax",
        ))
        and all(s in password for s in (
            "GENERIC_RESET_MESSAGE", "hash('sha256', $email)",
            "passwordResetRequestIpLimiter", "csrfFailure(",
            "createAccessDeniedException", "getSession()->migrate(true)",
        ))
        and all(s in password_test for s in (
            "testResetRequestUsesCsrfAndGenericAntiEnumerationResponse",
            "testAnonymousRecoveryPostsRejectInvalidCsrf",
            "testPasswordChangeRequiresAuthenticationAndValidCsrf",
        ))
        and all(s in session_test for s in (
            "testExpiredSessionsAndInvalidCsrfOnMutatingRoutes",
            "assertResponseStatusCodeSame(403)", "getSession()->invalidate()",
        ))
    )
    version_partial = bool(version) and (
        "config/version.php" in version_code
        and "function human()" in version_code
        and "'app_version' => $version->human()" in home
        and "'app_version' => $version->human()" in login
        and "V {{ app_version }}" in public_view
        and "V {{ app_version }}" in admin_view
    )
    password_partial = security_partial and (
        all(s in user for s in (
            "PasswordAuthenticatedUserInterface", "unique: true",
            "strtolower(trim(", "private string $passwordHash",
        ))
        and all(s in change for s in (
            "UserPasswordHasherInterface", "isPasswordValid(",
            "hashPassword(", "setPasswordHash(",
        ))
    )
    checks = {
        "D-013": relay_partial,
        "D-034": security_partial,
        "D-039": version_partial,
        "D-040": security_partial and version_partial and password_partial,
        "D-040-S01": password_partial,
    }
    result = {key: PARTIAL if ok else UNKNOWN for key, ok in checks.items()}
    # No check_run event trigger in the workflow means no automatic D-013 proof.
    header = relay.split("\npermissions:", 1)[0]
    if re.search(r"(?m)^\s*check_run:\s*$", header) is None:
        result["D-013"] = UNKNOWN
    return result


def full_coverage(gap: object, local: object) -> str:
    """Requires an independent merged PR / operational proof, absent here."""
    return UNKNOWN


class BrechasCondor06Tests(unittest.TestCase):
    @classmethod
    def setUpClass(cls) -> None:
        cls.document = MATRIX.read_text(encoding="utf-8")
        cls.rows = coverage_rows(cls.document)
        cls.source = {k: (ROOT / path).read_text(encoding="utf-8")
                      for k, path in SOURCES.items()}
        cls.local = local_controls(cls.source)

    def test_contrato_principal(self) -> None:
        """AC-01: one explicit assertion/verdict for each of five rows."""
        self.assertEqual(set(self.rows), set(GAPS))
        self.assertEqual(set(self.local), set(GAPS))
        expected = {
            "D-013": UNKNOWN,
            "D-034": PARTIAL,
            "D-039": PARTIAL,
            "D-040": PARTIAL,
            "D-040-S01": PARTIAL,
        }
        for gap, title in GAPS.items():
            with self.subTest(gap=gap):
                self.assertEqual(self.rows[gap][1], title)
                self.assertEqual(self.rows[gap][2], TICK + "NO_VERIFICABLE" + TICK)
                self.assertEqual(self.local[gap], expected[gap])
                self.assertEqual(full_coverage(gap, self.local[gap]), UNKNOWN)
        check = {
            "name": "SonarCloud Code Analysis", "status": "completed",
            "head_sha": "a" * 40,
            "annotations": [{"path": "synthetic:check.py", "message": "synthetic:notice"}],
        }
        self.assertEqual(synthetic_relay(check, None), (PARTIAL, "POST"))
        self.assertEqual(synthetic_relay(
            check, "<!-- condor-sonar-annotations -->"), (PARTIAL, "PATCH"))
        version = version_from_file(self.source["version"])
        self.assertIsNotNone(version)
        self.assertEqual(synthetic_version_views(version, "V " + version, "V " + version),
                         PARTIAL)
        self.assertEqual(synthetic_authorization(
            "synthetic:actor", "synthetic:tenant-A",
            {"tenant": "synthetic:tenant-A", "active": True, "can_write": True},
        ), PARTIAL)

    def test_falla_cerrado(self) -> None:
        """AC-02: missing source, duplicate coverage, false privilege => UNKNOWN."""
        line = next(x for x in self.document.splitlines()
                    if x.startswith("| " + TICK + "D-013" + TICK))
        for bad in (self.document.replace(line, "", 1),
                    self.document + "\n" + line,
                    self.document.replace(line, line.replace(
                        "NO_VERIFICABLE", "CUBIERTO", 1))):
            with self.assertRaisesRegex(ValueError, UNKNOWN):
                coverage_rows(bad)
        for key, needle, gap in (
            ("relay", "condor-sonar-annotations", "D-013"),
            ("security", "login_throttling:", "D-034"),
            ("appversion", "config/version.php", "D-039"),
            ("framework", "csrf_protection: true", "D-040"),
            ("change", "hashPassword(", "D-040-S01"),
        ):
            with self.subTest(gap=gap):
                broken = dict(self.source)
                self.assertIn(needle, broken[key])
                broken[key] = broken[key].replace(needle, "", 1)
                self.assertEqual(local_controls(broken)[gap], UNKNOWN)
        good = {"name": "SonarCloud Code Analysis", "status": "completed",
                "head_sha": "a" * 40, "annotations": []}
        for bad in ({**good, "status": "queued"}, {**good, "head_sha": "bad"},
                    {**good, "name": "untrusted-check"},
                    {**good, "annotations": [{"message": "missing path"}]},
                    {**good, "token": "synthetic:forbidden"}):
            self.assertEqual(synthetic_relay(bad, None), (UNKNOWN, UNKNOWN))
        for claim in (
            {"tenant": "synthetic:tenant-B", "active": True, "can_write": True},
            {"tenant": "synthetic:tenant-A", "active": True, "can_write": False},
            {"tenant": "synthetic:tenant-A", "active": True, "can_write": True, "admin": True},
            {},
        ):
            self.assertEqual(synthetic_authorization(
                "synthetic:actor", "synthetic:tenant-A", claim), UNKNOWN)
        for version, public, admin in (
            (None, "V 0.1.0", "V 0.1.0"),
            ("v0.1.0", "V 0.1.0", "V 0.1.0"),
            ("0.1.0\nextra", "V 0.1.0", "V 0.1.0"),
            ("1.1.1", "V 1.1.1", "V 1.1.0"),
        ):
            self.assertEqual(synthetic_version_views(version, public, admin), UNKNOWN)
        self.assertIsNone(version_from_file("<?php return ['version'=>'invalid'];"))
        self.assertIsNone(version_from_file(
            "<?php return ['version'=>'0.1.0','version'=>'0.1.1'];"))
        self.assertEqual(local_controls({}), dict.fromkeys(GAPS, UNKNOWN))

    def test_sin_datos_reales(self) -> None:
        """AC-03: repository reads and synthetic objects, no network, no PII."""
        for name, relative in SOURCES.items():
            with self.subTest(source=name):
                path = ROOT / relative
                self.assertTrue(path.is_file())
                self.assertTrue(path.resolve().is_relative_to(ROOT.resolve()))
        samples = ["synthetic:actor", "synthetic:tenant-A", "a" * 40]
        for sample in samples:
            self.assertNotIn("github_pat_", sample)
            self.assertNotIn("Bearer ", sample)
            self.assertNotIn("@gmail.com", sample)
        self.assertEqual(synthetic_authorization(None, None, {}), UNKNOWN)
        self.assertEqual(synthetic_relay(None, None), (UNKNOWN, UNKNOWN))

    def test_estados_unknown_explicitos(self) -> None:
        """AC-04: no inferred complete coverage of five broad decisions."""
        self.assertEqual(set(self.local), set(GAPS))
        self.assertEqual(
            {gap: full_coverage(gap, self.local[gap]) for gap in GAPS},
            dict.fromkeys(GAPS, UNKNOWN),
        )
        header = self.source["relay"].split("\npermissions:", 1)[0]
        self.assertIn("workflow_dispatch:", header)
        self.assertNotRegex(header, r"(?m)^\s*check_run:\s*$")
        missing_live = {
            "D-013": "sonar-automatic-roundtrip",
            "D-034": "full-security-e2e",
            "D-039": "deployed-version",
            "D-040": "all-phase-four-controls",
            "D-040-S01": "rehash-login-e2e",
        }
        self.assertEqual(set(missing_live), set(GAPS))
        for gap, missing in missing_live.items():
            with self.subTest(gap=gap):
                self.assertTrue(missing)
                self.assertEqual(full_coverage(gap, missing), UNKNOWN)


if __name__ == "__main__":
    unittest.main()
