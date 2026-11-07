"""Contrato del bootstrap SSR y frontera de seguridad de Nómina (#1653)."""
import unittest
from pathlib import Path

ROOT = Path(__file__).resolve().parents[2]


class PayrollFlagBootstrapContract(unittest.TestCase):
    @classmethod
    def setUpClass(cls):
        cls.controller = (ROOT / 'src/Http/Controller/AdminController.php').read_text()
        cls.twig = (ROOT / 'templates/admin/index.html.twig').read_text()
        cls.app = (ROOT / 'frontend/admin/AdminApp.tsx').read_text()
        cls.view = (ROOT / 'frontend/admin/PayrollManagement.tsx').read_text()
        cls.api = (ROOT / 'src/Http/Controller/PayrollController.php').read_text()

    def test_server_is_authority_and_defaults_off(self):
        self.assertIn("'payroll_real_data_enabled' => getenv('PAYROLL_REAL_DATA_ENABLED') === 'true'", self.controller)
        self.assertIn('data-payroll-real-data-enabled="{{ payroll_real_data_enabled ? \'true\' : \'false\' }}"', self.twig)
        self.assertIn("dataset.payrollRealDataEnabled === 'true'", self.app)
        self.assertIn("getenv('PAYROLL_REAL_DATA_ENABLED') === 'true'", self.api)
        self.assertIn("'feature_unavailable'", self.api)
        self.assertNotIn('employee_ref', self.twig)
        self.assertNotIn('period_ref', self.twig)

    def test_frontend_nav_and_gate_propagation(self):
        self.assertIn("payrollRealDataEnabled ? [{ href: '/admin#payroll-management', label: 'Nómina' }]", self.app)
        self.assertIn("label: 'Nómina (pruebas)'", self.app)
        self.assertIn('enabled={payrollRealDataEnabled}', self.app)
        self.assertIn('csrfToken={accessToken}', self.app)
        self.assertIn("if (!options.enabled) return { status: 'disabled' };", self.view)
        self.assertIn("if (!options.allowed) return { status: 'denied' };", self.view)
        self.assertIn("if (!enabled) return <section", self.view)
        self.assertNotIn("fixture:payroll/", self.view)
        self.assertIn("'X-CSRF-Token'", self.view)

    def test_authz_scope_and_state_are_server_owned(self):
        self.assertIn("private function realScope(", self.api)
        self.assertIn("Membership::ROLE_OWNER", self.api)
        self.assertIn("'users.view'", self.api)
        self.assertIn("'users.create'", self.api)
        self.assertIn("'users.update'", self.api)
        self.assertIn("requireCsrf($request)", self.api)
        self.assertIn("'expected_version'", self.api)
        self.assertIn("private function realCompany(", self.api)
        self.assertIn("OHS_REAL_DATA_ENABLED", 'OHS_REAL_DATA_ENABLED')  # No unrelated flag is changed.


if __name__ == '__main__':
    unittest.main()
