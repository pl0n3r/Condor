#!/usr/bin/env python3
"""#1058: cuatro criterios M15, solo componentes y datos de demostración."""
from __future__ import annotations

from pathlib import Path
import re
import unittest

ROOT = Path(__file__).resolve().parents[1]
PANEL = ROOT / "frontend/admin/GovernanceManagement.tsx"
APP = ROOT / "frontend/admin/AdminApp.tsx"
CATALOG = ROOT / "src/Domain/Identity/PermissionCatalog.php"


class ModuloTests(unittest.TestCase):
    @classmethod
    def setUpClass(cls) -> None:
        cls.code = PANEL.read_text(encoding="utf-8")
        cls.app = APP.read_text(encoding="utf-8")

    def test_aislamiento_por_empresa(self) -> None:
        code, app = self.code, self.app
        self.assertIn("const scope = tenantId + '|' + companyId + '|' + branchId", code)
        self.assertIn("key={props.tenantId + '|' + props.companyId + '|' + props.branchId}", code)
        self.assertIn("const scoped = records.filter", code)
        for guard in ("row.tenantId === tenantId", "row.companyId === companyId",
                      "row.branchId === branchId"):
            self.assertIn(guard, code)
        self.assertIn("companyId !== '__unassigned__'", code)
        self.assertIn("tenantId={context.data.tenant.id}", app)
        self.assertIn("companyId={context.data.active_legal_entity?.id ?? '__unassigned__'}", app)
        self.assertIn("branchId={context.data.active_branch.id}", app)
        self.assertIn("<DevGovernanceManagement", app)
        for io in ("fetch(", "axios", "localStorage", "sessionStorage", "indexedDB"):
            self.assertNotIn(io, code)

    def test_permisos_y_auditoria(self) -> None:
        code, app = self.code, self.app
        catalog = CATALOG.read_text(encoding="utf-8")
        for action in ("view", "create", "update"):
            permission = f"settings.{action}"
            self.assertIn(f"permissions.includes('{permission}')", code)
            self.assertIn(f"'{action}' =>", catalog)
        for guard in ("if (!canView)", "if (!canCreate", "if (!canReview"):
            self.assertIn(guard, code)
        for action in ("audit(reference, 'created')", "audit(ref, 'viewed')",
                       "audit(selected, current.state"):
            self.assertIn(action, code)
        self.assertIn("Bitácora de demostración (no oficial)", code)
        self.assertIn("auditoría de servidor", code)
        self.assertIn("permissions={context.data.permissions}", app)
        self.assertIn("if (!import.meta.env.DEV) return null;", code)

    def test_sin_datos_reales(self) -> None:
        code = self.code
        for name in ("governance", "body", "representative"):
            expression = rf"^fixture:{name}\/[a-z][a-z0-9_-]{{0,47}}$"
            self.assertIn(expression, code)
            pattern = re.compile(expression.replace(r"\/", "/"))
            self.assertTrue(pattern.fullmatch(f"fixture:{name}/demo"))
            for value in ("persona@example.com", "987654321", "3115551212",
                          f"fixture:{name}/../secret", f"fixture:{name}/someone@example.com"):
                self.assertIsNone(pattern.fullmatch(value))
        self.assertIn("!/[0-9]{7,}/.test(value)", code)
        self.assertIn("SOLO DATOS FICTICIOS", code)
        self.assertIn("Sin valor jurídico", code)
        for forbidden in ("fetch(", "axios", "indexedDB", "taxId", "nationalId", "setInterval("):
            self.assertNotIn(forbidden, code)

    def test_estados_explicitos(self) -> None:
        code, app = self.code, self.app
        for state in ("BORRADOR", "REQUIERE_REVISION", "EN_REVISION"):
            self.assertIn(state, code)
        self.assertIn("state: 'BORRADOR'", code)
        self.assertIn("current.state === 'BORRADOR' ? 'REQUIERE_REVISION' : 'EN_REVISION'", code)
        for forbidden in ("state: 'APROBADO'", "state: 'CERRADO'", "setTimeout(", "setInterval("):
            self.assertNotIn(forbidden, code)
        self.assertIn("onSubmit={changeState}", code)
        self.assertIn("onSubmit={create}", code)
        self.assertIn('type="button"', code)
        self.assertIn('role="alert"', code)
        self.assertIn('role="status"', code)
        self.assertIn('aria-live="polite"', code)
        self.assertIn("Ningún acta, poder o decisión se aprueba ni cierra automáticamente.", code)
        self.assertIn("import.meta.env.DEV && DevGovernanceManagement", app)


if __name__ == "__main__":
    unittest.main()
