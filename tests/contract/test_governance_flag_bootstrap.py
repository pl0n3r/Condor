"""Contrato SSR/React del gate de Gobierno corporativo, exclusivamente sintético."""
import unittest
from pathlib import Path

ROOT = Path(__file__).resolve().parents[2]


class GovernanceFlagBootstrapTests(unittest.TestCase):
    @classmethod
    def setUpClass(cls):
        cls.admin = (ROOT / 'src/Http/Controller/AdminController.php').read_text()
        cls.twig = (ROOT / 'templates/admin/index.html.twig').read_text()
        cls.app = (ROOT / 'frontend/admin/AdminApp.tsx').read_text()
        cls.view = (ROOT / 'frontend/admin/GovernanceManagement.tsx').read_text()
        cls.api = (ROOT / 'src/Http/Controller/GovernanceController.php').read_text()

    def test_server_exclusively_authorizes_strict_true_flag(self):
        self.assertIn(
            "'governance_real_data_enabled' => getenv('GOVERNANCE_REAL_DATA_ENABLED') === 'true'",
            self.admin,
        )
        self.assertIn(
            """data-governance-real-data-enabled="{{ governance_real_data_enabled ? 'true' : 'false' }}\"""",
            self.twig,
        )
        self.assertIn("dataset.governanceRealDataEnabled === 'true'", self.app)
        self.assertIn("getenv('GOVERNANCE_REAL_DATA_ENABLED') === 'true'", self.api)
        self.assertIn("'feature_unavailable'", self.api)

    def test_module_is_visible_only_if_server_enabled_or_explicit_dev_fallback(self):
        self.assertIn("governanceRealDataEnabled ? [{ href: '/admin#governance-management'", self.app)
        self.assertIn("(governanceRealDataEnabled || import.meta.env.DEV)", self.app)
        self.assertIn('enabled={governanceRealDataEnabled}', self.app)
        self.assertIn('context.data.active_branch.legal_entity?.id === context.data.active_legal_entity.id', self.app)
        self.assertIn('csrfToken={accessToken}', self.app)
        self.assertIn("if (!options.enabled) return { status: 'disabled' };", self.view)
        self.assertIn("if (!options.allowed", self.view)
        self.assertIn("if (!enabled) return <section", self.view)
        self.assertNotIn('fixture:', self.view)
        self.assertIn("'X-CSRF-Token'", self.view)

    def test_api_uses_owner_scope_csrf_and_optimistic_revision(self):
        self.assertIn('Membership::ROLE_OWNER', self.api)
        self.assertIn('governanceRealScope($branchId', self.api)
        self.assertIn('governanceValidateCsrf($request)', self.api)
        self.assertIn("'inventory.view'", self.api)
        self.assertIn("'inventory.create'", self.api)
        self.assertIn("'inventory.update'", self.api)
        self.assertIn("'expected_version'", self.api)
        self.assertIn("next_cursor", (ROOT / 'src/Application/Governance/GovernanceRecordService.php').read_text())

    def test_zero_personal_data_in_bootstrap(self):
        self.assertNotIn('record_ref', self.twig)
        self.assertNotIn('actor_id', self.twig)
        self.assertNotIn('company_id', self.twig)
        self.assertNotIn('data-governance-records', self.twig)


if __name__ == '__main__':
    unittest.main()
