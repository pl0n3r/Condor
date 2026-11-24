"""#1663: Cumplimiento legal SSR/UI gate; only exact server true enables real API."""
from pathlib import Path
import unittest

ROOT = Path(__file__).resolve().parents[2]


class LegalFlagBootstrapTests(unittest.TestCase):
    @classmethod
    def setUpClass(cls):
        cls.php = (ROOT / "src/Http/Controller/AdminController.php").read_text()
        cls.twig = (ROOT / "templates/admin/index.html.twig").read_text()
        cls.app = (ROOT / "frontend/admin/AdminApp.tsx").read_text()
        cls.nav = (ROOT / "frontend/admin/CondorNavigationCatalog.ts").read_text()
        cls.ui = (ROOT / "frontend/admin/ComplianceManagement.tsx").read_text()
        cls.backend = (ROOT / "src/Http/Controller/ComplianceController.php").read_text()

    def test_strict_bootstrap_and_on_menu(self):
        self.assertIn(
            "'legal_compliance_real_data_enabled' => getenv('LEGAL_COMPLIANCE_REAL_DATA_ENABLED') === 'true'",
            self.php,
        )
        self.assertIn("data-legal-compliance-real-data-enabled", self.twig)
        self.assertIn("legal_compliance_real_data_enabled ? 'true' : 'false'", self.twig)
        self.assertIn("dataset.legalComplianceRealDataEnabled === 'true'", self.app)
        self.assertIn('flags.legalComplianceRealDataEnabled', self.nav)
        self.assertIn("link('compliance-evidence-management', 'Cumplimiento legal')", self.nav)
        self.assertIn("enabled={legalComplianceRealDataEnabled}", self.app)
        self.assertIn("<LegalComplianceRealManagement", self.app)

    def test_off_never_mounts_synthetic_api_or_fake_rows(self):
        self.assertNotIn("<DevComplianceEvidenceManagement", self.app)
        self.assertNotIn("<DevLegalComplianceManagement", self.app)
        self.assertNotIn("Cumplimiento legal (pruebas)", self.app)
        self.assertIn("link('compliance-evidence-management', 'Cumplimiento legal (no habilitado)')", self.nav)
        self.assertIn("Este módulo aún no está habilitado", self.app)
        self.assertIn("if (!options.enabled) return { status: 'disabled' };", self.ui)
        self.assertIn("enabled, allowed", self.ui)
        self.assertIn("legalComplianceRealRequest(", self.ui)

    def test_guarded_server_authority_and_csrf(self):
        self.assertIn("getenv('LEGAL_COMPLIANCE_REAL_DATA_ENABLED') === 'true'", self.backend)
        self.assertIn("Membership::ROLE_OWNER", self.backend)
        self.assertIn("['error' => 'feature_unavailable']", self.backend)
        self.assertIn("'/api/v1/branches/{branchId}/compliance/real-records'", self.backend)
        self.assertIn("'settings.create'", self.backend)
        self.assertIn("'settings.update'", self.backend)
        self.assertIn("'X-CSRF-Token'", self.ui)
        self.assertIn("expected_version", self.ui)
        self.assertIn("row.tenant_id !== tenantId || row.company_id !== companyId", self.ui)
        self.assertNotIn("record_ref", self.twig)
        self.assertNotIn("tenant_id", self.twig)



    def test_server_default_on_with_explicit_emergency_off(self):
        bootstrap = (ROOT / "config/bootstrap.php").read_text()
        example = (ROOT / ".env.example").read_text()
        self.assertIn("'LEGAL_COMPLIANCE_REAL_DATA_ENABLED'", bootstrap)
        self.assertIn("getenv($moduleFlag) !== false", bootstrap)
        self.assertIn("array_key_exists($moduleFlag, $_ENV)", bootstrap)
        self.assertIn("array_key_exists($moduleFlag, $_SERVER)", bootstrap)
        self.assertIn("putenv($moduleFlag.'=true')", bootstrap)
        self.assertIn("$_ENV[$moduleFlag] = 'true'", bootstrap)
        self.assertIn("$_SERVER[$moduleFlag] = 'true'", bootstrap)
        self.assertIn("LEGAL_COMPLIANCE_REAL_DATA_ENABLED=true", example)

if __name__ == "__main__":
    unittest.main()
