"""Contrato de SSR y aislamiento del Servicio al cliente real, sin datos personales."""
import unittest
from pathlib import Path

ROOT = Path(__file__).resolve().parents[2]


class CustomerServiceFlagBootstrapTests(unittest.TestCase):
    @classmethod
    def setUpClass(cls):
        cls.admin = (ROOT / "src/Http/Controller/AdminController.php").read_text()
        cls.twig = (ROOT / "templates/admin/index.html.twig").read_text()
        cls.app = (ROOT / "frontend/admin/AdminApp.tsx").read_text()
        cls.view = (ROOT / "frontend/admin/CustomerServiceManagement.tsx").read_text()
        cls.api = (ROOT / "src/Http/Controller/CustomerServiceController.php").read_text()

    def test_flag_stricto_y_bootstrap_sin_pii(self):
        self.assertIn("'customer_service_real_data_enabled' => getenv('CUSTOMER_SERVICE_REAL_DATA_ENABLED') === 'true'", self.admin)
        self.assertIn('data-customer-service-real-data-enabled="{{ customer_service_real_data_enabled ?', self.twig)
        self.assertIn("dataset.customerServiceRealDataEnabled === 'true'", self.app)
        self.assertIn("getenv('CUSTOMER_SERVICE_REAL_DATA_ENABLED') === 'true'", self.api)
        for forbidden in ("record_ref", "actor_id", "company_id", "customer_service_real_records"):
            self.assertNotIn(forbidden, self.twig)

    def test_montaje_protegido_off_sin_fetch_ni_simulador(self):
        self.assertIn("customerServiceRealDataEnabled ? [{ href: '/admin#customer-service'", self.app)
        self.assertIn("(customerServiceRealDataEnabled || import.meta.env.DEV)", self.app)
        self.assertIn("enabled={customerServiceRealDataEnabled}", self.app)
        self.assertIn("import('./CustomerServiceManagement')", self.app)
        self.assertIn("<AsyncCustomerServiceManagement", self.app)
        self.assertIn("context.data.active_branch.legal_entity?.id === context.data.active_legal_entity.id", self.app)
        self.assertIn("companyId={context.data.active_legal_entity?.id", self.app)
        self.assertIn("if (!enabled) return <section", self.view)
        self.assertIn("if (!opt.enabled) return { status: 'disabled' };", self.view)
        self.assertIn("if (!opt.allowed", self.view)
        self.assertNotIn("fixture:", self.view)
        self.assertIn("credentials: 'same-origin'", self.view)

    def test_owner_csrf_version_y_ambito_servidor(self):
        for guard in ("Membership::ROLE_OWNER", "requireCsrf($request)", "'inventory.view'", "'inventory.create'",
                      "'inventory.update'", "'expected_version'", "'feature_unavailable'",
                      "$legalEntity?->tenant()->id() !== $tenant->id()"):
            with self.subTest(guard=guard):
                self.assertIn(guard, self.api)
        self.assertIn("next_cursor", (ROOT / "src/Application/CustomerService/SupportCaseService.php").read_text())

    def test_ui_con_estados_y_api_real(self):
        for value in ('customer-service-real/records', 'role=\"alert\"', 'role=\"status\"',
                      "PageHeader", "MetricStrip", "Toolbar", "DataTable", "SidePanel",
                      "'X-CSRF-Token'", "expected_version"):
            self.assertIn(value, self.view)


if __name__ == "__main__":
    unittest.main()
