"""Fail-closed contract tests for the Condor #1499 private browser lab."""
from __future__ import annotations

import os
import subprocess
import tempfile
import unittest
from pathlib import Path

ROOT = Path(__file__).resolve().parents[1]
SCRIPT = ROOT / "scripts/qa1499-private-browser-lab.cjs"
DOC = ROOT / "docs/verificacion/qa1499-private-browser-lab.md"


def run_node(*args: str, **overrides: str) -> subprocess.CompletedProcess[str]:
    env = dict(os.environ)
    for key in ("QA1499_BASE_URL", "QA1499_ACK_SYNTHETIC_TEST_ONLY",
                "QA1499_HEAD_SHA", "QA1499_SESSION_DIR"):
        env.pop(key, None)
    env.update(overrides)
    return subprocess.run(["node", str(SCRIPT), *args], cwd=ROOT, env=env,
                          text=True, capture_output=True, timeout=15, check=False)


class PrivateBrowserLabTests(unittest.TestCase):
    def test_preflight_rechaza_destinos_ajenos(self) -> None:
        self.assertTrue(SCRIPT.is_file())
        self.assertEqual(run_node("--self-test").returncode, 0)
        for url in ("https://condorapp.com.co", "http://condorapp.com.co:18891/",
                    "http://127.0.0.2:18891/", "http://localhost:18891/?token=secret",
                    "http://user:secret@localhost:18891/", "file:///tmp/session.json"):
            with self.subTest(url=url):
                result = run_node(QA1499_BASE_URL=url, QA1499_ACK_SYNTHETIC_TEST_ONLY="1",
                                  QA1499_HEAD_SHA="a" * 40, QA1499_SESSION_DIR="/tmp/no-sessions")
                self.assertNotEqual(result.returncode, 0)
                self.assertNotIn("secret", result.stderr + result.stdout)
                self.assertNotIn("condorapp.com.co", result.stderr + result.stdout)

    def test_matriz_privada_ocho_escenarios(self) -> None:
        source = SCRIPT.read_text(encoding="utf-8")
        for required in ("tenant_admin", "platform_owner", "'/admin'", "'/adminpl0n3r'",
                         "chromium", "webkit", "1440", "900", "390", "844",
                         "expected_cases: 8", "source_sha: sha",
                         "release_sha_matches: true"):
            with self.subTest(required=required):
                self.assertIn(required, source)
        self.assertEqual(2 * 2 * 2, 8)

    def test_fixtures_ausentes_fallan_cerrado(self) -> None:
        with tempfile.TemporaryDirectory(prefix="qa1499-session-") as tmp:
            os.chmod(tmp, 0o700)
            result = run_node(QA1499_BASE_URL="http://127.0.0.1:18891",
                              QA1499_ACK_SYNTHETIC_TEST_ONLY="1",
                              QA1499_HEAD_SHA="a" * 40, QA1499_SESSION_DIR=tmp)
        self.assertNotEqual(result.returncode, 0)
        self.assertIn("QA1499_PRIVATE_BROWSER_BLOCKED", result.stderr)
        self.assertNotIn("FileNotFoundError", result.stderr)

    def test_documento_e_invariantes(self) -> None:
        self.assertTrue(DOC.is_file())
        doc = DOC.read_text(encoding="utf-8")
        source = SCRIPT.read_text(encoding="utf-8")
        for phrase in ("#1891", "#1499", "ocho escenarios", "localhost",
                       "tenant_admin", "platform_owner", "Chrome", "Playwright WebKit",
                       "1440×900", "390×844", "NO_VERIFICADO", "PARCIAL",
                       "release_sha", "sesiones sintéticas", "fuera del repositorio",
                       "no certifica WCAG", "no acredita métricas de campo",
                       "no autoriza go-live"):
            with self.subTest(phrase=phrase):
                self.assertIn(phrase.casefold(), doc.casefold())
        for token in ("QA1499_ACK_SYNTHETIC_TEST_ONLY", "QA1499_HEAD_SHA",
                      "QA1499_SESSION_DIR", "git", "rev-parse", "maxRedirects: 0",
                      "request.method() !== 'GET'", "routeWebSocket",
                      "release_sha !== sha", "serviceWorkers: 'block'",
                      "synthetic_only !== true", "state: 'NO_VERIFICADO'"):
            with self.subTest(token=token):
                self.assertIn(token, source)
        self.assertNotIn("page.fill(", source)
        self.assertNotIn("page.screenshot(", source)
        self.assertNotIn("fs.writeFileSync", source)
        check = subprocess.run(["node", "--check", str(SCRIPT)], cwd=ROOT,
                               text=True, capture_output=True, timeout=15, check=False)
        self.assertEqual(check.returncode, 0, check.stderr)


if __name__ == "__main__":
    unittest.main()
