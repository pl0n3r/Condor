#!/usr/bin/env python3
"""#1564: M15 en HTTP Symfony real, UI conectada y límites sintéticos."""

from pathlib import Path
import unittest

ROOT = Path(__file__).resolve().parents[1]
UI = ROOT / "frontend/admin/GovernanceManagement.tsx"
CONTROLLER = ROOT / "src/Http/Controller/GovernanceController.php"
HTTP = ROOT / "tests/php/Application/Governance/GovernanceHttpIntegrationTest.php"


class GovernanceHttpUiIntegrationTests(unittest.TestCase):
    def test_ac01_four_kinds_use_real_authenticated_http(self) -> None:
        source = UI.read_text(encoding="utf-8")
        for kind in ("shareholder", "faculty", "authorization", "decision"):
            self.assertIn(kind, source)
        for api in (
            "new XMLHttpRequest()",
            "request.withCredentials = true",
            "request.setRequestHeader('Accept', 'application/json')",
            "document.getElementById('condor-admin-root')",
            "getAttribute('data-access-token')",
            "request.setRequestHeader('X-CSRF-Token', token)",
            "request.send(body ? JSON.stringify(body) : null)",
            "governanceRequest('GET', governancePath(branchId))",
            "governanceRequest('POST', governancePath(branchId)",
            "governanceRequest('PATCH', governancePath(branchId)",
            "serverRecord(response.record, tenantId, companyId, branchId)",
        ):
            self.assertIn(api, source)
        for forbidden in ("localStorage", "sessionStorage", "indexedDB", "axios", "setInterval("):
            self.assertNotIn(forbidden, source)
        http = HTTP.read_text(encoding="utf-8")
        self.assertIn("extends WebTestCase", http)
        self.assertIn("jsonRequest('POST'", http)
        self.assertIn("jsonRequest('PATCH'", http)
        self.assertIn("request('GET'", http)

    def test_ac02_server_guards_scope_role_csrf_and_feature_off(self) -> None:
        controller = CONTROLLER.read_text(encoding="utf-8")
        php_test = HTTP.read_text(encoding="utf-8")
        for token in (
            "$this->authorizedBranchScope($branchId, 'inventory.view')",
            "$this->authorizedBranchScope($branchId, 'inventory.create')",
            "$this->authorizedBranchScope($branchId, 'inventory.update')",
            "$this->requireCsrf($request);",
            "getenv('CONDOR_GOVERNANCE_SYNTHETIC_API')",
            "getenv('CONDOR_PRODUCTION_STAGE')",
            "'construccion'",
            "hash('sha256', $tenantId.'|'.$branchId)",
            "'Cache-Control', 'private, no-store'",
        ):
            self.assertIn(token, controller)
        for token in (
            "self::assertResponseStatusCodeSame(403)",
            "self::assertResponseStatusCodeSame(404)",
            "self::assertResponseStatusCodeSame(422)",
            "self::assertSame([], self::payload($client)['records'])",
            "self::assertSame('REQUIERE_REVISION'",
            "['state' => 'APROBADO']",
            "putenv('CONDOR_GOVERNANCE_SYNTHETIC_API=0')",
        ):
            self.assertIn(token, php_test)

    def test_ac03_ui_loading_empty_error_denied_and_no_legal_approval(self) -> None:
        source = UI.read_text(encoding="utf-8")
        for token in (
            "setRemoteState('loading')",
            "setRemoteState('ready')",
            "setRemoteState('denied')",
            "?'denied' : 'error'",  # normalization below handles formatting
            "role=\"status\" aria-live=\"polite\"",
            "Sin actuaciones ficticias para el filtro.",
            "Acceso denegado por el servidor",
            "Error al consultar gobierno ficticio",
            "SOLO DATOS FICTICIOS",
            "Sin valor jurídico",
            "import.meta.env.DEV",
        ):
            if token == "?'denied' : 'error'":
                self.assertIn("? 'denied' : 'error'", source)
            else:
                self.assertIn(token, source)
        for forbidden in ("state: 'APROBADO'", "state: 'CERRADO'", "document.cookie",
                          "localStorage", "sessionStorage"):
            self.assertNotIn(forbidden, source)


if __name__ == "__main__":
    unittest.main()
