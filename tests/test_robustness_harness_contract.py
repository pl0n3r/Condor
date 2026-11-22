#!/usr/bin/env python3
"""Contratos ejecutables del harness de guardado sintético (#1955)."""
from __future__ import annotations

import json
import re
import sys
import tempfile
import unittest
from pathlib import Path
from types import SimpleNamespace
from unittest.mock import patch

ROOT = Path(__file__).resolve().parents[1]
sys.path.insert(0, str(ROOT / "tests" / "robustness"))
import save_endpoints_fuzz as harness  # noqa: E402
import save_roundtrip_fuzz as roundtrip  # noqa: E402


class RobustnessHarnessContractTests(unittest.TestCase):
    def test_discovers_every_post_put_patch_on_authenticated_api(self) -> None:
        router = {
            "multi": {"path": "/api/v1/branches/{branchId}/items", "method": "GET|POST|PUT|PATCH"},
            "single": {"path": "/api/v1/orders", "method": "POST"},
            "public": {"path": "/api/public/contact", "method": "POST"},
            "read": {"path": "/api/v1/context", "method": "GET"},
            "not_api": {"path": "/admin/login", "method": "POST"},
            "list_method": {"path": "/api/v1/tools", "method": ["PUT", "PATCH"]},
        }
        with patch.object(harness.subprocess, "run",
                          return_value=SimpleNamespace(stdout=json.dumps(router))) as run:
            actual = harness.discover_routes()
        self.assertEqual(set(actual), {
            ("POST", "/api/v1/branches/{branchId}/items"),
            ("PUT", "/api/v1/branches/{branchId}/items"),
            ("PATCH", "/api/v1/branches/{branchId}/items"),
            ("POST", "/api/v1/orders"),
            ("PUT", "/api/v1/tools"),
            ("PATCH", "/api/v1/tools"),
        })
        self.assertEqual(len(actual), len(set(actual)), "No duplicar rutas ni métodos")
        self.assertIn("debug:router", run.call_args.args[0])
        self.assertIn("--format=json", run.call_args.args[0])

    def test_empty_discovery_does_not_create_false_green(self) -> None:
        class FakeClient:
            def login(self) -> None:
                pass

            def req(self, method, path, body=None, ctype="application/json", **kwargs):
                return 200, '{"active_branch":{"id":"synthetic-branch"}}', .001

        with patch.object(harness, "Client", FakeClient), \
             patch.object(harness, "EMAIL", "owner-ci@example.test"), \
             patch.object(harness, "PASSWORD", "synthetic"), \
             patch.object(harness, "discover_routes", return_value=[]):
            self.assertEqual(harness.main(), 1, "Sin rutas no se acepta éxito vacuo")

    def test_ci_credentials_cannot_leave_local_server(self) -> None:
        import urllib.error
        import urllib.request

        self.assertEqual(harness.BASE, "http://127.0.0.1:8091")
        handler = harness.LoopbackOnlyRedirect()
        request = urllib.request.Request(harness.BASE + "/admin/login")
        with self.assertRaises(urllib.error.HTTPError):
            handler.redirect_request(
                request, None, 302, "Found", {}, "https://example.test/steal-session"
            )
        safe = handler.redirect_request(
            request, None, 302, "Found", {}, harness.BASE + "/admin"
        )
        self.assertEqual(safe.full_url, harness.BASE + "/admin")

    def test_baseline_requires_exact_issue_and_no_duplicate_signatures(self) -> None:
        source = json.loads((ROOT / "tests/robustness/known_findings.json").read_text(encoding="utf-8"))
        self.assertIsInstance(source["known"], list)
        self.assertGreater(len(source["known"]), 0)
        self.assertEqual(len(harness.load_baseline()), len(source["known"]))
        for entry in source["known"]:
            self.assertEqual(set(entry), {"endpoint", "problema", "issue"})
            self.assertRegex(entry["issue"], r"^#[1-9][0-9]*$")
            self.assertTrue(entry["endpoint"])
            self.assertTrue(entry["problema"])
        invalid = [
            [{"endpoint": "/api/v1/x", "problema": "HTTP 500"}],
            [{"endpoint": "/api/v1/x", "problema": "HTTP 500", "issue": "sin issue"}],
            [source["known"][0], source["known"][0]],
            "permitir todo",
        ]
        with tempfile.TemporaryDirectory() as temp:
            path = Path(temp) / "baseline.json"
            for entries in invalid:
                with self.subTest(entries=entries):
                    path.write_text(json.dumps({"known": entries}), encoding="utf-8")
                    with self.assertRaises(ValueError):
                        harness.load_baseline(str(path))

    def test_unlisted_regression_forces_nonzero_exit(self) -> None:
        class FakeClient:
            def login(self) -> None:
                pass

            def req(self, method, path, body=None, ctype="application/json", **kwargs):
                if method == "GET":
                    return 200, '{"active_branch":{"id":"synthetic-branch"}}', .001
                return 503, '{"error":"internal_error"}', .001

        signature = "POST /api/v1/hr/employees | HTTP 503"
        self.assertIn(signature, harness.load_baseline())
        with patch.object(harness, "Client", FakeClient), \
             patch.object(harness, "EMAIL", "owner-ci@example.test"), \
             patch.object(harness, "PASSWORD", "synthetic"), \
             patch.object(harness, "discover_routes",
                          return_value=[("POST", "/api/v1/hr/employees")]), \
             patch.object(harness, "RAW_BODIES", [("bad", b"null", "application/json")]), \
             patch.object(harness, "WEIRD_VALUES", []):
            with patch.object(harness, "load_baseline", return_value={signature}):
                self.assertEqual(harness.main(), 0, "Known issues are reported but do not gate")
            with patch.object(harness, "load_baseline", return_value=set()):
                self.assertEqual(harness.main(), 1, "Removing an entry must break CI")

    def test_corrupt_baseline_never_silently_disables_failures(self) -> None:
        with tempfile.TemporaryDirectory() as temp:
            missing = Path(temp) / "no-baseline.json"
            with self.assertRaises(FileNotFoundError):
                harness.load_baseline(str(missing))

    def test_roundtrip_uses_same_strict_ledger_and_real_synthetic_recipe(self) -> None:
        self.assertIs(roundtrip.base, harness)
        self.assertTrue(roundtrip.RECIPES)
        for recipe in roundtrip.RECIPES:
            self.assertTrue(recipe["create"].startswith("/api/"))
            self.assertTrue(recipe["list"].startswith("/api/"))
            self.assertIn(recipe["id_field"], recipe["base"]())
            self.assertTrue(recipe["fields"])

    def test_workflow_runs_live_fuzzers_only_after_synthetic_onboarding(self) -> None:
        ci = (ROOT / ".github/workflows/ci.yml").read_text(encoding="utf-8")
        scope = ci[ci.index("    name: Backend PHP / MariaDB"):]
        self.assertLess(scope.index("Probar onboarding transaccional"),
                        scope.index("Robustez de guardado HTTP sintético"))
        self.assertLess(scope.index("Robustez de guardado HTTP sintético"),
                        scope.index("Smoke HTTP y login real"))
        for anchor in (
            "python3 -m unittest tests/test_robustness_harness_contract.py",
            "php -S 127.0.0.1:8091 -t public public/router.php",
            "CONDOR_FUZZ_BASE_URL=http://127.0.0.1:8091",
            "CONDOR_FUZZ_EMAIL=owner-ci@example.test",
            "CONDOR_FUZZ_PASSWORD=\"$CONDOR_OWNER_PASSWORD\"",
            "python3 tests/robustness/save_endpoints_fuzz.py",
            "python3 tests/robustness/save_roundtrip_fuzz.py",
            "kill \"$server_pid\"",
        ):
            with self.subTest(anchor=anchor):
                self.assertIn(anchor, scope)


if __name__ == "__main__":
    unittest.main()
