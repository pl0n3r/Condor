"""Condor #1055: cuatro aceptaciones fail-closed de UI Calidad offline."""
from pathlib import Path
import re
import unittest

ROOT = Path(__file__).resolve().parents[1]
PANEL = (ROOT / "frontend/admin/QualityManagement.tsx").read_text(encoding="utf-8")


class ModuloTests(unittest.TestCase):
    def test_aislamiento_por_empresa(self):
        self.assertIn("tenantId: string; companyId: string; branchId: string;", PANEL)
        self.assertIn("const scope = tenantId + '|' + companyId + '|' + branchId;", PANEL)
        for guard in ("row.tenantId === tenantId", "row.companyId === companyId",
                      "row.branchId === branchId", "scoped.some(row => row.ref === ref)"):
            self.assertIn(guard, PANEL)
        self.assertIn("key={[props.tenantId, props.companyId, props.branchId].join('|')}", PANEL)
        self.assertIn("history.filter(item => item.scope === scope)", PANEL)
        self.assertNotIn("localStorage", PANEL)
        self.assertNotIn("sessionStorage", PANEL)

    def test_permisos_y_auditoria(self):
        for permission in ("inventory.view", "inventory.create", "inventory.update"):
            self.assertIn("permissions.includes('" + permission + "')", PANEL)
        for guard in ("if (!canView)", "if (!canCreate", "if (!canUpdate"):
            self.assertIn(guard, PANEL)
        for operation in ("audit(ref, 'created', actorRef)",
                          "audit(item, 'viewed')",
                          "audit(item, 'state_changed', reviewActorRef)"):
            self.assertIn(operation, PANEL)
        self.assertIn("setHistory(previous =>", PANEL)
        self.assertIn("No sustituye la auditoría", PANEL.replace(
            "La bitácora local no sustituye la auditoría", "No sustituye la auditoría"))

    def test_sin_datos_reales(self):
        self.assertIn("if (!import.meta.env.DEV) return null", PANEL)
        for pattern in ("recordPattern.test(ref)", "findingPattern.test(findingRef)",
                        "actorPattern.test(actorRef)", "actorPattern.test(reviewActorRef)"):
            self.assertIn(pattern, PANEL)
        self.assertIn("SOLO DATOS FICTICIOS", PANEL)
        self.assertNotIn("fetch(", PANEL)
        self.assertNotIn("XMLHttpRequest", PANEL)
        self.assertNotIn("sendBeacon(", PANEL)
        for prefix in ("quality", "finding", "actor"):
            exp = rf"fixture:{prefix}/[a-z][a-z0-9_-]{{0,47}}"
            self.assertIsNotNone(re.fullmatch(exp, f"fixture:{prefix}/demo_1"))
            for unsafe in ("someone@example.com", "3001234567", "../admin",
                           "https://example.com", f"fixture:{prefix}/Nombre Real"):
                self.assertIsNone(re.fullmatch(exp, unsafe))

    def test_estados_explicitos(self):
        self.assertIn("'PENDIENTE' | 'EN_REVISION' | 'CIERRE_REQUIERE_REVISION'", PANEL)
        self.assertIn("detail?.status === 'PENDIENTE' ? 'EN_REVISION'", PANEL)
        self.assertIn("detail?.status === 'EN_REVISION' ? 'CIERRE_REQUIERE_REVISION'", PANEL)
        self.assertIn("nextStatus === null", PANEL)
        self.assertIn("onSubmit={changeState}", PANEL)
        self.assertIn("onSubmit={create}", PANEL)
        self.assertIn('aria-live="polite"', PANEL)
        self.assertIn("No hay transición automática a APROBADO ni CERRADO", PANEL)
        self.assertNotIn("setInterval(", PANEL)
        self.assertNotIn("setTimeout(", PANEL)
        self.assertNotIn("status: 'CERRADO'", PANEL)


if __name__ == "__main__":
    unittest.main()
