"""Contrato SSR, ámbito y transporte para Procesos, siempre sintético y pre-live."""
import unittest
from pathlib import Path

ROOT = Path(__file__).resolve().parents[2]


class ProcessesRealFlagBootstrapTests(unittest.TestCase):
    @classmethod
    def setUpClass(cls):
        cls.admin = (ROOT / "src/Http/Controller/AdminController.php").read_text()
        cls.twig = (ROOT / "templates/admin/index.html.twig").read_text()
        cls.app = (ROOT / "frontend/admin/AdminApp.tsx").read_text()
        cls.view = (ROOT / "frontend/admin/OperationsProcessesPanel.tsx").read_text()
        cls.api = (ROOT / "src/Http/Controller/OperationsProcessController.php").read_text()

    def test_strict_flag_from_server_only(self):
        self.assertIn(
            "'operations_processes_real_data_enabled' => getenv('OPERATIONS_PROCESSES_REAL_DATA_ENABLED') === 'true'",
            self.admin,
        )
        self.assertIn(
            'data-operations-processes-real-data-enabled="{{ operations_processes_real_data_enabled ?',
            self.twig,
        )
        self.assertIn("dataset.operationsProcessesRealDataEnabled === 'true'", self.app)
        self.assertIn("getenv('OPERATIONS_PROCESSES_REAL_DATA_ENABLED') === 'true'", self.api)

    def test_ui_does_not_leak_into_live_without_flag_and_valid_scope(self):
        self.assertIn("processesRealDataEnabled ? [{ href: '/admin#operations-processes'", self.app)
        self.assertIn("(processesRealDataEnabled || import.meta.env.DEV)", self.app)
        self.assertIn("enabled={processesRealDataEnabled}", self.app)
        self.assertIn("context.data.active_branch.legal_entity?.id === context.data.active_legal_entity.id", self.app)
        self.assertIn("companyId={context.data.active_legal_entity?.id", self.app)
        self.assertIn("if (!enabled) return <section", self.view)
        self.assertIn("if (!opt.enabled) return { status: 'disabled' };", self.view)
        self.assertIn("if (!opt.allowed", self.view)
        self.assertIn("!opt.company", self.view)
        self.assertNotIn("fixture:", self.view)
        self.assertIn("'X-CSRF-Token'", self.view)
        self.assertIn("credentials: 'same-origin'", self.view)

    def test_server_owner_authorization_and_optimistic_updates(self):
        for guard in ("Membership::ROLE_OWNER", "requireCsrf($request)",
                      "'inventory.view'", "'inventory.create'", "'inventory.update'",
                      "'expected_version'", "'feature_unavailable'",
                      "$legalEntity?->tenant()->id() !== $tenant->id()"):
            with self.subTest(guard=guard):
                self.assertIn(guard, self.api)
        self.assertIn("next_cursor",
                      (ROOT / "src/Application/Workflow/OperationalProcessService.php").read_text())

    def test_no_private_records_bootstrapped_in_twig(self):
        for forbidden in ("record_ref", "actor_id", "company_id",
                          "data-operations-process-records", "processes_real_records"):
            self.assertNotIn(forbidden, self.twig)


if __name__ == "__main__":
    unittest.main()
