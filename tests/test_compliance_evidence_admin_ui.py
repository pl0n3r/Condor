#!/usr/bin/env python3
"""#1040: compliance admin construction-only API/RBAC/fixture acceptance."""
from __future__ import annotations

from pathlib import Path
import re
import unittest

ROOT = Path(__file__).resolve().parents[1]
PANEL = ROOT / 'frontend/admin/ComplianceManagement.tsx'
APP = ROOT / 'frontend/admin/AdminApp.tsx'
CONTROLLER = ROOT / 'src/Http/Controller/ComplianceController.php'


class ModuloTests(unittest.TestCase):
    def test_aislamiento_por_empresa(self) -> None:
        code, app = PANEL.read_text(), APP.read_text()
        self.assertIn("key={'compliance-' + context.data.tenant.id + '-'", app)
        self.assertIn("tenantId", app)
        self.assertIn("branchId={context.data.active_branch.id}", app)
        self.assertIn("pathFor(branchId)", code)
        self.assertIn("encodeURIComponent(branchId)", code)
        self.assertIn("requestId.current", code)
        self.assertIn("credentials: 'same-origin'", code)
        self.assertIn("setSelected(null)", code)
        self.assertNotIn("localStorage", code)
        self.assertNotIn("sessionStorage", code)
        self.assertNotIn("indexedDB", code)
        self.assertNotIn("tenant_id:", code)
        self.assertNotIn("legal_entity_id:", code)
        self.assertIn("hash('sha256', $company->id().'|'.$office->id())", CONTROLLER.read_text())

    def test_permisos_y_auditoria(self) -> None:
        code, app = PANEL.read_text(), APP.read_text()
        for permission in ('settings.view', 'settings.create', 'settings.update'):
            self.assertIn(permission, code)
        for gate in ('if (!canView)', 'if (!canCreate', 'if (!canUpdate'):
            self.assertIn(gate, code)
        self.assertIn("'X-CSRF-Token'", code)
        self.assertIn("csrfToken={accessToken}", app)
        self.assertIn("permissions={context.data.permissions}", app)
        self.assertIn("response.status === 401 || response.status === 403", code)
        self.assertIn("auditada en servidor", code)
        self.assertIn("AuditEvent", (ROOT / "src/Http/Controller/BranchApiSupport.php").read_text())
        api = CONTROLLER.read_text()
        for verb in ("methods: ['GET']", "methods: ['POST']", "methods: ['PATCH']"):
            self.assertIn(verb, api)
        self.assertIn("$this->authorizedBranchScope(", api)
        self.assertIn("$this->requireCsrf($request)", api)

    def test_sin_datos_reales(self) -> None:
        code, app = PANEL.read_text(), APP.read_text()
        expressions = [
            r"const evidencePattern = /^fixture:evidence\/[a-z][a-z0-9_-]{0,47}$/;",
            r"const obligationPattern = /^fixture:obligation\/[a-z][a-z0-9_-]{0,47}$/;",
        ]
        for expression in expressions:
            # The actual regex is checked as a literal contract rather than evaluated in Python.
            self.assertIn(expression, code)
        self.assertIn("!/[0-9]{7,}/.test(value)", code)
        self.assertIn("!import.meta.env.DEV", code)
        self.assertIn("import.meta.env.DEV && (", app)
        self.assertIn("No se ha activado el módulo real", code)
        for forbidden in ("window.localStorage", "axios", "document_number", "tax_id:"):
            self.assertNotIn(forbidden, code)

    def test_estados_explicitos(self) -> None:
        code, app = PANEL.read_text(), APP.read_text()
        for value in ("PENDIENTE", "EN_REVISION", "REQUIERE_REVISION"):
            self.assertIn(value, code)
        self.assertIn("onSubmit={(event) => { void create(event); }}", code)
        self.assertIn("onSubmit={(event) => { void changeState(event); }}", code)
        self.assertIn("Ningún estado se cierra automáticamente", code)
        self.assertNotIn("state: 'CERRADA'", code)
        self.assertNotIn("state: 'APROBADA'", code)
        self.assertIn("type=\"button\"", code)
        self.assertIn("role=\"alert\"", code)
        self.assertIn("role=\"status\"", code)
        self.assertIn("aria-live=\"polite\"", code)
        self.assertIn("<ComplianceManagement", app)


if __name__ == '__main__':
    unittest.main()
