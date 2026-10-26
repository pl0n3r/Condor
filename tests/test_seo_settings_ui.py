"""Acceptance wiring and safe-configuration invariants for Condor SEO UI (#934).

All fixtures are source-level, synthetic and disconnected from live tenants.
The API and persistence contracts are tested independently under #948.
"""
from pathlib import Path
import unittest

ROOT = Path(__file__).resolve().parents[1]
PANEL = (ROOT / "frontend/admin/SeoSettingsPanel.tsx").read_text(encoding="utf-8")
APP = (ROOT / "frontend/admin/AdminApp.tsx").read_text(encoding="utf-8")
HTTP = (ROOT / "src/Http/Controller/SeoSettingsController.php").read_text(
    encoding="utf-8"
)


class UiSeoIndexnowGtmTests(unittest.TestCase):
    def test_acceptance(self):
        """Rejects bad GTM identifiers, requires explicit enablement, shows IndexNow."""
        for value in [
            "export function SeoSettingsPanel",
            "permissions.includes('settings.view')",
            "permissions.includes('settings.update')",
            "const GTM_ID = /^GTM-[A-Z0-9]+$/;",
            "GTM_ID.test(normalizedGtm)",
            "aria-invalid={!validGtm}",
            "Identificador GTM inválido.",
            "disabled={busy || !validGtm || !gtmChanged}",
            "Guardar identificador GTM",
            "gtm: { container_id: normalizedGtm || null }",
            "gtm: { enabled: !ready.gtm.enabled }",
            "ready.gtm.container_id === null || gtmChanged",
            "ready.gtm.enabled ? 'Desactivar GTM' : 'Activar GTM'",
            "ready.indexnow.enabled ? 'Desactivar IndexNow' : 'Activar IndexNow'",
            "indexnow: { enabled: !ready.indexnow.enabled }",
            "Últimas notificaciones",
            "ready.indexnow.last_notifications.length === 0",
            "No hay notificaciones IndexNow registradas.",
            "entry.status ?? 'Estado no informado'",
            "readSettings(await response.json() as unknown)",
            "credentials: 'same-origin'",
            "'X-CSRF-Token': csrfToken",
            "inventoryPath(branchId).replace(/\\/inventory$/, '/seo/settings')",
            "requestId.current += 1",
            "No tienes permiso para consultar o guardar los ajustes SEO.",
            "status === 422 || status === 400",
            "role=\"alert\"",
            "aria-live=\"polite\"",
        ]:
            with self.subTest(fragment=value):
                self.assertIn(value, PANEL)
        self.assertIn("import { SeoSettingsPanel } from './SeoSettingsPanel';", APP)
        self.assertIn("href: '/admin#seo-settings'", APP)
        self.assertIn("<SeoSettingsPanel", APP)
        self.assertIn("key={'seo-settings-' + context.data.active_branch.id}", APP)
        self.assertIn("branchId={context.data.active_branch.id}", APP)
        self.assertIn("permissions={context.data.permissions}", APP)
        self.assertIn("csrfToken={accessToken}", APP)

    def test_two_step_backend_and_no_external_effects(self):
        self.assertIn("Configurar y activar GTM exige dos pasos separados.", HTTP)
        self.assertIn("$previous['gtm_enabled'] = false;", HTTP)
        self.assertIn("'last_notifications' => []", HTTP)
        self.assertIn("authorizedBranchScope($branchId, 'settings.view')", HTTP)
        self.assertIn("authorizedBranchScope($branchId, 'settings.update')", HTTP)
        self.assertIn("$this->requireCsrf($request)", HTTP)
        self.assertIn("TagManagerSettings::normalizeContainerId(", HTTP)
        self.assertNotIn("googletagmanager.com", PANEL)
        self.assertNotIn("indexnow.org", PANEL)
        self.assertNotIn("localStorage", PANEL)
        self.assertNotIn("style={{", PANEL)
        self.assertNotIn("document.createElement('script')", PANEL)
        self.assertNotIn("Math.random()", PANEL)


if __name__ == "__main__":
    unittest.main()
