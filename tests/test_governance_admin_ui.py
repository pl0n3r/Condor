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

    @property
    def real_api(self) -> bool:
        return 'export async function governanceRequest(' in self.code

    def test_aislamiento_por_empresa(self) -> None:
        code, app = self.code, self.app
        if self.real_api:
            for guard in (
                'row.tenant_id !== tenantId',
                'row.company_id !== companyId',
                "options.companyId === '__unassigned__'",
                "options.branchId",
                "encodeURIComponent(options.branchId)",
                'data.next_cursor',
                'new Set(records.map(x => x.ref))',
            ):
                self.assertIn(guard, code)
            for guard in (
                'tenantId={context.data.tenant.id}',
                "companyId={context.data.active_legal_entity?.id ?? '__unassigned__'}",
                'branchId={context.data.active_branch.id}',
            ):
                self.assertIn(guard, app)
            self.assertIn("dataset.governanceRealDataEnabled === 'true'", app)
            self.assertIn("enabled={governanceRealDataEnabled}", app)
            self.assertIn('records: [row]', (ROOT / 'frontend/admin/GovernanceManagement.test.mjs').read_text())
            return
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
        if self.real_api:
            api = (ROOT / 'src/Http/Controller/GovernanceController.php').read_text()
            for action in ('view', 'create', 'update'):
                self.assertIn(f"permissions.includes('inventory.{action}')", code)
                self.assertIn(f"'inventory.{action}'", api)
            for guard in (
                'Membership::ROLE_OWNER', 'governanceRealScope($branchId',
                'governanceValidateCsrf($request)', "'expected_version'",
            ):
                self.assertIn(guard, api)
            self.assertIn("'X-CSRF-Token'", code)
            self.assertIn("credentials: 'same-origin'", code)
            self.assertIn("'feature_unavailable'", api)
            self.assertIn('permissions={context.data.permissions}', app)
            return
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
        if self.real_api:
            admin = (ROOT / 'src/Http/Controller/AdminController.php').read_text()
            twig = (ROOT / 'templates/admin/index.html.twig').read_text()
            self.assertIn("getenv('GOVERNANCE_REAL_DATA_ENABLED') === 'true'", admin)
            self.assertIn('data-governance-real-data-enabled=', twig)
            self.assertIn("if (!options.enabled) return { status: 'disabled' };", code)
            self.assertIn('if (!enabled) return <section', code)
            self.assertIn("'feature_unavailable'", (ROOT / 'src/Http/Controller/GovernanceController.php').read_text())
            self.assertIn('!/[0-9]{7,}/.test(value)', code)
            self.assertNotIn('fixture:', code)
            for forbidden in ('taxId', 'nationalId', 'setInterval(', 'setTimeout('):
                self.assertNotIn(forbidden, code)
            for forbidden in ('data-governance-records', 'record_ref', 'actor_id'):
                self.assertNotIn(forbidden, twig)
            return
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
        if self.real_api:
            for state in ('PENDIENTE', 'REQUIERE_REVISION', 'EN_REVISION'):
                self.assertIn(state, code)
            for forbidden in ("state: 'APROBADO'", "state: 'CERRADO'", 'setInterval('):
                self.assertNotIn(forbidden, code)
            for guard in ('transitions[current.state].includes(nextState)',
                          'expectedVersion: current.version',
                          "if (status === 'disabled')",
                          'type="button"', 'role="alert"', 'aria-live="polite"'):
                self.assertIn(guard, code)
            self.assertIn('onSubmit={event => { void create(event); }}', code)
            self.assertIn('onSubmit={event => { void transition(event); }}', code)
            self.assertIn('No constituye aprobación legal.', code)
            self.assertIn('governanceRealDataEnabled', app)
            return
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
