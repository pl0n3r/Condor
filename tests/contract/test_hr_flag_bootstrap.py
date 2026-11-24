"""Synthetic contract for owner-approved HR feature bootstrap (#1778)."""
import unittest
from pathlib import Path

ROOT = Path(__file__).resolve().parents[2]

class HrFlagBootstrapContract(unittest.TestCase):
    @classmethod
    def setUpClass(cls):
        cls.controller = (ROOT/'src/Http/Controller/AdminController.php').read_text()
        cls.twig = (ROOT/'templates/admin/index.html.twig').read_text()
        cls.app = (ROOT/'frontend/admin/AdminApp.tsx').read_text()
        cls.nav = (ROOT / "frontend/admin/CondorNavigationCatalog.ts").read_text()
        cls.backend = (ROOT/'src/Http/Controller/HumanResourcesController.php').read_text()

    def test_server_is_only_source_and_defaults_visible(self):
        self.assertIn("'hr_real_data_enabled' => getenv('HR_REAL_DATA_ENABLED') === 'true'", self.controller)
        self.assertIn('data-hr-real-data-enabled="{{ hr_real_data_enabled ? \'true\' : \'false\' }}"', self.twig)
        self.assertIn("getenv('HR_REAL_DATA_ENABLED') === 'true'", self.backend)
        self.assertIn("'feature_unavailable'", self.backend)
        self.assertNotIn('employee_ref', self.twig)
        self.assertNotIn('collaborator_ref', self.twig)

    def test_nav_and_component_only_after_exact_true(self):
        self.assertIn("dataset.hrRealDataEnabled === 'true'", self.app)
        self.assertIn('flags.hrRealDataEnabled', self.nav)
        self.assertIn("link('human-resources-management', 'Talento humano')", self.nav)
        self.assertIn('{hrRealDataEnabled && (', self.app)
        self.assertIn('<HumanResourcesManagement', self.app)
        self.assertNotIn('DevHumanResourcesManagement', self.app)
        self.assertNotIn('Talento Humano (pruebas)', self.app)


    def test_server_default_on_with_explicit_emergency_off(self):
        bootstrap = (ROOT / "config/bootstrap.php").read_text()
        example = (ROOT / ".env.example").read_text()
        self.assertIn("'HR_REAL_DATA_ENABLED'", bootstrap)
        self.assertIn("getenv($moduleFlag) !== false", bootstrap)
        self.assertIn("array_key_exists($moduleFlag, $_ENV)", bootstrap)
        self.assertIn("array_key_exists($moduleFlag, $_SERVER)", bootstrap)
        self.assertIn("putenv($moduleFlag.'=true')", bootstrap)
        self.assertIn("$_ENV[$moduleFlag] = 'true'", bootstrap)
        self.assertIn("$_SERVER[$moduleFlag] = 'true'", bootstrap)
        self.assertIn("HR_REAL_DATA_ENABLED=true", example)

if __name__ == '__main__':
    unittest.main()
