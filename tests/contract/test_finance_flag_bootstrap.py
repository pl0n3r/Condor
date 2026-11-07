"""Condor #1659: puerta SSR, navegación y transporte fail-closed del módulo financiero."""
from pathlib import Path
import unittest

ROOT = Path(__file__).resolve().parents[2]


class FinanceFlagBootstrapTests(unittest.TestCase):
    @classmethod
    def setUpClass(cls):
        cls.server = (ROOT / "src/Http/Controller/AdminController.php").read_text()
        cls.twig = (ROOT / "templates/admin/index.html.twig").read_text()
        cls.app = (ROOT / "frontend/admin/AdminApp.tsx").read_text()
        cls.view = (ROOT / "frontend/admin/FinanceManagement.tsx").read_text()
        cls.backend = (ROOT / "src/Http/Controller/FinanceController.php").read_text()

    def test_criterio_principal(self):
        self.assertIn(
            "'finance_real_data_enabled' => getenv('FINANCE_REAL_DATA_ENABLED') === 'true'",
            self.server,
        )
        self.assertIn("data-finance-real-data-enabled", self.twig)
        self.assertIn("finance_real_data_enabled ? 'true' : 'false'", self.twig)
        self.assertIn("dataset.financeRealDataEnabled === 'true'", self.app)
        self.assertIn("getenv('FINANCE_REAL_DATA_ENABLED') === 'true'", self.backend)
        self.assertIn("<FinanceRealManagement", self.app)
        self.assertIn("enabled={financeRealDataEnabled}", self.app)
        self.assertIn("csrfToken={accessToken}", self.app)
        self.assertIn("key={'finance-real-'", self.app)
        self.assertIn("financeRealDataEnabled ? [{ href: '/admin#finance-management', label: 'Finanzas' }]", self.app)
        self.assertIn("DevFinanceManagement && !financeRealDataEnabled", self.app)

    def test_criterio_seguridad_y_bordes(self):
        self.assertIn("if (!options.enabled) return { status: 'disabled' };", self.view)
        self.assertIn("if (!options.allowed || !options.tenantId || !options.branchId", self.view)
        self.assertIn("'X-CSRF-Token'", self.view)
        self.assertIn("expected_version", self.view)
        self.assertIn("row.tenant_id !== tenant || row.company_id !== company", self.view)
        self.assertIn("credentials: 'same-origin'", self.view)
        self.assertIn("cache: 'no-store'", self.view)
        self.assertIn("Membership::ROLE_OWNER", self.backend)
        self.assertIn("'feature_unavailable'", self.backend)
        self.assertIn("financeRealScope($branchId, 'settings.view')", self.backend)
        self.assertIn("financeRealScope($branchId, 'settings.create')", self.backend)
        self.assertIn("financeRealScope($branchId, 'settings.update')", self.backend)
        self.assertNotIn("entry_ref", self.twig)
        self.assertNotIn("company_id", self.twig)
        self.assertNotIn("balance_demo", self.twig)
        self.assertIn("finance_real_data_enabled ? 'true' : 'false'", self.twig)


if __name__ == "__main__":
    unittest.main()
