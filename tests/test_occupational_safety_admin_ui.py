#!/usr/bin/env python3
"""AC M03: contratos verificables del panel SG-SST sintético."""
from __future__ import annotations

from pathlib import Path
import re
import unittest

ROOT = Path(__file__).resolve().parents[1]
COMPONENT = ROOT / "frontend/admin/ComplianceManagement.tsx"
APP = ROOT / "frontend/admin/AdminApp.tsx"


class ModuloTests(unittest.TestCase):
    def test_aislamiento_por_empresa(self) -> None:
        code, app = COMPONENT.read_text(), APP.read_text()
        self.assertIn("tenantId + '|' + companyId + '|' + branchId", code)
        self.assertIn("key={props.tenantId + '|' + props.companyId + '|' + props.branchId}", code)
        for fragment in ("row.tenantId === tenantId", "row.companyId === companyId",
                         "row.branchId === branchId"):
            self.assertIn(fragment, code)
        self.assertIn("companyId={context.data.active_legal_entity?.id ?? '__unassigned__'}", app)
        self.assertIn("tenantId={context.data.tenant.id}", app)
        self.assertIn("branchId={context.data.active_branch.id}", app)
        self.assertIn("useState<Registro[]>([])", code)
        for forbidden in ("localStorage", "sessionStorage", "indexedDB", "fetch("):
            self.assertNotIn(forbidden, code)

    def test_permisos_y_auditoria(self) -> None:
        code = COMPONENT.read_text()
        for permission in ("inventory.view", "inventory.create", "inventory.update"):
            self.assertIn("permissions.includes('" + permission + "')", code)
        for guard in ("if (!canView)", "if (!canCreate", "if (!canUpdate"):
            self.assertIn(guard, code)
        for action in ("audit(ref, 'created', actorRef)", "audit(item, 'viewed')",
                       "audit(selected, 'state_changed', reviewActorRef)"):
            self.assertIn(action, code)
        self.assertIn("Bitácora de demostración (no oficial)", code)
        self.assertIn("no sustituye la auditoría de servidor", code)

    def test_sin_datos_reales(self) -> None:
        code, app = COMPONENT.read_text(), APP.read_text()
        for name, kind in (("record", "occupational"), ("evidence", "evidence"),
                           ("actor", "actor")):
            match = re.search(r"const " + name + r"RefPattern = /(.+?)/;", code)
            self.assertIsNotNone(match)
            assert match is not None
            regex = re.compile(match.group(1).replace(r"\/", "/"))
            self.assertIsNotNone(regex.fullmatch("fixture:" + kind + "/demo"))
            for unsafe in ("1002030405", "alguien@example.com", "3115551212",
                           "https://example.com", "fixture:" + kind + "/../private",
                           "fixture:" + kind + "/user@example.com"):
                self.assertIsNone(regex.fullmatch(unsafe))
        self.assertIn("if (!import.meta.env.DEV) return null;", code)
        self.assertIn("import.meta.env.DEV && (", app)
        for unwanted in ("axios", "httpclient", "token:", "password:",
                         "navigator.sendBeacon", "new WebSocket(", "XMLHttpRequest("):
            self.assertNotIn(unwanted.lower(), code.lower())

    def test_estados_explicitos(self) -> None:
        code, app = COMPONENT.read_text(), APP.read_text()
        for state in ("PENDIENTE", "EN_REVISION", "CIERRE_REQUIERE_REVISION"):
            self.assertIn(state, code)
        self.assertIn("selectedRow.state === 'PENDIENTE' && nextState === 'EN_REVISION'", code)
        self.assertIn("selectedRow.state === 'EN_REVISION' && nextState === 'CIERRE_REQUIERE_REVISION'", code)
        self.assertIn("onSubmit={changeState}", code)
        self.assertIn("onSubmit={create}", code)
        self.assertIn("Ningún estado se aprueba ni se cierra automáticamente.", code)
        self.assertNotIn("state: 'CERRADO'", code)
        self.assertNotIn("state: 'APROBADO'", code)
        self.assertIn('type="button"', code)
        self.assertIn('role="alert"', code)
        self.assertIn('role="status"', code)
        self.assertIn("<ComplianceManagement", app)


if __name__ == "__main__":
    unittest.main()
