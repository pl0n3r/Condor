#!/usr/bin/env python3
"""M10: contrato de seguridad de la UI sintética. TS/CI cubren compilación."""
from __future__ import annotations

from pathlib import Path
import re
import unittest

ROOT = Path(__file__).resolve().parents[1]
COMPONENT = ROOT / "frontend/admin/AssetsManagement.tsx"
APP = ROOT / "frontend/admin/AdminApp.tsx"


class ModuloTests(unittest.TestCase):
    def test_aislamiento_por_empresa(self) -> None:
        code, app = COMPONENT.read_text(), APP.read_text()
        self.assertIn("tenantId + '|' + companyId + '|' + branchId", code)
        self.assertIn("key={props.tenantId + '|' + props.companyId + '|' + props.branchId}", code)
        self.assertIn("row.tenantId === tenantId", code)
        self.assertIn("row.companyId === companyId", code)
        self.assertIn("row.branchId === branchId", code)
        self.assertIn("companyId={context.data.active_legal_entity?.id ?? '__unassigned__'}", app)
        self.assertIn("tenantId={context.data.tenant.id}", app)
        self.assertIn("branchId={context.data.active_branch.id}", app)
        for forbidden in ("localStorage", "sessionStorage", "indexedDB", "fetch("):
            self.assertNotIn(forbidden, code)

    def test_permisos_y_auditoria(self) -> None:
        code, app = COMPONENT.read_text(), APP.read_text()
        for permission in ("inventory.view", "inventory.create", "inventory.update"):
            self.assertIn(permission, code)
        for gate in ("if (!canCreate", "if (!canView", "if (!canUpdate"):
            self.assertIn(gate, code)
        for operation in ("audit(reference, 'created')",
                          "audit(assetRef, 'viewed')",
                          "audit(selected, 'state_changed')"):
            self.assertIn(operation, code)
        self.assertIn("Bitácora de demostración (no oficial)", code)
        self.assertIn("auditoría de servidor", code)
        self.assertIn("import.meta.env.DEV", app)

    def test_sin_datos_reales(self) -> None:
        code = COMPONENT.read_text()
        for kind in ("asset", "responsible", "evidence"):
            found = re.search(rf"const {kind}RefPattern = /(.+?)/;", code)
            self.assertIsNotNone(found)
            assert found is not None
            regex = re.compile(found.group(1).replace(r"\/", "/"))
            self.assertIsNotNone(regex.fullmatch(f"fixture:{kind}/demo"))
            for bad in ("1002030405", "alguien@example.com", "3115551212",
                        "https://example.com", f"fixture:{kind}/../private",
                        f"fixture:{kind}/user@example.com"):
                self.assertIsNone(regex.fullmatch(bad))
        for forbidden in ("axios", "httpclient", "token:", "password:"):
            self.assertNotIn(forbidden, code.lower())
        self.assertIn("if (!import.meta.env.DEV) return null;", code)

    def test_estados_explicitos(self) -> None:
        code, app = COMPONENT.read_text(), APP.read_text()
        for state in ("INVENTARIADO", "ASIGNADO", "MANTENIMIENTO_PENDIENTE",
                      "BAJA_REQUIERE_REVISION"):
            self.assertIn(state, code)
        self.assertIn("setRecords(previous => previous.map", code)
        self.assertIn("onSubmit={changeState}", code)
        self.assertIn("onSubmit={create}", code)
        self.assertIn("Registrar transición ficticia explícita", code)
        self.assertIn("Ningún activo se aprueba, da de baja o cierra automáticamente.", code)
        self.assertNotIn("state: 'CERRADO'", code)
        self.assertNotIn("state: 'APROBADO'", code)
        self.assertIn("type=\"button\"", code)
        self.assertIn("role=\"alert\"", code)
        self.assertIn("role=\"status\"", code)
        self.assertIn("import.meta.env.DEV && (", app)
        self.assertIn("<AssetsManagement", app)


if __name__ == "__main__":
    unittest.main()
