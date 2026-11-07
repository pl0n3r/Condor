"""M05: bootstrap SSR del módulo persistente; no activa datos reales."""
from pathlib import Path
import unittest

ROOT = Path(__file__).resolve().parents[2]


class SocialSecurityFlagBootstrapContract(unittest.TestCase):
    @classmethod
    def setUpClass(cls):
        cls.php = (ROOT / 'src/Http/Controller/AdminController.php').read_text()
        cls.twig = (ROOT / 'templates/admin/index.html.twig').read_text()
        cls.app = (ROOT / 'frontend/admin/AdminApp.tsx').read_text()
        cls.view = (ROOT / 'frontend/admin/SocialSecurityManagement.tsx').read_text()
        cls.backend = (ROOT / 'src/Http/Controller/SocialSecurityController.php').read_text()

    def test_server_exact_flag_and_template_off_by_default(self):
        self.assertIn("'social_security_real_data_enabled' => getenv('SOCIAL_SECURITY_REAL_DATA_ENABLED') === 'true'", self.php)
        self.assertIn("data-social-security-real-data-enabled", self.twig)
        self.assertIn("social_security_real_data_enabled ? 'true' : 'false'", self.twig)
        self.assertIn("dataset.socialSecurityRealDataEnabled === 'true'", self.app)
        self.assertIn("getenv('SOCIAL_SECURITY_REAL_DATA_ENABLED') === 'true'", self.backend)
        self.assertNotIn('record_ref', self.twig)
        self.assertNotIn('worker_ref', self.twig)

    def test_navigation_real_only_on_and_no_fixtures_off(self):
        self.assertIn("socialSecurityRealDataEnabled ? [{ href: '/admin#social-security-management', label: 'Seguridad social' }]", self.app)
        self.assertIn("label: 'Seguridad social (pruebas)'", self.app)
        self.assertIn('<SocialSecurityRealManagement', self.app)
        self.assertIn('enabled={socialSecurityRealDataEnabled}', self.app)
        self.assertIn('csrfToken={accessToken}', self.app)
        self.assertNotIn('<DevSocialSecurityManagement', self.app)
        self.assertIn("if (!options.enabled) return { status: 'disabled' };", self.view)
        self.assertIn("if (!options.allowed || !options.tenantId", self.view)
        self.assertIn("'X-CSRF-Token'", self.view)
        self.assertIn("expected_version", self.view)

    def test_owner_permission_scope_and_off_on_server(self):
        self.assertIn("Membership::ROLE_OWNER", self.backend)
        self.assertIn("private function recordScope(", self.backend)
        self.assertIn("private function realCompany(", self.backend)
        self.assertIn("if (getenv('SOCIAL_SECURITY_REAL_DATA_ENABLED') === 'true')", self.backend)
        self.assertIn("'feature_unavailable'", self.backend)
        for value in ['users.view', 'users.create', 'users.update']:
            self.assertIn(f"'{value}'", self.backend)
        self.assertIn('requireCsrf($request)', self.backend)
        self.assertIn("['state', 'expected_version']", self.backend)

    def test_product_stays_in_construction_without_customer_data(self):
        data = (ROOT / 'datos.yml').read_text()
        self.assertIn('"phase": "construccion"', data)
        self.assertIn('"nothing_live": true', data)
        self.assertIn('"no_real_customer_data": true', data)


if __name__ == '__main__':
    unittest.main()
