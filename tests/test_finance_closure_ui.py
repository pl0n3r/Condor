#!/usr/bin/env python3
"""#1568: integración HTTP/UI de cierres ficticios, sin navegador ni secretos."""
from pathlib import Path
import unittest

ROOT = Path(__file__).resolve().parents[1]
UI = ROOT / "frontend/admin/FinanceManagement.tsx"
API = ROOT / "frontend/admin/api.ts"
CONTROLLER = ROOT / "src/Http/Controller/FinanceController.php"
HTTP = ROOT / "tests/php/Http/FinanceClosurePreviewControllerTest.php"


class FinanceClosureUiTests(unittest.TestCase):
    @classmethod
    def setUpClass(cls):
        cls.ui = UI.read_text(encoding="utf-8")
        cls.api = API.read_text(encoding="utf-8")
        cls.controller = CONTROLLER.read_text(encoding="utf-8")
        cls.http = HTTP.read_text(encoding="utf-8")

    def test_real_http_binding_and_scoped_csrf(self):
        self.assertIn("financeClosurePreviewPath(branchId: string)", self.api)
        self.assertIn("safeUlid(branchId)", self.api)
        self.assertIn("'/finance/closures/preview'", self.api)
        self.assertIn("previewSyntheticFinanceClosure(branchId, mode, controller.signal)", self.ui)
        for snippet in ("method: 'POST'", "credentials: 'same-origin'",
                        "'X-CSRF-Token': csrfToken", "JSON.stringify(financeClosureFixture(mode))",
                        "document.getElementById('condor-admin-root')?.dataset.accessToken"):
            self.assertIn(snippet, self.api)
        self.assertIn("'settings.update', true", self.controller)
        self.assertIn("requireCsrf($request)", self.controller)
        self.assertIn("authorizedBranchScope($branchId, $permission)", self.controller)

    def test_loading_empty_error_and_permission_denied_follow_responses(self):
        for state in ("idle", "loading", "empty", "error", "denied", "ready"):
            self.assertIn(f"status: '{state}'", self.ui)
        for snippet in ("response.status", "response.ok", "response.json()",
                        "FinanceClosureAccessDenied", "envelope.synthetic_only !== true",
                        "row.can_close === false", "row.can_approve === false",
                        "result.periods.length === 0", "error instanceof FinanceClosureAccessDenied",
                        "controller.signal.aborted", "pendingClosurePreview.current?.abort()"):
            self.assertIn(snippet, self.ui + self.api)
        for snippet in ("role=\"status\"", "role=\"alert\"", "aria-busy",
                        "Consultar conciliación ficticia", "Consultar período vacío ficticio"):
            self.assertIn(snippet, self.ui)

    def test_synthetic_only_and_no_real_closing(self):
        for snippet in ("'fixture:tenant/current'", "'fixture:legal-entity/current'",
                        "'fixture:finance-period/october'", "'fixture:finance-entry/demo'",
                        "mode === 'empty' ? []", "synthetic_only === true"):
            self.assertIn(snippet, self.api)
        for forbidden in ("localStorage", "sessionStorage", "indexedDB", "state: 'CERRADO'",
                          "state: 'APROBADO'", "setInterval(", "setTimeout("):
            self.assertNotIn(forbidden, self.ui + self.api)
        self.assertNotIn("fetch(", self.ui)  # HTTP boundary is isolated in api.ts.
        self.assertIn("if (!import.meta.env.DEV) return null;", self.ui)
        self.assertIn("canReview", self.ui)
        self.assertIn("self::assertFalse($preview['can_close'])", self.http)
        self.assertIn("self::assertResponseStatusCodeSame(403)", self.http)
        self.assertIn("self::assertResponseStatusCodeSame(404)", self.http)
        self.assertIn("self::assertResponseStatusCodeSame(422)", self.http)


if __name__ == "__main__":
    unittest.main()
