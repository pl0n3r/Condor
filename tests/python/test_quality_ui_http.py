#!/usr/bin/env python3
"""#1582: deterministic HTTP/UI contract for synthetic M13 internal complaints."""
from pathlib import Path
import re
import unittest

ROOT = Path(__file__).resolve().parents[2]
UI = ROOT / "frontend/admin/QualityManagement.tsx"
CONTROLLER = ROOT / "src/Http/Controller/QualityController.php"
HTTP = ROOT / "tests/php/Http/QualityControllerHttpTest.php"


class QualityHttpUiTests(unittest.TestCase):
    @classmethod
    def setUpClass(cls):
        cls.ui = UI.read_text(encoding="utf-8")
        cls.remote = cls.ui.split("// M13 S03 HTTP:", 1)[1]
        cls.controller = CONTROLLER.read_text(encoding="utf-8")
        cls.http = HTTP.read_text(encoding="utf-8")

    def test_real_api_contract_and_isolation(self):
        for fragment in (
            "new XMLHttpRequest()", "request.withCredentials = true",
            "request.setRequestHeader('Accept', 'application/json')",
            "request.setRequestHeader('X-CSRF-Token', csrf ?? '')",
            "document.getElementById('condor-admin-root')",
            "getAttribute('data-access-token')",
            "encodeURIComponent(branchId) + '/quality/records'",
            "tenantId, companyId, branchId, endpoint, canView, revision",
            "body.synthetic_only === true", "row.kind === 'QUEJA_INTERNA'",
            "qualityHttp(endpoint)", "qualityHttp(url, method, payload, token)",
        ):
            self.assertIn(fragment, self.remote)
        self.assertNotIn("tenant_id:", self.remote)
        self.assertNotIn("company_id:", self.remote)
        self.assertNotIn("localStorage", self.remote)
        self.assertNotIn("sessionStorage", self.remote)

    def test_loading_empty_error_and_permission_denied_are_explicit(self):
        for fragment in (
            "{ kind: 'loading' | 'denied' }",
            "{ kind: 'error'; message: string }",
            "{ kind: 'ready'; records: ServerQuality[] }",
            "setLoad({ kind: 'loading' })", "setLoad({ kind: 'denied' })",
            "setLoad({ kind: 'ready', records: body.records })",
            "setLoad({ kind: 'error', message:",
            "load.kind === 'loading'", "load.kind === 'denied'",
            "load.kind === 'error'", "load.kind === 'ready'",
            "records.length === 0", "Reintentar",
            "request.abort()", "setRevision(value => value + 1)",
            "role=\"alert\"", "role=\"status\"", "aria-live=\"polite\"",
        ):
            self.assertIn(fragment, self.remote)

    def test_csrf_cross_tenant_role_body_and_state_real_http(self):
        for fragment in (
            "extends WebTestCase",
            "loginUser($owner)", "loginUser($viewer)", "loginUser($foreign)",
            "jsonRequest('POST', $baseA", "jsonRequest('PATCH', $state",
            "request('GET', $baseB)", "assertResponseStatusCodeSame(403)",
            "assertResponseStatusCodeSame(404)", "assertResponseStatusCodeSame(422)",
            "assertResponseStatusCodeSame(400)",
            "'kind' => 'QUEJA_INTERNA'", "['state' => 'CERRADO'",
            "'APROBADO'", "'REQUIERE_REVISION'",
            "assertSame([], self::payload($client)['records'])",
        ):
            self.assertIn(fragment, self.http)
        self.assertIn("$this->requireCsrf($request)", self.controller)
        self.assertIn("$this->authorizedBranchScope($branchId, $permission)", self.controller)
        self.assertIn("'Cache-Control' => 'private, no-store'", self.controller)
        self.assertIn("'CON DOR_QUALITY_SYNTHETIC_API'", self.controller.replace(
            "'CONDOR_QUALITY_SYNTHETIC_API'", "'CON DOR_QUALITY_SYNTHETIC_API'"
        ))

    def test_fixture_only_dev_only_and_no_automatic_approval(self):
        self.assertIn("if (!import.meta.env.DEV) return null", self.ui)
        self.assertIn("'fixture:quality-record/queja'", self.http)
        self.assertIn("'fixture:quality-source/demo'", self.http)
        self.assertIn("longDigits = /[0-9]{8,}/", self.remote)
        self.assertIn("syntheticReference(recordRef, remoteRef)", self.remote)
        self.assertIn("syntheticReference(originRef, sourceRef)", self.remote)
        self.assertNotIn("document.cookie", self.remote)
        self.assertNotIn("axios", self.remote)
        self.assertNotIn("setInterval(", self.remote)
        self.assertFalse(re.search(r"['\"](?:CERRADO|APROBADO)['\"]", self.remote))


if __name__ == "__main__":
    unittest.main()
