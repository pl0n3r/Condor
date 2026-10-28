#!/usr/bin/env python3
"""M16 synthetic UI source contract; full TypeScript/CI remains mandatory."""
from __future__ import annotations
import re
import unittest
from pathlib import Path

ROOT = Path(__file__).resolve().parents[1]
COMPONENT = ROOT / 'frontend/admin/CustomerServiceManagement.tsx'
APP = ROOT / 'frontend/admin/AdminApp.tsx'


class ModuloTests(unittest.TestCase):
    def test_aislamiento_por_empresa(self) -> None:
        code, app = COMPONENT.read_text(), APP.read_text()
        self.assertIn("const scope = tenantId + '/' + branchId", code)
        self.assertIn("key={props.tenantId + '|' + props.branchId}", code)
        self.assertIn('tenantId={context.data.tenant.id}', app)
        self.assertIn('branchId={context.data.active_branch.id}', app)
        self.assertIn('useState<CaseRecord[]>([])', code)
        for unwanted in ('localStorage', 'sessionStorage', 'indexedDB', 'fetch('):
            self.assertNotIn(unwanted, code)

    def test_permisos_y_auditoria(self) -> None:
        code, app = COMPONENT.read_text(), APP.read_text()
        for perm in ('inventory.view', 'inventory.create', 'inventory.update'):
            self.assertIn(perm, code)
        self.assertIn('if (!canCreate', code)
        self.assertIn('if (!canView', code)
        self.assertIn('if (!canUpdate', code)
        self.assertIn("log(ref, 'created')", code)
        self.assertIn("log(reference, 'viewed')", code)
        self.assertIn("log(reference, 'state_changed')", code)
        self.assertIn('Bitácora de demostración (no oficial)', code)
        self.assertIn('auditoría de servidor', code)
        self.assertIn('import.meta.env.DEV', app)

    def test_sin_datos_reales(self) -> None:
        code = COMPONENT.read_text()
        m = re.search(r'const syntheticRef = /(.+?)/;', code)
        self.assertIsNotNone(m)
        assert m is not None
        pat = re.compile(m.group(1).replace(r'\/', '/'))
        for valid in ('fixture:case/demo', 'fixture:case/demo_2'):
            self.assertIsNotNone(pat.fullmatch(valid))
        for unsafe in ('1002030405', 'alguien@example.com', '3115551212',
                       'https://example.com', 'fixture:case/../private',
                       'fixture:case/user@example.com', 'fixture:case/other/one'):
            self.assertIsNone(pat.fullmatch(unsafe))
        for unwanted in ('axios', 'httpclient', 'file_get_contents(', 'token:', 'password:'):
            self.assertNotIn(unwanted, code.lower())
        self.assertIn('if (!import.meta.env.DEV) return null;', code)

    def test_estados_explicitos(self) -> None:
        code = COMPONENT.read_text()
        self.assertIn("'REQUIERE_REVISION' | 'EN_REVISION'", code)
        self.assertIn("state: 'REQUIERE_REVISION'", code)
        self.assertIn("state: 'EN_REVISION'", code)
        self.assertIn("r.state === 'REQUIERE_REVISION'", code)
        self.assertIn('Pasar explícitamente a revisión', code)
        self.assertIn('type="button"', code)
        self.assertIn('onClick={() => moveToReview(detail.ref)}', code)
        self.assertIn('pattern="fixture:case/[a-z][a-z0-9_-]{0,31}"', code)
        self.assertIn('required maxLength={45}', code)
        self.assertIn('onSubmit={create}', code)
        self.assertIn('role="alert"', code)
        self.assertIn('No se permiten aprobaciones ni cierres automáticos.', code)
        self.assertNotIn("state: 'CERRADO'", code)
        self.assertNotIn("state: 'APROBADO'", code)


if __name__ == '__main__':
    unittest.main()
