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
        cls.backend = (ROOT/'src/Http/Controller/HumanResourcesController.php').read_text()

    def test_server_is_only_source_and_defaults_closed(self):
        self.assertIn("'hr_real_data_enabled' => getenv('HR_REAL_DATA_ENABLED') === 'true'", self.controller)
        self.assertIn('data-hr-real-data-enabled="{{ hr_real_data_enabled ? \'true\' : \'false\' }}"', self.twig)
        self.assertIn("getenv('HR_REAL_DATA_ENABLED') === 'true'", self.backend)
        self.assertIn("'feature_unavailable'", self.backend)
        self.assertNotIn('employee_ref', self.twig)
        self.assertNotIn('collaborator_ref', self.twig)

    def test_nav_and_component_only_after_exact_true(self):
        self.assertIn("dataset.hrRealDataEnabled === 'true'", self.app)
        self.assertIn("...(hrRealDataEnabled ? [{ href: '/admin#human-resources-management', label: 'Talento humano' }] : []),", self.app)
        self.assertIn('{hrRealDataEnabled && (', self.app)
        self.assertIn('<HumanResourcesManagement', self.app)
        self.assertNotIn('DevHumanResourcesManagement', self.app)
        self.assertNotIn('Talento Humano (pruebas)', self.app)

if __name__ == '__main__':
    unittest.main()
