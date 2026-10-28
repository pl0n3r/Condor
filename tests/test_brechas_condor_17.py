#!/usr/bin/env python3
"""#1212: contrastes D-045-S01/S02/S03, D-046 y D-047, exclusivamente offline."""
from __future__ import annotations
import json
import re
import subprocess
import sys
import unittest
from pathlib import Path

ROOT = Path(__file__).resolve().parents[1]
UNKNOWN = "UNKNOWN"
GAP = "BRECHA_DOCUMENTADA"
MATRIX = "docs/matriz-cobertura.md"
CONTRAST = "docs/contraste-cd_d3.md"
SPEC = "ESPECIFICACIONES.md"
ROWS = {
    "D-045-S01": ("IndexNow", "#### IndexNow",
                  "src/Application/Seo/IndexNowNotifier.php"),
    "D-045-S02": ("Google Tag Manager por customer/tenant",
                  "#### Google Tag Manager por customer/tenant",
                  "src/Application/Seo/IndexNowNotifier.php"),
    "D-045-S03": ("Seguridad y pruebas", "#### Seguridad y pruebas",
                  "src/Application/Seo/IndexNowNotifier.php"),
    "D-046": ("Propietario único de plataforma y administración delegada",
              "### D-046 — Propietario único de plataforma y administración delegada",
              "src/Application/Identity/PlatformOwnerTenantContext.php"),
    "D-047": ("Diagnóstico seguro y compartible de errores",
              "### D-047 — Diagnóstico seguro y compartible de errores", SPEC),
}
SUITES = {"test_indexnow_notifier.py": 1, "test_tag_manager_settings.py": 2}


def source(path: str) -> str:
    """Lee únicamente el checkout, nunca APIs ni registros reales."""
    return (ROOT / path).read_text(encoding="utf-8")


def rows(document: str, kind: str) -> dict[str, tuple[str, ...]]:
    if kind not in {"matrix", "contrast"}:
        raise ValueError("UNKNOWN: fuente")
    result = {}
    for key, (title, _section, candidate) in ROWS.items():
        prefix = ("| " + chr(96) + key + chr(96) + " · "
                  if kind == "matrix" else "| " + key + " |")
        matches = [s for s in document.splitlines() if s.startswith(prefix)]
        if len(matches) != 1:
            raise ValueError("UNKNOWN: fila ausente o duplicada " + key)
        cells = tuple(c.strip() for c in matches[0].strip("|").split("|"))
        if kind == "matrix":
            valid = (len(cells) == 5
                     and cells[0] == (chr(96)+key+chr(96)+" · "+chr(96)+SPEC+chr(96))
                     and cells[1] == title
                     and cells[2] == chr(96)+"NO_VERIFICABLE"+chr(96)
                     and "Falta evidencia integral " + key in cells[3]
                     and "tríada código/test/PR MERGED" in cells[3]
                     and cells[4] == "#1")
        else:
            valid = (len(cells) == 6 and cells[0] == key
                     and cells[1] == "NO_VERIFICABLE" and cells[2] == candidate
                     and cells[4] == "—" and key in cells[5]
                     and "Falta acreditar integralmente" in cells[5]
                     and "tríada código/test/PR MERGED" in cells[5])
        if not valid:
            raise ValueError("UNKNOWN: fila inválida " + key)
        result[key] = cells
    return result


def section(raw: str, heading: str) -> str:
    lines = raw.splitlines()
    pos = [i for i, x in enumerate(lines) if x == heading]
    if len(pos) != 1:
        return ""
    level = len(heading) - len(heading.lstrip("#"))
    for end in range(pos[0] + 1, len(lines)):
        match = re.match(r"^(#{1,6})\s+", lines[end])
        if match and len(match.group(1)) <= level:
            return "\n".join(lines[pos[0]+1:end])
    return "\n".join(lines[pos[0]+1:])


def documented(key: str, matrix: str, contrast: str, spec: str) -> str:
    if key not in ROWS:
        return UNKNOWN
    try:
        rows(matrix, "matrix")
        rows(contrast, "contrast")
    except ValueError:
        return UNKNOWN
    return GAP if section(spec, ROWS[key][1]) else UNKNOWN


def operational(_key: object, _claim: object = None) -> str:
    """Ni CI verde ni claims sintéticos certifican operación/live."""
    return UNKNOWN


def offline_suite(filename: str) -> None:
    """PHP usa únicamente fakes in-memory; ejecutamos suites concretas."""
    if filename not in SUITES:
        raise ValueError("UNKNOWN: suite no autorizada")
    run = subprocess.run(
        [sys.executable, "-m", "unittest", "discover",
         "-s", "tests", "-p", filename],
        cwd=ROOT, capture_output=True, text=True, check=False, timeout=120,
    )
    output = run.stdout + run.stderr
    expected = SUITES[filename]
    if (run.returncode != 0
            or not re.search(rf"Ran {expected} tests?\b", output)
            or not re.search(r"^OK$", output, re.MULTILINE)):
        raise AssertionError("Suite offline falló: "+filename+"\n"+output[-1600:])


class BrechasCondor17Tests(unittest.TestCase):
    def test_contrato_principal(self) -> None:
        """AC-01: ID trazable, fixture real para SEO y negativos explícitos."""
        matrix, contrast, spec = (source(p) for p in (MATRIX, CONTRAST, SPEC))
        self.assertEqual(set(rows(matrix, "matrix")), set(ROWS))
        self.assertEqual(set(rows(contrast, "contrast")), set(ROWS))
        for key in ROWS:
            with self.subTest(key=key):
                self.assertEqual(GAP, documented(key, matrix, contrast, spec))
                self.assertEqual(UNKNOWN, operational(key, {"ci": "success"}))

        # S01: URLs públicas/privadas, noindex, deduplicación, tenant y retries.
        offline_suite("test_indexnow_notifier.py")
        notifier = source("src/Application/Seo/IndexNowNotifier.php")
        for token in ("MAX_ATTEMPTS = 3", "isPublicPath($publicPath)",
                      "$this->enqueueIfAbsent", "$this->transport"):
            self.assertIn(token, notifier, "D-045-S01: "+token)

        # S02: GTM por tenant/canal, consent, estado disabled y entradas inválidas.
        offline_suite("test_tag_manager_settings.py")
        gtm = source("src/Application/Seo/TagManagerSettings.php")
        for token in ("renderPublicTag(", "GTM-", "tenant"):
            self.assertIn(token, gtm, "D-045-S02: "+token)

        # S03: pruebas de denegación en límites públicos; CSP global sigue UNKNOWN.
        self.assertIn("noindex", notifier, "D-045-S03")
        self.assertIn("consent", gtm.lower(), "D-045-S03")
        self.assertIn("#### Seguridad y pruebas", spec)

        # D046: owner global y gates HTTP negativos (suite existente sintética).
        owner = source("src/Http/Controller/PlatformOwnerApiController.php")
        http = source("tests/test_platform_owner_api_permissions.py")
        self.assertIn("ROLE_PLATFORM_OWNER", owner, "D-046")
        self.assertIn("self::assertResponseStatusCodeSame(401)", http)
        self.assertIn("self::assertResponseStatusCodeSame(403)", http)
        provisioner = source("tests/test_provision_platform_owner_command_unit.py")
        self.assertIn("testSecondOwnerIsRejectedAndTransactionRollsBack", provisioner)

        # D047: token almacenado como hash, expiración y sanitización.
        share = source("src/Application/Observability/DiagnosticShareService.php")
        sanitizer = source("src/Infrastructure/Observability/ErrorSanitizer.php")
        self.assertIn("TTL_MINUTES = 30", share, "D-047")
        self.assertIn("hash('sha256', $token)", share)
        self.assertIn("$share->isUsable($now)", share)
        self.assertIn("self::MAX_FRAMES", sanitizer)
        self.assertIn("Bearer [REDACTED]", sanitizer)
        # Prueba real del sanitizador contra un secreto y correo sintéticos:
        # sin framework, DB, servicios externos ni persistencia.
        php_fixture = r"""
require $argv[1];
$safe = new \App\Infrastructure\Observability\ErrorSanitizer($argv[2]);
$err = new \RuntimeException(
    'Authorization: Bearer SYNTHETIC_SECRET usuario@example.test'
);
$text = $safe->message($err);
if (str_contains($text, 'SYNTHETIC_SECRET')
    || str_contains($text, 'usuario@example.test')
    || !str_contains($text, 'Bearer [REDACTED]')
    || !str_contains($text, '[EMAIL]')) {
    throw new \RuntimeException('Sanitizer did not redact fixture');
}
echo 'SANITIZED';
"""
        sanitized = subprocess.run(
            ["php", "-r", php_fixture,
             str(ROOT / "src/Infrastructure/Observability/ErrorSanitizer.php"),
             str(ROOT)],
            cwd=ROOT, capture_output=True, text=True, check=False, timeout=30,
        )
        self.assertEqual(0, sanitized.returncode,
                         sanitized.stdout + sanitized.stderr)
        self.assertEqual("SANITIZED", sanitized.stdout.strip())
        self.assertIn("testBearerLinksAreScopedAndFailClosed",
                      source("tests/test_shared_diagnostic_http.py"))

    def test_falla_cerrado(self) -> None:
        """AC-02: duplicado, fila ausente o status promovido nunca pasan."""
        for kind, raw in (("matrix", source(MATRIX)),
                          ("contrast", source(CONTRAST))):
            for key in ROWS:
                prefix = ("| "+chr(96)+key+chr(96)+" · "
                          if kind == "matrix" else "| "+key+" |")
                row = next(l for l in raw.splitlines() if l.startswith(prefix))
                for invalid in (raw.replace(row, "", 1), raw+"\n"+row,
                                raw.replace(row, row.replace("NO_VERIFICABLE", "CUBIERTO"), 1)):
                    with self.subTest(key=key, kind=kind):
                        with self.assertRaisesRegex(ValueError, "UNKNOWN"):
                            rows(invalid, kind)
        self.assertEqual(UNKNOWN, documented("D-047", "", "", source(SPEC)))
        with self.assertRaisesRegex(ValueError, "UNKNOWN"):
            offline_suite("test_live_http.py")

    def test_sin_datos_reales(self) -> None:
        """AC-03: fakes sintéticos sin transporte real ni credenciales."""
        a = source("tests/test_indexnow_notifier.py")
        b = source("tests/test_tag_manager_settings.py")
        self.assertIn("$queued = []", a)
        self.assertIn("$delivered = []", a)
        self.assertIn("$storage = []", b)
        self.assertIn("tenant-a", a+b)
        payload = {"tenant": "synthetic", "container": "GTM-ABC123",
                   "status": UNKNOWN}
        data = json.dumps(payload, sort_keys=True).lower()
        for secret in ("password", "token", "authorization", "cookie",
                       "database_url", "secret", "bearer"):
            self.assertNotIn(secret, data)
        self.assertEqual(UNKNOWN, operational("D-047", payload))

    def test_estados_unknown_explicitos(self) -> None:
        """AC-04: evidencia offline parcial no es tríada MERGED ni live."""
        matrix, contrast, spec = (source(p) for p in (MATRIX, CONTRAST, SPEC))
        for key in ROWS:
            with self.subTest(key=key):
                self.assertEqual(GAP, documented(key, matrix, contrast, spec))
                for claim in (None, {}, True, {"ci": "success"},
                              {"gtm": "enabled", "indexnow": "sent"},
                              {"owner": "verified", "diagnostic": "redacted"},
                              {"state": "VALIDATED_IN_PRODUCTION", "sha": "a"*40},
                              {"code": True, "test": True, "pr": "MERGED"}):
                    self.assertEqual(UNKNOWN, operational(key, claim))
        self.assertEqual(UNKNOWN, documented("D-999", matrix, contrast, spec))


if __name__ == "__main__":
    unittest.main()
