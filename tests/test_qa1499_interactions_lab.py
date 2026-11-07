"""Checks fail-closed for the synthetic Chrome keyboard/vitals laboratory (#1830)."""
from __future__ import annotations

import os
import subprocess
import unittest
from pathlib import Path

ROOT = Path(__file__).resolve().parents[1]
LAB = ROOT / "scripts/qa1499-interactions-lab.cjs"
DOC = ROOT / "docs/verificacion/browser-interactions-1499.md"


def launch(*args: str, **overrides: str) -> subprocess.CompletedProcess[str]:
    env = dict(os.environ)
    for key in (
        "QA1499_BASE_URL", "QA1499_HEAD_SHA",
        "QA1499_ACK_SYNTHETIC_TEST_ONLY", "QA1499_OUTPUT",
    ):
        env.pop(key, None)
    env.update(overrides)
    return subprocess.run(
        ["node", str(LAB), *args], env=env,
        text=True, capture_output=True, timeout=15, check=False,
    )


class InteractionsLabContractTests(unittest.TestCase):
    def test_self_test_rejects_live(self) -> None:
        self.assertTrue(LAB.is_file())
        result = launch("--self-test")
        self.assertEqual(result.returncode, 0, result.stderr)
        self.assertIn("QA1499_INTERACTIONS_SELF_TEST_PASS", result.stdout)
        for url in (
            "https://condorapp.com.co",
            "http://example.test:18777",
            "http://user:secret@localhost:18777",
            "http://127.0.0.1:18777/?session=secret",
        ):
            with self.subTest(url=url):
                bad = launch(
                    QA1499_BASE_URL=url,
                    QA1499_HEAD_SHA="a" * 40,
                    QA1499_ACK_SYNTHETIC_TEST_ONLY="1",
                )
                self.assertNotEqual(bad.returncode, 0)
                self.assertNotIn("secret", bad.stderr)
                self.assertNotIn("condorapp.com.co", bad.stderr)

    def test_missing_ack_and_sha_fail_closed(self) -> None:
        local = "http://127.0.0.1:18777"
        for environment in (
            {"QA1499_BASE_URL": local},
            {"QA1499_BASE_URL": local, "QA1499_HEAD_SHA": "a" * 40},
            {"QA1499_BASE_URL": local, "QA1499_ACK_SYNTHETIC_TEST_ONLY": "1"},
            {"QA1499_BASE_URL": local, "QA1499_ACK_SYNTHETIC_TEST_ONLY": "1",
             "QA1499_HEAD_SHA": "main"},
        ):
            with self.subTest(keys=tuple(environment)):
                result = launch(**environment)
                self.assertNotEqual(result.returncode, 0)
                self.assertIn("Preflight o navegador local no disponibles", result.stderr)
        invalid_arg = launch("--run")
        self.assertNotEqual(invalid_arg.returncode, 0)
        self.assertIn("--self-test", invalid_arg.stderr)

    def test_document_rejects_live_claims(self) -> None:
        self.assertTrue(DOC.is_file())
        doc = DOC.read_text(encoding="utf-8")
        script = LAB.read_text(encoding="utf-8")
        for token in (
            "#1499", "NO VERIFICADO", "LCP", "CLS", "INP",
            "p75", "Chrome", "teclado", "APP_ENV=test",
            "go-live", "QA1499_ACK_SYNTHETIC_TEST_ONLY",
        ):
            self.assertIn(token.casefold(), doc.casefold())
        for required in (
            "new PerformanceObserver", "largest-contentful-paint",
            "layout-shift", "durationThreshold: 16",
            "page.keyboard.press('Tab')", "serviceWorkers: 'block'",
            "request.abort('blockedbyclient')", "release_approved: false",
            "field p75 for LCP, INP, CLS", "event_timing_supported",
            "console.log(JSON.stringify({ summary, ...results }",
        ):
            self.assertIn(required, script)
        self.assertNotIn("https://www.condorapp.com.co", script)
        self.assertNotIn("page.fill(", script)
        self.assertNotIn("page.type(", script)
        self.assertNotIn("localStorage", script)
        self.assertNotIn("fs.writeFileSync", script)


if __name__ == "__main__":
    unittest.main()
