#!/usr/bin/env python3
"""Regresiones offline del smoke sintético, sin acceso a producción."""
from __future__ import annotations

import ast
import importlib.util
import json
import unittest
from datetime import datetime, timezone
from pathlib import Path

ROOT = Path(__file__).resolve().parents[1]
MODULE = ROOT / "scripts" / "authenticated_production_smoke.py"
WORKFLOW = ROOT / ".github" / "workflows" / "authenticated-production-smoke.yml"
SPEC = importlib.util.spec_from_file_location("authenticated_production_smoke", MODULE)
assert SPEC is not None and SPEC.loader is not None
smoke = importlib.util.module_from_spec(SPEC)
import sys
sys.modules[SPEC.name] = smoke
SPEC.loader.exec_module(smoke)

SHA = "a" * 40
NOW = datetime(2026, 10, 10, 7, 0, tzinfo=timezone.utc)


class FixtureClient:
    def __init__(self):
        self.calls = []
        self.responses = {
            ("GET", "/health"): smoke.Reply(200, json.dumps({
                "status": "ok", "version": "0.1.194", "release_sha": SHA,
                "schema_up_to_date": True,
            }).encode()),
            ("GET", "/admin/login"): smoke.Reply(
                200, b'<form><input name="_csrf_token" value="csrf-test"></form>'
            ),
            ("POST", "/admin/login"): smoke.Reply(200, b'<section id="condor-admin-root"></section>'),
            ("GET", "/admin"): smoke.Reply(
                200, b'<section id="condor-admin-root"></section>'
            ),
            ("GET", "/api/v1/context"): smoke.Reply(200, json.dumps({
                "tenant": {"id": "synthetic-tenant", "slug": "synthetic"},
                "active_branch": {"id": "branch-synthetic"},
                "branches": [{"id": "branch-synthetic"}],
            }).encode()),
            ("GET", "/adminpl0n3r"): smoke.Reply(403),
            ("GET", smoke.BAD_BRANCH): smoke.Reply(403),
        }

    def request(self, method, path, data=None):
        self.calls.append((method, path, data))
        return self.responses[(method, path)]


class AuthenticatedProductionSmokeTests(unittest.TestCase):
    def test_workflow_skips_without_synthetic_identity_and_has_read_only_permissions(self):
        workflow = WORKFLOW.read_text(encoding="utf-8")
        self.assertIn("workflow_dispatch:", workflow)
        self.assertIn("schedule:", workflow)
        self.assertIn("github.ref == 'refs/heads/main'", workflow)
        self.assertEqual(workflow.count("vars.CONDOR_AUTH_SMOKE_ENABLED == 'true'"), 2)
        self.assertIn("needs.identity.outputs.configured == 'true'", workflow)
        self.assertNotIn("vars.CONDOR_AUTH_SMOKE_ENABLED != 'false'", workflow)
        self.assertIn("needs.identity.outputs.configured == 'true'", workflow)
        self.assertIn("SKIPPED: identidad sintética no configurada", workflow)
        self.assertIn("permissions:\n  contents: read", workflow)
        self.assertNotIn("contents: write", workflow)
        self.assertNotIn("issues: write", workflow)
        self.assertIn("secrets.CONDOR_SMOKE_EMAIL", workflow)
        self.assertIn("secrets.CONDOR_SMOKE_PASSWORD", workflow)
        self.assertIn("persist-credentials: false", workflow)
        self.assertIn("EXPECTED_MAIN_SHA:", workflow)
        self.assertNotIn("set -x", workflow)

    def test_sonar_triage_is_read_only_and_fails_closed(self):
        workflow = WORKFLOW.read_text(encoding="utf-8")
        sonar = workflow.split("  sonar_triage:\n", 1)[1].split("  identity:\n", 1)[0]
        self.assertIn("github.event_name == 'pull_request'", sonar)
        self.assertIn("checks: read", sonar)
        self.assertIn("PR_HEAD_SHA:", sonar)
        self.assertIn("PR_NUMBER:", sonar)
        self.assertIn("SonarCloud%20Code%20Analysis", sonar)
        self.assertIn("componentKeys=pl0n3r_Condor", sonar)
        self.assertIn("total != expected", sonar)
        self.assertIn("raise Unverified()", sonar)
        self.assertIn("for attempt in range(12):", sonar)
        self.assertIn("time.sleep(8)", sonar)
        self.assertIn("if attempt < 11:", sonar)
        # Fail closed when the external Sonar check never becomes complete.
        self.assertIn('else:\n                  raise Unverified()', sonar)
        script = sonar.split("          python3 - <<'PY'\n", 1)[1].split("          PY\n", 1)[0]
        ast.parse("\n".join(line[10:] for line in script.splitlines()))
        self.assertIn('state = "UNKNOWN"', sonar)
        self.assertIn('sys.exit(0 if state == "NO_HIGH_FINDINGS" else 1)', sonar)
        self.assertNotIn("secrets.", sonar)
        self.assertNotIn("condorapp.com.co", sonar)

    def test_login_csrf_and_same_origin_are_mandatory(self):
        client = FixtureClient()
        report = smoke.probe(client, "synthetic@example.test", "secret-only-test",
                             SHA, expected_version="0.1.194", clock=lambda: NOW)
        self.assertEqual(report["state"], "VALIDATED")
        methods = [call[0] for call in client.calls]
        self.assertEqual(methods.count("POST"), 1)
        self.assertEqual(client.calls[2], ("POST", "/admin/login", {
            "_username": "synthetic@example.test",
            "_password": "secret-only-test", "_csrf_token": "csrf-test",
        }))
        redirect = smoke.SafeLoginRedirect()
        request = smoke.Request(smoke.ORIGIN + smoke.LOGIN, data=b"_username=test", method="POST")
        followed = redirect.redirect_request(
            request, None, 302, "Found", {}, smoke.ORIGIN + "/admin"
        )
        self.assertEqual("GET", followed.get_method())
        self.assertEqual(smoke.ORIGIN + "/admin", followed.full_url)
        with self.assertRaises(smoke.SmokeFailure):
            redirect.redirect_request(request, None, 302, "Found", {},
                                      "https://attacker.invalid/admin")
        self.assertIsNone(redirect.redirect_request(
            smoke.Request(smoke.ORIGIN + "/admin", method="GET"),
            None, 302, "Found", {}, smoke.ORIGIN + "/admin"
        ))
        self.assertEqual("/admin", smoke.target("/admin"))
        for location in ("https://attacker.invalid/admin", "//attacker.invalid/admin",
                         "/adminpl0n3r", "/admin?token=secret", ""):
            with self.subTest(location=location):
                with self.assertRaises(smoke.SmokeFailure):
                    smoke.target(location)
        for method, path in (("DELETE", "/admin"), ("POST", "/health"),
                             ("POST", "/api/v1/context"), ("GET", "/api/v1/tenants")):
            with self.subTest(method=method, path=path):
                with self.assertRaises(smoke.SmokeFailure):
                    smoke.Client().request(method, path, {})
        client.responses[("GET", "/admin/login")] = smoke.Reply(200, b"<form></form>")
        with self.assertRaises(smoke.SmokeFailure):
            smoke.probe(client, "synthetic@example.test", "secret-only-test", SHA, expected_version="0.1.194")

    def test_tenant_auth_dashboard_and_cross_scope_denial(self):
        client = FixtureClient()
        report = smoke.probe(client, "synthetic@example.test", "synthetic-pass", SHA, expected_version="0.1.194", clock=lambda: NOW)
        self.assertFalse(report["tenant_safe"])
        self.assertTrue(report["tenant_context_consistent"])
        self.assertTrue(report["unknown_branch_denied"])
        self.assertFalse(report["cross_tenant_verified"])
        self.assertEqual("0.1.194", report["version"])
        self.assertEqual(SHA, report["release_sha"])
        self.assertTrue(all(m == "GET" for m, p, d in client.calls if p != "/admin/login"))
        for path in ("/adminpl0n3r", smoke.BAD_BRANCH):
            broken = FixtureClient()
            broken.responses[("GET", path)] = smoke.Reply(200)
            with self.subTest(path=path):
                with self.assertRaises(smoke.SmokeFailure):
                    smoke.probe(broken, "synthetic@example.test", "synthetic-pass", SHA, expected_version="0.1.194")
        broken = FixtureClient()
        broken.responses[("GET", "/api/v1/context")] = smoke.Reply(
            200, b'{"tenant":{"id":"t"},"branches":[{"id":"b"}],"active_branch":{"id":"other"}}'
        )
        with self.assertRaises(smoke.SmokeFailure):
            smoke.probe(broken, "synthetic@example.test", "synthetic-pass", SHA, expected_version="0.1.194")

    def test_health_sha_version_and_safe_evidence_fail_closed(self):
        client = FixtureClient()
        valid = smoke.probe(client, "synthetic@example.test", "never-print-this",
                            SHA, expected_version="0.1.194", clock=lambda: NOW)
        evidence = json.dumps(valid)
        for secret in ("synthetic@example.test", "never-print-this", "csrf-test",
                       "synthetic-tenant", "branch-synthetic"):
            self.assertNotIn(secret, evidence)
        self.assertEqual("2026-10-10T07:00:00Z", valid["observed_at"])
        for changes in ({"release_sha": "b" * 40}, {"schema_up_to_date": False},
                        {"status": "failed"}, {"version": "broken"},
                        {"version": "9.9.9"}):
            with self.subTest(changes=changes):
                broken = FixtureClient()
                current = json.loads(broken.responses[("GET", "/health")].body)
                current.update(changes)
                broken.responses[("GET", "/health")] = smoke.Reply(
                    200, json.dumps(current).encode()
                )
                with self.assertRaises(smoke.SmokeFailure):
                    smoke.probe(broken, "synthetic@example.test", "private", SHA, expected_version="0.1.194")

    def test_health_identity_remains_stable_through_the_full_probe(self):
        stable = FixtureClient()
        report = smoke.probe(stable, "synthetic@example.test", "private",
                             SHA, expected_version="0.1.194", clock=lambda: NOW)
        self.assertEqual("VALIDATED", report["state"])
        self.assertEqual(2, sum(method == "GET" and path == "/health"
                                for method, path, _ in stable.calls))

        class ChangingHealth(FixtureClient):
            def __init__(self):
                super().__init__()
                self.health_reads = 0

            def request(self, method, path, data=None):
                if method == "GET" and path == "/health":
                    self.health_reads += 1
                    if self.health_reads == 2:
                        return smoke.Reply(200, json.dumps({
                            "status": "ok", "version": "0.1.194",
                            "release_sha": "b" * 40, "schema_up_to_date": True,
                        }).encode())
                return super().request(method, path, data)

        changed = ChangingHealth()
        with self.assertRaises(smoke.SmokeFailure):
            smoke.probe(changed, "synthetic@example.test", "private",
                        SHA, expected_version="0.1.194", clock=lambda: NOW)
        self.assertEqual(2, changed.health_reads)

    def test_json_duplicate_keys_fail_closed_for_health_and_tenant(self):
        conflicting_health = FixtureClient()
        conflicting_health.responses[("GET", "/health")] = smoke.Reply(
            200, ('{"status":"ok","version":"0.1.194",'
                  '"release_sha":"' + "b" * 40 + '",'
                  '"schema_up_to_date":true,'
                  '"release_sha":"' + SHA + '"}').encode())
        with self.assertRaises(smoke.SmokeFailure):
            smoke.probe(conflicting_health, "synthetic@example.test", "private",
                        SHA, expected_version="0.1.194", clock=lambda: NOW)

        conflicting_tenant = FixtureClient()
        conflicting_tenant.responses[("GET", "/api/v1/context")] = smoke.Reply(
            200, b'{"tenant":{"id":"synthetic","id":"different"},'
                 b'"active_branch":{"id":"branch-1"},'
                 b'"branches":[{"id":"branch-1"}]}')
        with self.assertRaises(smoke.SmokeFailure):
            smoke.probe(conflicting_tenant, "synthetic@example.test", "private",
                        SHA, expected_version="0.1.194", clock=lambda: NOW)

    def test_context_rejects_conflicting_legal_entity_projection(self):
        inconsistent = FixtureClient()
        inconsistent.responses[("GET", "/api/v1/context")] = smoke.Reply(
            200, json.dumps({
                "tenant": {"id": "synthetic"},
                "active_branch": {
                    "id": "branch-1", "legal_entity": {"id": "entity-A"},
                },
                "branches": [{
                    "id": "branch-1", "legal_entity": {"id": "entity-B"},
                }],
                "active_legal_entity": {"id": "entity-A"},
            }).encode())
        with self.assertRaises(smoke.SmokeFailure):
            smoke.probe(inconsistent, "synthetic@example.test", "private",
                        SHA, expected_version="0.1.194", clock=lambda: NOW)


if __name__ == "__main__":
    unittest.main()
