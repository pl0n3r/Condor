"""Checks fail-closed for the synthetic Chrome keyboard/vitals laboratory (#1830)."""
from __future__ import annotations

import json
import os
import subprocess
import threading
import unittest
from http.server import BaseHTTPRequestHandler, ThreadingHTTPServer
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
                for output in (bad.stderr, bad.stdout):
                    self.assertNotIn("secret", output)
                    self.assertNotIn("condorapp.com.co", output)

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

    @unittest.skipUnless(
        os.environ.get("QA1499_RUN_NETWORK_TEST") == "1",
        "Browser/Chrome is optional; run this explicit synthetic localhost QA on an isolated host",
    )
    def test_network_boundary_blocks_cross_origin_resources_redirects_and_ws(self) -> None:
        """Two localhost servers simulate a foreign origin without any public network."""
        foreign_hits: list[str] = []

        class ForeignHandler(BaseHTTPRequestHandler):
            def do_GET(self) -> None:
                foreign_hits.append(self.path)
                self.send_response(200)
                self.end_headers()

            def log_message(self, *_args: object) -> None:
                pass

        foreign = ThreadingHTTPServer(("127.0.0.1", 0), ForeignHandler)
        foreign_port = foreign.server_address[1]

        class AppHandler(BaseHTTPRequestHandler):
            def do_GET(self) -> None:
                if self.path == "/producto":
                    self.send_response(302)
                    self.send_header(
                        "Location", f"http://127.0.0.1:{foreign_port}/redirect",
                    )
                    self.end_headers()
                    return
                if self.path not in ("/", "/admin/login"):
                    self.send_response(404)
                    self.end_headers()
                    return
                body = (
                    '<!doctype html><html><body><h1>Laboratorio sintético</h1>'
                    '<a href="#local">Enlace de prueba</a>'
                    f'<img src="http://127.0.0.1:{foreign_port}/image">'
                    f'<script>fetch("http://127.0.0.1:{foreign_port}/fetch").catch(()=>{{}});'
                    f'new WebSocket("ws://127.0.0.1:{foreign_port}/ws");</script>'
                    "</body></html>"
                ).encode("utf-8")
                self.send_response(200)
                self.send_header("Content-Type", "text/html; charset=utf-8")
                self.send_header("Content-Length", str(len(body)))
                self.end_headers()
                self.wfile.write(body)

            def log_message(self, *_args: object) -> None:
                pass

        app = ThreadingHTTPServer(("127.0.0.1", 0), AppHandler)
        threads = [
            threading.Thread(target=server.serve_forever, daemon=True)
            for server in (foreign, app)
        ]
        try:
            for thread in threads:
                thread.start()
            env = dict(os.environ)
            env.update(
                QA1499_BASE_URL=f"http://127.0.0.1:{app.server_address[1]}",
                QA1499_HEAD_SHA="a" * 40,
                QA1499_ACK_SYNTHETIC_TEST_ONLY="1",
            )
            env.pop("QA1499_OUTPUT", None)
            result = subprocess.run(
                ["node", str(LAB)], env=env, text=True,
                capture_output=True, timeout=65, check=False,
            )
            self.assertEqual(result.returncode, 2, result.stderr)
            payload = json.loads(result.stdout)
            self.assertEqual(payload["summary"]["cases"], 6)
            self.assertEqual(payload["summary"]["failed"], 2)
            self.assertEqual(
                sorted(row["route"] for row in payload["results"] if row.get("error")),
                ["/producto", "/producto"],
            )
            self.assertEqual(foreign_hits, [], "A cross-origin request reached the server")
        finally:
            for server in (app, foreign):
                server.shutdown()
                server.server_close()
            for thread in threads:
                thread.join(timeout=2)

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
            "routeWebSocket('**/*'", "proxy: { server:",
            "finalUrl.pathname !== route", "cls: null",
            "net.createServer(socket => socket.destroy())",
        ):
            self.assertIn(required, script)
        self.assertNotIn("https://www.condorapp.com.co", script)
        self.assertNotIn("page.fill(", script)
        self.assertNotIn("page.type(", script)
        self.assertNotIn("localStorage", script)
        self.assertNotIn("fs.writeFileSync", script)


if __name__ == "__main__":
    unittest.main()
