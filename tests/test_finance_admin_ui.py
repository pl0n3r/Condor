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
        required_scopes = {
            "composite_key": "const scope = tenantId + '|' + companyId + '|' + branchId",
            "react_remount": "key={props.tenantId + '|' + props.companyId + '|' + props.branchId}",
            "filtered_view": "const scoped = records.filter",
            "tenant": "row.tenantId === tenantId",
            "company": "row.companyId === companyId",
            "branch": "row.branchId === branchId",
            "no_company": "companyId !== '__unassigned__'",
        }
        for boundary, signature in required_scopes.items():
            with self.subTest(boundary=boundary):
                self.assertIn(signature, self.code)
        for network_or_disk in ("fetch(", "axios", "localStorage", "sessionStorage", "indexedDB"):
            with self.subTest(forbidden=network_or_disk):
                self.assertNotIn(network_or_disk, self.code)

    def test_permisos_y_auditoria(self) -> None:
        policies = {
            "ver": "settings.view", "crear": "settings.create",
            "revisar": "settings.update", "lectura": "if (!canView)",
            "escritura": "if (!canCreate", "revision": "if (!canReview",
        }
        ledger = (
            "audit(reference, 'created')",
            "audit(ref, 'viewed')",
            "audit(selected, current.state",
        )
        for label, needle in policies.items():
            with self.subTest(policy=label):
                self.assertIn(needle, self.code)
        for statement in ledger:
            with self.subTest(audit=statement):
                self.assertIn(statement, self.code)
        self.assertIn("Bitácora de demostración (no oficial)", self.code)
        self.assertIn("auditoría de servidor", self.code)
        self.assertIn("if (!import.meta.env.DEV) return null;", self.code)

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
        # Las máscaras fixture: no legitiman documentos ni teléfonos de longitud real.
        self.assertIn("function isDemoReference(value: string, format: RegExp)", code)
        self.assertIn("format.test(value) && !/[0-9]{7,}/.test(value)", code)
        for use in (
            "isDemoReference(reference, financeRefPattern)",
            "isDemoReference(periodRef, periodRefPattern)",
            "isDemoReference(conceptRef, conceptRefPattern)",
            "isDemoReference(ref, financeRefPattern)",
            "isDemoReference(selected, financeRefPattern)",
        ):
            with self.subTest(guard=use):
                self.assertIn(use, code)
        self.assertIn("['0.00', '100.00', '250.00']", code)
        self.assertIn("SOLO DATOS FICTICIOS", code)
        self.assertIn("Sin valor legal", code)
        for forbidden in ("bankAccount", "taxId", "nationalId", "paymentMethod",
                          "fetch(", "axios", "indexedDB"):
            self.assertNotIn(forbidden, code)

    def test_estados_explicitos(self) -> None:
        expected_lifecycle = (
            "BORRADOR", "REQUIERE_REVISION", "EN_REVISION",
            "state: 'BORRADOR'",
            "current.state === 'BORRADOR' ? 'REQUIERE_REVISION' : 'EN_REVISION'",
            "onSubmit={changeState}", "onSubmit={create}",
            'type="button"', 'role="alert"', 'role="status"',
            'aria-live="polite"',
            "Ningún registro se aprueba, contabiliza, paga ni cierra automáticamente.",
        )
        illegal_shortcuts = (
            "state: 'APROBADO'", "state: 'CERRADO'",
            "state: 'PAGADO'", "setTimeout(", "setInterval(",
        )
        for marker in expected_lifecycle:
            with self.subTest(lifecycle=marker):
                self.assertIn(marker, self.code)
        for shortcut in illegal_shortcuts:
            with self.subTest(forbidden=shortcut):
                self.assertNotIn(shortcut, self.code)

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
        self.assertIn("href: '/admin#finance-management'", shell)


if __name__ == "__main__":
    unittest.main()
