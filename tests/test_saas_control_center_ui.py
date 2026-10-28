"""Contract acceptance for synthetic SaaS Control Center owner-only UI."""
from __future__ import annotations

import re
import unittest
from pathlib import Path

ROOT = Path(__file__).resolve().parents[1]
PANEL = ROOT / 'frontend/admin/PlatformSubscriptionsPanel.tsx'


class SaasTests(unittest.TestCase):
    @classmethod
    def setUpClass(cls) -> None:
        cls.source = PANEL.read_text(encoding='utf-8')

    def test_aislamiento_por_empresa(self) -> None:
        src = self.source
        self.assertIn('isSyntheticTenant(tenantId)', src)
        self.assertIn('encodeURIComponent(tenantId)', src)
        self.assertIn('pending.current?.abort()', src)
        self.assertIn('controller.signal.aborted', src)
        self.assertIn("key={tenantId + ':' + revision}", src)
        self.assertIn('tenantId={tenantId}', src)
        self.assertNotIn('localStorage', src)
        self.assertNotIn('sessionStorage', src)

    def test_auditoria_y_permisos(self) -> None:
        src = self.source
        self.assertIn('owner === true && syntheticOnly === true', src)
        self.assertIn('canMutateSyntheticSubscription(', src)
        self.assertIn("'X-CSRF-Token': csrfToken", src)
        self.assertIn("credentials: 'same-origin'", src)
        self.assertIn('if (!response.ok)', src)
        self.assertIn("setStatus('unknown')", src)
        self.assertIn('if (onChanged) await onChanged()', src)
        self.assertIn('inFlight.current = true', src)
        self.assertIn('if (pending.current === controller) {', src)
        self.assertIn('pending.current = null;\n        inFlight.current = false;', src)
        self.assertNotIn('audit_count: 1', src)
        self.assertNotIn('audit_id: ', src)

    def test_sin_datos_reales(self) -> None:
        src = self.source
        self.assertIn('^synthetic-tenant-', src)
        self.assertIn('^synthetic-plan-version-', src)
        self.assertIn('row.synthetic === true', src)
        self.assertIn('catalog.every(isSafePlan)', src)
        self.assertIn('catalog.some((plan) => plan.id === chosenPlan.id)', src)
        self.assertIn('JSON.stringify(operation ===', src)
        self.assertNotIn('customer_email', src)
        self.assertNotIn('bank_account', src)
        self.assertNotIn('amount_minor:', src)

    def test_fail_closed(self) -> None:
        src = self.source
        self.assertIn('UNKNOWN: se requiere propietario', src)
        self.assertIn('UNKNOWN: no se confirmó el cambio', src)
        self.assertIn('SaasControlCenterReadPanel', src)
        self.assertIn('planVersions.length <= 128 && planVersions.every(isSafePlan)', src)
        self.assertIn('csrfToken.trim().length >= 16', src)
        self.assertIn("setStatus('verifying')", src)
        self.assertIn('Releer datos canónicos', src)
        self.assertEqual([], re.findall(r'(?im)^\\s*(?:const|let)\\s+price\\s*=', src))


if __name__ == '__main__':
    unittest.main()
