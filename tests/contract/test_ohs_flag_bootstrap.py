"""#1661: bootstrap SG-SST jamás habilitado por cliente ni por defecto."""
from pathlib import Path
import unittest

ROOT = Path(__file__).resolve().parents[2]


class OhsFlagBootstrapTests(unittest.TestCase):
    @classmethod
    def setUpClass(cls):
        cls.php = (ROOT / "src/Http/Controller/AdminController.php").read_text()
        cls.twig = (ROOT / "templates/admin/index.html.twig").read_text()
        cls.app = (ROOT / "frontend/admin/AdminApp.tsx").read_text()
        cls.ui = (ROOT / "frontend/admin/ComplianceManagement.tsx").read_text()
        cls.backend = (ROOT / "src/Http/Controller/ComplianceController.php").read_text()

    def test_criterio_principal(self):
        self.assertIn(
            "'ohs_real_data_enabled' => getenv('OHS_REAL_DATA_ENABLED') === 'true'",
            self.php,
        )
        self.assertIn("data-ohs-real-data-enabled", self.twig)
        self.assertIn("ohs_real_data_enabled ? 'true' : 'false'", self.twig)
        self.assertIn("dataset.ohsRealDataEnabled === 'true'", self.app)
        self.assertIn("enabled={ohsRealDataEnabled}", self.app)
        self.assertIn("<ComplianceManagement", self.app)
        self.assertNotIn("<ComplianceSyntheticPreview", self.app)
        self.assertIn("getenv('OHS_REAL_DATA_ENABLED') === 'true'", self.backend)

    def test_criterio_seguridad_y_bordes(self):
        self.assertIn("if (!options.enabled) return { status: 'disabled' };", self.ui)
        self.assertIn("if (!options.allowed || !options.tenantId || !options.branchId", self.ui)
        self.assertIn("'X-CSRF-Token'", self.ui)
        self.assertIn("expected_version", self.ui)
        self.assertIn("row.tenant_id !== tenantId || row.company_id !== companyId", self.ui)
        self.assertIn("credentials: 'same-origin'", self.ui)
        self.assertIn("cache: 'no-store'", self.ui)
        self.assertIn("Membership::ROLE_OWNER", self.backend)
        self.assertIn("'feature_unavailable'", self.backend)
        self.assertIn("occupationalRealScope($branchId, 'settings.view')", self.backend)
        self.assertIn("'settings.create'", self.backend)
        self.assertIn("'settings.update'", self.backend)
        self.assertNotIn("record_ref", self.twig)
        self.assertNotIn("tenant_id", self.twig)
        self.assertNotIn("inspeccion_demo", self.twig)

    def test_menu_apaga_simulador_en_produccion(self):
        self.assertIn("ohsRealDataEnabled || import.meta.env.DEV", self.app)
        self.assertIn("SG-SST (no habilitado)", self.app)
        self.assertIn("import.meta.env.DEV", self.ui)
        self.assertIn("SG-SST aún no está habilitado", self.ui)


if __name__ == "__main__":
    unittest.main()
