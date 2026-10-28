#!/usr/bin/env python3
"""AC-01..04 de #1090: verificación del panel sintético M02 y su montaje DEV."""
from pathlib import Path
import re
import unittest

BASE = Path(__file__).resolve().parent.parent
VIEW = BASE.joinpath("frontend/admin/ContractsManagement.tsx")
SHELL = BASE.joinpath("frontend/admin/AdminApp.tsx")
IDENTITY = BASE.joinpath("src/Domain/Identity/PermissionCatalog.php")


class ModuloTests(unittest.TestCase):
    @classmethod
    def setUpClass(cls):
        cls.tsx = VIEW.read_text(encoding="utf-8")
        cls.shell = SHELL.read_text(encoding="utf-8")

    def test_aislamiento_por_empresa(self):
        src = self.tsx
        for term in ("[tenantId, companyId, branchId].join('|')",
                     "drafts.filter(entry => entry.scope === scope)",
                     "selected.scope !== scope",
                     "item.scope === scope",
                     "[props.tenantId, props.companyId, props.branchId].join('|')"):
            with self.subTest(scope=term):
                self.assertIn(term, src)
        self.assertIn("tenantId={context.data.tenant.id}", self.shell)
        self.assertIn("companyId={context.data.active_legal_entity?.id ?? '__unassigned__'}", self.shell)
        self.assertIn("branchId={context.data.active_branch.id}", self.shell)

    def test_permisos_y_auditoria(self):
        permissions = IDENTITY.read_text(encoding="utf-8")
        for operation in ("view", "create", "update"):
            with self.subTest(permission=operation):
                self.assertIn("permissions.includes('users." + operation + "')", self.tsx)
                self.assertIn("'" + operation + "' =>", permissions)
        for guard in ("if (!canRead)", "if (!canAdd", "if (!canAdvance"):
            self.assertIn(guard, self.tsx)
        for audit in ("record(ref, 'borrador_creado')", "record(item.ref, 'consulta_ficticia')",
                      "record(selected.ref, 'revision_manual_'", ".slice(-80)"):
            self.assertIn(audit, self.tsx)
        self.assertIn("permissions={context.data.permissions}", self.shell)

    def test_sin_datos_reales(self):
        src = self.tsx
        for kind in ("contracts", "person", "template"):
            with self.subTest(kind=kind):
                token = "^fixture:" + kind + "/(demo|test|sample)-[0-9]{2,3}$"
                self.assertIn(token, src)
                pattern = re.compile(token)
                for allowed in ("demo-01", "test-99", "sample-123"):
                    self.assertTrue(pattern.fullmatch("fixture:" + kind + "/" + allowed))
                for rejected in ("fixture:" + kind + "/maria-gomez",
                                 "fixture:" + kind + "/abc12345678",
                                 "fixture:" + kind + "/../secret",
                                 "persona@ejemplo.com", "3115551212"):
                    self.assertFalse(pattern.fullmatch(rejected))
        for forbidden in ("fetch(", "axios", "localStorage", "indexedDB", "setInterval(",
                          "sessionStorage", "new WebSocket", "status: 'CERRADO'"):
            self.assertNotIn(forbidden, src)
        self.assertIn("filter === '' || entry.ref === filter", src)
        self.assertIn("scopedDrafts.map(item => <option", src)
        self.assertNotIn("<input value={filter}", src)
        self.assertIn("SOLO DATOS FICTICIOS", src)
        self.assertIn("if (!import.meta.env.DEV) return null;", src)

    def test_estados_explicitos(self):
        for value in ("BORRADOR", "REQUIERE_REVISION", "EN_REVISION"):
            self.assertIn(value, self.tsx)
        self.assertIn("selected.phase === 'BORRADOR' ? 'REQUIERE_REVISION' : 'EN_REVISION'", self.tsx)
        self.assertIn("onSubmit={createDemo}", self.tsx)
        self.assertIn("onSubmit={advance}", self.tsx)
        self.assertIn("role=\"alert\"", self.tsx)
        self.assertIn("aria-live=\"polite\"", self.tsx)
        self.assertIn("Ningún borrador se aprueba, firma o cierra automáticamente.", self.tsx)
        self.assertIn("import.meta.env.DEV && DevContractsManagement", self.shell)


if __name__ == "__main__":
    unittest.main()
