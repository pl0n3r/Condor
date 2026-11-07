#!/usr/bin/env python3
"""Contrato M04: UI persistente de Nómina con backend autoritativo y flag OFF."""
from __future__ import annotations

import unittest
from pathlib import Path

ROOT = Path(__file__).resolve().parents[1]
COMPONENT = ROOT / "frontend/admin/PayrollManagement.tsx"
APP = ROOT / "frontend/admin/AdminApp.tsx"
CONTROLLER = ROOT / "src/Http/Controller/AdminController.php"
BACKEND = ROOT / "src/Http/Controller/PayrollController.php"
TWIG = ROOT / "templates/admin/index.html.twig"
TRANSPORT_TEST = ROOT / "frontend/admin/PayrollManagement.test.mjs"


class ModuloTests(unittest.TestCase):
    @classmethod
    def setUpClass(cls) -> None:
        cls.code = COMPONENT.read_text(encoding="utf-8")
        cls.app = APP.read_text(encoding="utf-8")
        cls.controller = CONTROLLER.read_text(encoding="utf-8")
        cls.backend = BACKEND.read_text(encoding="utf-8")
        cls.twig = TWIG.read_text(encoding="utf-8")
        cls.tests = TRANSPORT_TEST.read_text(encoding="utf-8")

    def test_aislamiento_por_empresa_razon_social_y_sede(self) -> None:
        for item in ("row.tenant_id !== tenant", "row.company_id !== company",
                     "encodeURIComponent(options.branchId)",
                     "tenantId, companyId, branchId",
                     "companyId === '__unassigned__'"):
            self.assertIn(item, self.code)
        self.assertIn("row.ref === selected", self.code)
        for binding in ("tenantId={context.data.tenant.id}",
                        "companyId={context.data.active_legal_entity?.id ?? '__unassigned__'}",
                        "branchId={context.data.active_branch.id}"):
            self.assertIn(binding, self.app)
        for negative in ("tenant_id: 'tenantB'", "company_id: 'companyB'",
                         "duplicados", "GET maneja vacío"):
            self.assertIn(negative, self.tests)

    def test_server_gate_falla_cerrado_y_nunca_emite_http_en_off(self) -> None:
        self.assertIn("'payroll_real_data_enabled' => getenv('PAYROLL_REAL_DATA_ENABLED') === 'true'", self.controller)
        self.assertIn('data-payroll-real-data-enabled=', self.twig)
        self.assertIn("dataset.payrollRealDataEnabled === 'true'", self.app)
        self.assertIn("enabled={payrollRealDataEnabled}", self.app)
        self.assertIn("if (!options.enabled) return { status: 'disabled' };", self.code)
        self.assertIn("if (!enabled || !canView) return;", self.code)
        self.assertIn("if (!enabled) return <section", self.code)
        self.assertIn("if (getenv('PAYROLL_REAL_DATA_ENABLED')", self.backend)
        self.assertIn("'feature_unavailable'", self.backend)
        self.assertIn("jamás emiten HTTP", self.tests)

    def test_permisos_csrf_y_backend_son_autoridad(self) -> None:
        for permission in ("users.view", "users.create", "users.update"):
            self.assertIn(permission, self.code)
        self.assertIn("Membership::ROLE_OWNER", self.backend)
        self.assertIn("realScope(", self.backend)
        self.assertIn("requireCsrf($request)", self.backend)
        self.assertIn("csrfToken={accessToken}", self.app)
        self.assertIn("'X-CSRF-Token'", self.code)
        self.assertIn("expected_version: options.expectedVersion", self.code)
        self.assertIn("sin CSRF", self.tests)
        for storage in ("localStorage", "sessionStorage", "indexedDB", "axios"):
            self.assertNotIn(storage, self.code)

    def test_estados_y_ux_con_kit_sin_bypass_de_transiciones(self) -> None:
        for component in ("PageHeader", "MetricStrip", "Toolbar", "DataTable",
                          "SidePanel", "StatusPill"):
            self.assertIn(component, self.code)
        for state in ("BORRADOR", "EN_REVISION"):
            self.assertIn(state, self.code)
        for forbidden in ("state: 'APROBADO'", "state: 'PAGADO'",
                          "setTimeout(", "setInterval("):
            self.assertNotIn(forbidden, self.code)
        for state in ("loading", "ready", "error", "denied", "disabled"):
            self.assertIn(state, self.code)
        self.assertIn('role="alert"', self.code)
        self.assertIn('role="status"', self.code)
        self.assertIn('aria-live="polite"', self.code)
        self.assertIn("No hay registros para este filtro.", self.code)
        self.assertIn("versionado en el servidor", self.code)

    def test_referencias_opacas_no_incluyen_pii_ni_pagos(self) -> None:
        self.assertIn("const opaque = /^[a-z][a-z0-9_-]{0,63}$/;", self.code)
        self.assertIn("!opaque.test(row.employee_ref)", self.code)
        self.assertIn("!opaque.test(row.period_ref)", self.code)
        for secret in ("bankAccount", "nationalId", "taxId",
                       "paymentMethod", "persona@example.com"):
            self.assertNotIn(secret, self.code)
        self.assertNotIn("fixture:payroll/", self.code)
        self.assertIn("no liquida, aprueba ni ejecuta pagos", self.code)


if __name__ == "__main__":
    unittest.main()
