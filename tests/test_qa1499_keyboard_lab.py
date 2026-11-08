"""Contract for an opt-in synthetic browser keyboard lab, Condor #1877."""
from __future__ import annotations

import os
from pathlib import Path
import subprocess
import unittest

ROOT = Path(__file__).resolve().parents[1]
SCRIPT = ROOT / "scripts/qa1499-keyboard-lab.cjs"
DOC = ROOT / "docs/verificacion/qa1499-keyboard-lab.md"


def node(*args: str, **overrides: str) -> subprocess.CompletedProcess[str]:
    env = dict(os.environ)
    for key in ("QA1499_BASE_URL", "QA1499_ACK_SYNTHETIC_TEST_ONLY", "QA1499_HEAD_SHA", "QA1499_OUTPUT"):
        env.pop(key, None)
    env.update(overrides)
    return subprocess.run([ "node", str(SCRIPT), *args], capture_output=True,
                          text=True, timeout=15, check=False, env=env)


class KeyboardLabContractTests(unittest.TestCase):
    def test_self_test_and_live_rejected(self) -> None:
        self.assertTrue(SCRIPT.is_file())
        result = node("--self-test")
        self.assertEqual(result.returncode, 0, result.stderr)
        self.assertIn("QA1499_KEYBOARD_SELF_TEST_PASS", result.stdout)
        for url in ("https://condorapp.com.co", "http://www.condorapp.com.co:18771",
                    "http://localhost:18771/?token=123", "http://user:pass@localhost:18771/"):
            with self.subTest(url=url):
                result = node(QA1499_BASE_URL=url,
                              QA1499_ACK_SYNTHETIC_TEST_ONLY="1",
                              QA1499_HEAD_SHA="a" * 40)
                self.assertNotEqual(result.returncode, 0)
                self.assertIn("QA1499_KEYBOARD_BLOCKED", result.stderr)
                self.assertNotIn("pass", result.stderr)

    def test_safe_contract(self) -> None:
        source = SCRIPT.read_text(encoding="utf-8")
        self.assertEqual(node("--self-test").returncode, 0)
        self.assertEqual(node(QA1499_BASE_URL="http://127.0.0.1:18771",
                              QA1499_HEAD_SHA="a" * 40).returncode, 1)
        self.assertEqual(node(QA1499_BASE_URL="http://127.0.0.1:18771",
                              QA1499_ACK_SYNTHETIC_TEST_ONLY="1",
                              QA1499_HEAD_SHA="main").returncode, 1)
        for guard in ("routeWebSocket('**/*'", "request.abort('blockedbyclient')",
                      "QA1499_ACK_SYNTHETIC_TEST_ONLY", "SHA_PATTERN",
                      "const ROUTES = Object.freeze(['/', '/producto', '/admin/login'])",
                      "name: 'desktop'", "name: 'mobile'", "'Chrome'", "'Playwright WebKit'",
                      "engine_unavailable", "completeKeyboardAudit: false", "wcagCertified: false", "fieldVitalsCertified: false",
                      "sourceShaDeclared", "process.stdout.write"):
            with self.subTest(guard=guard):
                self.assertIn(guard, source)
        self.assertEqual(subprocess.run(["node", "--check", str(SCRIPT)],
                                        check=False, capture_output=True).returncode, 0)

    def test_document_caveats(self) -> None:
        doc = DOC.read_text(encoding="utf-8")
        for required in ("#1499", "#1868/A", "#1877", "localhost", "12 escenarios",
                         "NO_VERIFICADO", "PARCIAL", "sin credenciales", "no es Safari nativo",
                         "NO certifica WCAG 2.2 AA", "no autoriza go-live", "SHA exacto"):
            with self.subTest(token=required):
                self.assertIn(required, doc)


if __name__ == "__main__":
    unittest.main()
