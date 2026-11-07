"""D-063: bootstrap de Contratación persistida apagado por defecto (solo lectura de código)."""
from pathlib import Path
import unittest

ROOT = Path(__file__).resolve().parents[2]


class ContractsRealFlagBootstrapTests(unittest.TestCase):
    def test_server_flag_must_be_exact_true(self):
        controller = (ROOT / "src/Http/Controller/AdminController.php").read_text()
        twig = (ROOT / "templates/admin/index.html.twig").read_text()
        app = (ROOT / "frontend/admin/AdminApp.tsx").read_text()
        self.assertIn("'contracts_real_data_enabled' => getenv('CONTRACTS_REAL_DATA_ENABLED') === 'true'", controller)
        self.assertIn("data-contracts-real-data-enabled=\"{{ contracts_real_data_enabled ? 'true' : 'false' }}\"", twig)
        self.assertIn("dataset.contractsRealDataEnabled === 'true'", app)
        self.assertIn("contractsRealDataEnabled && (", app)
        self.assertIn("<ContractsRealManagement", app)
        self.assertIn("enabled={contractsRealDataEnabled}", app)

    def test_no_synthetic_ui_in_real_mode_and_no_real_ui_when_disabled(self):
        app = (ROOT / "frontend/admin/AdminApp.tsx").read_text()
        self.assertIn("!contractsRealDataEnabled && DevContractsManagement", app)
        self.assertIn("label: 'Contratación' ", app)
        self.assertIn("label: 'Contratación (pruebas)'", app)
        real = (ROOT / "frontend/admin/ContractsManagement.tsx").read_text()
        self.assertIn("if (!options.enabled) return { status: 'disabled' }", real)
        self.assertIn("if (!options.allowed) return { status: 'denied' }", real)
        self.assertIn("options.companyId === '__unassigned__'", real)
        self.assertIn("credentials: 'same-origin'", real)
        self.assertIn("'X-CSRF-Token'", real)
        self.assertIn("expected_version", real)
        self.assertIn("No hay firma, contratación real ni aprobación.", real)

    def test_backend_hard_gate_and_company_scope(self):
        api = (ROOT / "src/Http/Controller/ContractsController.php").read_text()
        self.assertIn("getenv('CONTRACTS_REAL_DATA_ENABLED') !== 'true'", api)
        self.assertIn("['error' => 'feature_unavailable']", api)
        self.assertIn("Membership::ROLE_OWNER", api)
        self.assertIn("requireCsrf", api)
        self.assertIn("legalEntity->tenant()->id() !== $tenant->id()", api)
        self.assertIn("['state', 'expected_version']", api)

    def test_no_real_feature_activation_in_defaults(self):
        data = (ROOT / "datos.yml").read_text()
        self.assertIn('"phase": "construccion"', data)
        self.assertIn('"nothing_live": true', data)
        self.assertIn('"no_real_customer_data": true', data)


if __name__ == '__main__':
    unittest.main()
