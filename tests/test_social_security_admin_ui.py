#!/usr/bin/env python3
"""Garantías ejecutables para el panel M05, exclusivamente ficticio."""
from __future__ import annotations

import re
import unittest
from pathlib import Path

ROOT = Path(__file__).resolve().parents[1]
PANEL = ROOT / "frontend/admin/SocialSecurityManagement.tsx"
APP = ROOT / "frontend/admin/AdminApp.tsx"
CATALOG = ROOT / "src/Domain/Identity/PermissionCatalog.php"


class ModuloTests(unittest.TestCase):
    def test_aislamiento_por_empresa(self) -> None:
        panel = PANEL.read_text(encoding="utf-8")
        app = APP.read_text(encoding="utf-8")
        for contract in (
            "const scope = [tenantId, companyId, branchId].join('|')",
            "item.scope === scope", "visible.some(item => item.id === id)",
            "key={[props.tenantId, props.companyId, props.branchId].join('|')}",
            "companyId={context.data.active_legal_entity?.id ?? '__unassigned__'}",
            "tenantId={context.data.tenant.id}", "branchId={context.data.active_branch.id}",
        ):
            self.assertIn(contract, panel + app)
        self.assertIn("!canView", panel)
        self.assertIn("<DevSocialSecurityManagement", app)

    def test_permisos_y_auditoria(self) -> None:
        code = PANEL.read_text(encoding="utf-8")
        permissions = CATALOG.read_text(encoding="utf-8")
        self.assertIn("'users' => 'Usuarios'", permissions)
        for action in ("view", "create", "update"):
            self.assertIn(f"permissions.includes('users.{action}')", code)
            self.assertIn(f"'{action}' =>", permissions)
        for guard in ("!canView", "!canCreate", "!canReview"):
            self.assertIn(guard, code)
        for action in ("log('Creación manual", "log('Consulta manual", "log('Cambio manual"):
            self.assertIn(action, code)
        self.assertIn("entry.scope === scope", code)
        self.assertIn("auditoría de servidor", code)

    def test_sin_datos_reales(self) -> None:
        code = PANEL.read_text(encoding="utf-8")
        patterns = re.findall(r"^\s+(affiliation|employee|plan): /(\^fixture:[^\n]+?\$)/,", code, re.M)
        self.assertEqual(3, len(patterns))
        for kind, expression in patterns:
            pattern = re.compile(expression.replace(r"\/", "/"))
            self.assertIsNotNone(pattern.fullmatch(f"fixture:{kind}/demo"))
            for invalid in ("1002030405", "persona@example.com", "3115551212",
                            f"fixture:{kind}/../private", f"fixture:{kind}/user@example.com"):
                self.assertIsNone(pattern.fullmatch(invalid))
        self.assertIn("!/[0-9]{7,}/.test(input)", code)
        for disallowed in ("fetch(", "axios", "localStorage", "sessionStorage",
                           "indexedDB", "nationalId", "bankAccount", "setInterval("):
            self.assertNotIn(disallowed, code)
        self.assertIn("SOLO DATOS FICTICIOS", code)
        self.assertIn("Sin valor legal", code)
        self.assertIn("if (!import.meta.env.DEV) return null;", code)

    def test_estados_explicitos(self) -> None:
        panel = PANEL.read_text(encoding="utf-8")
        app = APP.read_text(encoding="utf-8")
        stages = re.search(r"const steps:.*?= \{([^}]+)\};", panel, re.S)
        self.assertIsNotNone(stages)
        assert stages is not None
        self.assertIn("BORRADOR: 'REQUIERE_REVISION'", stages.group(1))
        self.assertIn("REQUIERE_REVISION: 'EN_REVISION'", stages.group(1))
        self.assertIn("EN_REVISION: null", stages.group(1))
        self.assertIn("const next = steps[picked.stage]", panel)
        self.assertIn("onSubmit={move}", panel)
        self.assertIn("onSubmit={register}", panel)
        self.assertIn('type="button"', panel)
        self.assertIn('role="alert"', panel)
        self.assertIn('aria-live="polite"', panel)
        self.assertIn("import.meta.env.DEV && DevSocialSecurityManagement", app)
        self.assertNotRegex(panel, r"(state|stage): '(APROBADO|CERRADO|PAGADO|ENVIADO)'")
        self.assertNotIn("setTimeout(", panel)


if __name__ == "__main__":
    unittest.main()
