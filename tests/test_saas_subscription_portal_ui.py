"""Contrato ejecutable del portal SaaS sintético de Condor #1068.

Frontend/build valida TypeScript. Estas pruebas vigilan invariantes de seguridad
y que el slice no simule cobros ni cree un catálogo paralelo.
"""
from pathlib import Path
import re
import unittest

ROOT = Path(__file__).resolve().parents[1]
PORTAL = ROOT / "frontend/admin/SubscriptionPortal.tsx"


class SaasTests(unittest.TestCase):
    @classmethod
    def setUpClass(cls) -> None:
        cls.source = PORTAL.read_text(encoding="utf-8")

    def test_aislamiento_por_empresa(self) -> None:
        source = self.source
        self.assertIn("value.tenant_id !== tenantId", source)
        self.assertIn("currentTenant.current !== tenantId", source)
        self.assertIn("currentTenant.current !== requestedTenant", source)
        self.assertIn("requestedTenant = tenantId", source)
        self.assertIn("new AbortController()", source)
        self.assertIn("return () => controller.abort()", source)
        self.assertIn("TENANT_ID.test(tenantId)", source)
        self.assertIn("tenantId: requestedTenant", source)
        self.assertIn("state.snapshot.tenant_id === tenantId", source)
        self.assertIn("const seen = new Set<string>()", source)
        self.assertIn("validRenewal(value.renewal_at)", source)
        self.assertIn("date.toISOString().slice(0, 10) === value", source)
        self.assertIn("seen.has(item.key)", source)
        self.assertNotIn("localStorage", source)
        self.assertNotIn("sessionStorage", source)

    def test_auditoria_y_permisos(self) -> None:
        source = self.source
        self.assertIn("snapshot?.permissions.can_request_plan_change === true", source)
        self.assertIn("!canRequest || !csrfToken", source)
        self.assertIn("!available.some((option) => option.key === selectedPlan)", source)
        self.assertIn("api.requestPlanChange({", source)
        self.assertIn("csrfToken,", source)
        self.assertIn("validAuditReceipt(receipt, requestedTenant, requestedPlan)", source)
        self.assertIn("AUDIT_ID.test(value.audit_id)", source)
        self.assertIn("inFlight.current = requestedTenant", source)
        self.assertIn("if (inFlight.current === requestedTenant) inFlight.current = null", source)
        self.assertIn("La suscripción todavía no cambió.", source)
        self.assertNotIn("setState({ status: 'ready', snapshot: {", source)
        self.assertNotIn("window.location", source)

    def test_sin_datos_reales(self) -> None:
        source = self.source
        self.assertIn("value.classification !== 'synthetic'", source)
        self.assertIn("value.source !== 'commercial-catalog'", source)
        self.assertIn("exactKeys(value", source)
        self.assertIn("exactKeys(item", source)
        self.assertIn("value.plan.currency !== 'COP'", source)
        self.assertIn("validSubscriptionSnapshot(payload, tenantId)", source)
        for forbidden in (
            "<input", "<textarea", "nit:", "email:", "password:",
            "payment_method", "credit_card", "card_number",
            "stripe", "mercadopago", "payu", "pse", "datos.yml",
        ):
            self.assertNotIn(forbidden, source.lower())

    def test_fail_closed(self) -> None:
        source = self.source
        self.assertIn("status: 'loading'", source)
        self.assertIn("status: 'empty'", source)
        self.assertIn("status: 'denied'", source)
        self.assertIn("status: 'error'", source)
        self.assertIn("status === 403 ? 'denied' : 'error'", source)
        self.assertIn("setState({ status: 'error' })", source)
        self.assertIn("setSelectedPlan('')", source)
        self.assertIn("setSubmission({ status: 'idle' })", source)
        self.assertIn("UNKNOWN", source)
        self.assertIn("minor === null) return 'UNKNOWN'", source)
        self.assertIn("Auditoría no verificable: UNKNOWN.", source)
        self.assertIn("classification: 'synthetic'", source)
        self.assertNotRegex(source, r"\b(price|amount|limit)\s*\?\?\s*0\b")
        self.assertFalse(re.search(r"Math\.random\(|fakePayment\(", source))


if __name__ == "__main__":
    unittest.main()
