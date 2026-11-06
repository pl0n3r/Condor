"""M06 regression: route/UI contract is wired without asserting legal compliance."""
import pathlib
import unittest

ROOT = pathlib.Path(__file__).resolve().parents[1]

class M06HttpUiIntegrationTest(unittest.TestCase):
    def test_six_segments_are_wired_with_safe_states(self):
        php = (ROOT / "src/Http/Controller/LegalComplianceController.php").read_text()
        ui = (ROOT / "frontend/admin/ComplianceManagement.tsx").read_text()
        admin = (ROOT / "frontend/admin/AdminApp.tsx").read_text()
        for section in ("perfil", "catalogo", "matriz", "calendario", "evaluaciones", "auditorias"):
            self.assertIn(section, php)
            self.assertIn(section, ui)
        for gate in ("authorizedBranchScope", "settings.view", "settings.create",
                     "settings.update", "requireCsrf", "CONDOR_LEGAL_SYNTHETIC_API"):
            self.assertIn(gate, php)
        for state in ("loading", "ready", "error", "denied"):
            self.assertIn(state, ui)
        self.assertIn("LegalComplianceManagement", admin)
        self.assertIn("automatically_closed' => false", php)

if __name__ == "__main__":
    unittest.main()
