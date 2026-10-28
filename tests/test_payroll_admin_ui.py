#!/usr/bin/env python3
"""Contrato de aceptación de UI de Nómina M04, exclusivamente sintética."""
from __future__ import annotations

import re
import unittest
from pathlib import Path

ROOT = Path(__file__).resolve().parents[1]
COMPONENT = ROOT / "frontend/admin/PayrollManagement.tsx"
APP = ROOT / "frontend/admin/AdminApp.tsx"


class ModuloTests(unittest.TestCase):
    def test_aislamiento_por_empresa(self) -> None:
        code, app = COMPONENT.read_text(encoding="utf-8"), APP.read_text(encoding="utf-8")
        self.assertIn("tenantId + '|' + companyId + '|' + branchId", code)
        self.assertIn("key={props.tenantId + '|' + props.companyId + '|' + props.branchId}", code)
        for guard in ("row.tenantId === tenantId", "row.companyId === companyId",
                      "row.branchId === branchId"):
            self.assertIn(guard, code)
        for bind in ("tenantId={context.data.tenant.id}",
                     "companyId={context.data.active_legal_entity?.id ?? '__unassigned__'}",
                     "branchId={context.data.active_branch.id}"):
            self.assertIn(bind, app)
        self.assertIn("import.meta.env.DEV && (", app)
        self.assertIn("<PayrollManagement", app)
        for persistence in ("fetch(", "localStorage", "sessionStorage", "indexedDB"):
            self.assertNotIn(persistence, code)

    def test_permisos_y_auditoria(self) -> None:
        code = COMPONENT.read_text(encoding="utf-8")
        for permission in ("payroll.view", "payroll.create", "payroll.review"):
            self.assertIn(permission, code)
        for guard in ("if (!canView)", "if (!canCreate", "if (!canReview"):
            self.assertIn(guard, code)
        for event in ("audit(reference, 'created')", "audit(item, 'viewed')",
                      "audit(selected, nextAction"):
            self.assertIn(event, code)
        self.assertIn("Bitácora de demostración (no oficial)", code)
        self.assertIn("auditoría de servidor", code)
        self.assertIn("if (!import.meta.env.DEV) return null;", code)
        for persistence in ("->persist(", "axios", "localStorage", "sessionStorage"):
            self.assertNotIn(persistence, code)

    def test_sin_datos_reales(self) -> None:
        code = COMPONENT.read_text(encoding="utf-8")
        for kind in ("payroll", "period", "employee", "concept"):
            found = re.search(rf"const {kind}RefPattern = /(.+?)/;", code)
            self.assertIsNotNone(found)
            assert found is not None
            expression = re.compile(found.group(1).replace(r"\/", "/"))
            self.assertIsNotNone(expression.fullmatch(f"fixture:{kind}/demo"))
            for non_fixture in ("1002030405", "persona@example.com", "3115551212",
                                "https://example.com", f"fixture:{kind}/../private",
                                f"fixture:{kind}/user@example.com"):
                self.assertIsNone(expression.fullmatch(non_fixture), non_fixture)
        self.assertIn("Sin valor legal", code)
        self.assertIn("SOLO DATOS FICTICIOS", code)
        for forbidden in ("paymentMethod", "bankAccount", "taxId", "nationalId",
                          "fetch(", "axios", "indexedDB"):
            self.assertNotIn(forbidden, code)

    def test_estados_explicitos(self) -> None:
        code, app = COMPONENT.read_text(encoding="utf-8"), APP.read_text(encoding="utf-8")
        for state in ("BORRADOR", "PENDIENTE_REVISION", "REVISION_EN_CURSO"):
            self.assertIn(state, code)
        for forbidden in ("state: 'APROBADO'", "state: 'CERRADO'",
                          "state: 'PAGADO'", "setTimeout(", "setInterval("):
            self.assertNotIn(forbidden, code)
        self.assertIn("onSubmit={changeState}", code)
        self.assertIn("onSubmit={create}", code)
        self.assertIn("type=\"button\"", code)
        self.assertIn("Registrar transición ficticia explícita", code)
        self.assertIn("Ningún estado se aprueba, liquida, paga o cierra automáticamente.", code)
        self.assertIn('role="alert"', code)
        self.assertIn('role="status"', code)
        self.assertIn('aria-live="polite"', code)
        self.assertIn("<PayrollManagement", app)
        self.assertIn("import.meta.env.DEV && (", app)


if __name__ == "__main__":
    unittest.main()
