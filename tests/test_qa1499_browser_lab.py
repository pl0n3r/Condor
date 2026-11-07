"""Contrato Condor #1819: laboratorio de navegador local y fail-closed."""
from __future__ import annotations

import os
from pathlib import Path
import shutil
import subprocess
import unittest

ROOT = Path(__file__).resolve().parents[1]
SCRIPT = ROOT / "scripts/qa1499-browser-lab.cjs"
DOC = ROOT / "docs/verificacion/browser-lab-1499.md"


def node_run(*args: str, env_override: dict[str, str] | None = None) -> subprocess.CompletedProcess[str]:
    runner = shutil.which("node")
    if runner is None:
        raise AssertionError("node necesario para verificar la guarda del laboratorio")
    env = os.environ.copy()
    for key in (
        "QA1499_BASE_URL", "QA1499_ACK_SYNTHETIC_TEST_ONLY",
        "QA1499_AXE_SCRIPT", "QA1499_HEAD_SHA", "QA1499_OUTPUT"
    ):
        env.pop(key, None)
    env.update(env_override or {})
    return subprocess.run(
        [runner, str(SCRIPT), *args],
        env=env, text=True, capture_output=True, timeout=15,
        check=False
    )


class BrowserLabContractTests(unittest.TestCase):
    def test_self_test_requires_loopback(self) -> None:
        self.assertTrue(SCRIPT.is_file(), str(SCRIPT))
        result = node_run("--self-test")
        self.assertEqual(result.returncode, 0, result.stderr)
        self.assertIn("QA1499_SELF_TEST_PASS", result.stdout)

    def test_live_url_rejected(self) -> None:
        for value in (
            "https://www.condorapp.com.co",
            "http://condorapp.com.co:18772",
            "https://localhost:18772",
            "http://localhost:18772/?token=secret",
            "http://user:pass@127.0.0.1:18772/"
        ):
            with self.subTest(url=value):
                result = node_run(env_override={"QA1499_BASE_URL": value})
                self.assertNotEqual(result.returncode, 0)
                self.assertIn("Solo se permite HTTP loopback", result.stderr)
                self.assertNotIn("QA1499_SELF_TEST_PASS", result.stdout)

    def test_explicit_synthetic_and_axe_required(self) -> None:
        env = {
            "QA1499_BASE_URL": "http://127.0.0.1:18772",
            "QA1499_HEAD_SHA": "a" * 40,
        }
        denied = node_run(env_override=env)
        self.assertNotEqual(denied.returncode, 0)
        self.assertIn("QA1499_ACK_SYNTHETIC_TEST_ONLY", denied.stderr)
        env["QA1499_ACK_SYNTHETIC_TEST_ONLY"] = "1"
        denied = node_run(env_override=env)
        self.assertNotEqual(denied.returncode, 0)
        self.assertIn("QA1499_AXE_SCRIPT", denied.stderr)
        env["QA1499_HEAD_SHA"] = "not-a-sha"
        denied = node_run(env_override=env)
        self.assertNotEqual(denied.returncode, 0)
        self.assertIn("QA1499_HEAD_SHA", denied.stderr)

    def test_document_evidence_is_limited(self) -> None:
        text = DOC.read_text(encoding="utf-8")
        for required in (
            "cd01e123fff4513f5082a9116e2b285ba975ccbe",
            "12/12", "Chrome 156", "WebKit 26.6", "axe-core 4.10.3",
            "NO VERIFICADO", "INP", "LCP", "CLS", "tenant", "plataforma",
            "APP_ENV=test", "solo localhost", "No autoriza go-live",
            "public/marketing.css", "68827", "65536", "#1425"
        ):
            with self.subTest(required=required):
                self.assertIn(required, text)
        source = SCRIPT.read_text(encoding="utf-8")
        for guard in ("localBase(", "route.abort('blockedbyclient')", "QA1499_AXE_SCRIPT", "Number(style.opacity) > 0.995"):
            self.assertIn(guard, source)


if __name__ == "__main__":
    unittest.main()
