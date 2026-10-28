#!/usr/bin/env python3
"""AC-01..04 del simulador financiero M07, sin servicios ni datos reales."""
from __future__ import annotations

from pathlib import Path
import re
import unittest

ROOT = Path(__file__).resolve().parents[1]
COMPONENT = ROOT / "frontend/admin/FinanceManagement.tsx"
PERMISSION_CATALOG = ROOT / "src/Domain/Identity/PermissionCatalog.php"


class ModuloTests(unittest.TestCase):
    @classmethod
    def setUpClass(cls) -> None:
        cls.code = COMPONENT.read_text(encoding="utf-8")

    def test_aislamiento_por_empresa(self) -> None:
        code = self.code
        self.assertIn("const scope = tenantId + '|' + companyId + '|' + branchId", code)
        self.assertIn("key={props.tenantId + '|' + props.companyId + '|' + props.branchId}", code)
        self.assertIn("const scoped = records.filter", code)
        for guard in ("row.tenantId === tenantId", "row.companyId === companyId",
                      "row.branchId === branchId"):
            self.assertIn(guard, code)
        self.assertIn("companyId !== '__unassigned__'", code)
        for io in ("fetch(", "axios", "localStorage", "sessionStorage", "indexedDB"):
            self.assertNotIn(io, code)

    def test_permisos_y_auditoria(self) -> None:
        code = self.code
        for permission in ("settings.view", "settings.create", "settings.update"):
            self.assertIn(permission, code)
        for guard in ("if (!canView)", "if (!canCreate", "if (!canReview"):
            self.assertIn(guard, code)
        for action in ("audit(reference, 'created')", "audit(ref, 'viewed')",
                       "audit(selected, current.state"):
            self.assertIn(action, code)
        self.assertIn("Bitácora de demostración (no oficial)", code)
        self.assertIn("auditoría de servidor", code)
        self.assertIn("if (!import.meta.env.DEV) return null;", code)

    def test_permisos_reales_del_catalogo(self) -> None:
        """La demo no puede quedar denegada por nombres de permiso inexistentes."""
        code = self.code
        php = PERMISSION_CATALOG.read_text(encoding="utf-8")
        for permission in ("settings.view", "settings.create", "settings.update"):
            self.assertIn(f"permissions.includes('{permission}')", code)
        self.assertIn("'settings' => 'Configuración'", php)
        for action in ("view", "create", "update"):
            self.assertIn(f"'{action}' =>", php)
        for old in ("finance.view", "finance.create", "finance.review"):
            self.assertNotIn(old, code)

    def test_sin_datos_reales(self) -> None:
        code = self.code
        for kind in ("finance", "period", "concept"):
            m = re.search(rf"const {kind}RefPattern = /(.+?)/;", code)
            self.assertIsNotNone(m)
            assert m is not None
            pattern = re.compile(m.group(1).replace(r"\/", "/"))
            self.assertIsNotNone(pattern.fullmatch(f"fixture:{kind}/demo"))
            for invalid in ("987654321", "persona@example.com", "3115551212",
                            "https://example.com", f"fixture:{kind}/../private",
                            f"fixture:{kind}/user@example.com"):
                self.assertIsNone(pattern.fullmatch(invalid))
        self.assertIn("['0.00', '100.00', '250.00']", code)
        self.assertIn("SOLO DATOS FICTICIOS", code)
        self.assertIn("Sin valor legal", code)
        for forbidden in ("bankAccount", "taxId", "nationalId", "paymentMethod",
                          "fetch(", "axios", "indexedDB"):
            self.assertNotIn(forbidden, code)

    def test_estados_explicitos(self) -> None:
        code = self.code
        for state in ("BORRADOR", "REQUIERE_REVISION", "EN_REVISION"):
            self.assertIn(state, code)
        self.assertIn("state: 'BORRADOR'", code)
        self.assertIn("current.state === 'BORRADOR' ? 'REQUIERE_REVISION' : 'EN_REVISION'", code)
        for forbidden in ("state: 'APROBADO'", "state: 'CERRADO'", "state: 'PAGADO'",
                          "setTimeout(", "setInterval("):
            self.assertNotIn(forbidden, code)
        self.assertIn("onSubmit={changeState}", code)
        self.assertIn("onSubmit={create}", code)
        self.assertIn('type="button"', code)
        self.assertIn('role="alert"', code)
        self.assertIn('role="status"', code)
        self.assertIn('aria-live="polite"', code)
        self.assertIn("Ningún registro se aprueba, contabiliza, paga ni cierra automáticamente.", code)


    def test_montaje_dev_en_administracion(self) -> None:
        """Solo existe en DEV, mantiene el ámbito tenant/empresa/sede y permisos."""
        shell = (ROOT / "frontend/admin/AdminApp.tsx").read_text(encoding="utf-8")
        self.assertIn("const DevFinanceManagement = import.meta.env.DEV", shell)
        self.assertIn("import('./FinanceManagement')", shell)
        self.assertIn("import.meta.env.DEV && DevFinanceManagement", shell)
        self.assertIn("<DevFinanceManagement", shell)
        self.assertIn("tenantId={context.data.tenant.id}", shell)
        self.assertIn("companyId={context.data.active_legal_entity?.id ?? '__unassigned__'}", shell)
        self.assertIn("branchId={context.data.active_branch.id}", shell)
        self.assertIn("permissions={context.data.permissions}", shell)
        self.assertIn("Cargando finanzas ficticias", shell)


if __name__ == "__main__":
    unittest.main()
