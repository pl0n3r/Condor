#!/usr/bin/env python3
"""M12 · Riesgos empresariales: contrato de UI únicamente sintética."""
from __future__ import annotations

import re
import unittest
from pathlib import Path

ROOT = Path(__file__).resolve().parents[1]
COMPONENT = ROOT / "frontend/admin/RiskManagement.tsx"


def source() -> str:
    return COMPONENT.read_text(encoding="utf-8")


class ModuloTests(unittest.TestCase):
    def test_aislamiento_por_empresa(self) -> None:
        code = source()
        self.assertIn("tenantId + '|' + companyId + '|' + branchId", code)
        self.assertIn("key={props.tenantId + '|' + props.companyId + '|' + props.branchId}", code)
        for guard in ("row.tenantId === tenantId", "row.companyId === companyId",
                      "row.branchId === branchId"):
            self.assertIn(guard, code)
        self.assertIn("companyId !== '__unassigned__'", code)
        self.assertIn("item.scope === scope", code)
        self.assertIn("row.ref === detail.ref", code)
        for forbidden in ("fetch(", "localStorage", "sessionStorage", "indexedDB", "axios"):
            self.assertNotIn(forbidden, code)

    def test_permisos_y_auditoria(self) -> None:
        code = source()
        for permission in ("inventory.view", "inventory.create", "inventory.update"):
            self.assertIn(permission, code)
        for check in ("if (!canView)", "if (!canCreate", "if (!canUpdate"):
            self.assertIn(check, code)
        for audit in ("audit(riskRef, 'created'", "audit(ref, 'viewed'",
                      "audit(detail.ref, 'state_changed'"):
            self.assertIn(audit, code)
        self.assertIn("setHistory(previous =>", code)
        self.assertIn("Bitácora de demostración (no oficial)", code)
        self.assertIn("La bitácora local no sustituye la auditoría de servidor.", code)
        self.assertIn("if (!import.meta.env.DEV) return null;", code)

    def test_sin_datos_reales(self) -> None:
        code = source()
        for kind in ("risk", "actor"):
            found = re.search(rf"const {kind}RefPattern = /(.+?)/;", code)
            self.assertIsNotNone(found)
            assert found is not None
            pattern = re.compile(found.group(1).replace(r"\/", "/"))
            self.assertIsNotNone(pattern.fullmatch(f"fixture:{kind}/demo"))
            for rejected in ("1002030405", "persona@example.com", "3115551212",
                             "https://example.com", f"fixture:{kind}/../private",
                             f"fixture:{kind}/user@example.com", f"fixture:{kind}/"):
                self.assertIsNone(pattern.fullmatch(rejected), rejected)
        self.assertIn("digitSequencePattern = /[0-9]{7,}/", code)
        self.assertIn("const searchPrefix = 'fixture:risk/';", code)
        self.assertIn("searchPrefix.startsWith(value)", code)
        self.assertIn("value.startsWith(searchPrefix) && validFixture(value, riskRefPattern)", code)
        self.assertIn("!digitSequencePattern.test(value)", code)
        self.assertIn("if (validSearch(value)) setSearch(value)", code)
        self.assertIn("!digitSequencePattern.test(reference)", code)
        self.assertIn("SOLO DATOS FICTICIOS", code)
        self.assertIn("sin API", code)
        for forbidden in ("fetch(", "axios", "localStorage", "sessionStorage",
                          "indexedDB", "window.location", "document.cookie"):
            self.assertNotIn(forbidden, code)

    def test_estados_explicitos(self) -> None:
        code = source()
        self.assertIn("'PENDIENTE' | 'REQUIERE_REVISION'", code)
        self.assertIn("detail.state === 'PENDIENTE'", code)
        self.assertIn("onSubmit={create}", code)
        self.assertIn("onSubmit={changeState}", code)
        self.assertIn('type="button"', code)
        self.assertIn("Registrar transición ficticia explícita", code)
        self.assertIn("Ningún riesgo se aprueba ni se cierra automáticamente.", code)
        for forbidden in ("state: 'CERRADO'", "state: 'APROBADO'",
                          "setTimeout(", "setInterval(", "useEffect("):
            self.assertNotIn(forbidden, code)
        for accessibility in ('role="alert"', 'role="status"',
                              'aria-live="polite"', 'aria-pressed='):
            self.assertIn(accessibility, code)


if __name__ == "__main__":
    unittest.main()
