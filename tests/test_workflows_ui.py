"""AC-01 for Condor admin workflow management (#933).

Tests only synthetic source contracts. HTTP authorization and version locking
are independently covered by the existing workflow backend integration tests.
"""
from pathlib import Path
import unittest

ROOT = Path(__file__).resolve().parents[1]
PANEL = (ROOT / "frontend/admin/WorkflowsPanel.tsx").read_text(encoding="utf-8")
APP = (ROOT / "frontend/admin/AdminApp.tsx").read_text(encoding="utf-8")
CONTROLLER = (ROOT / "src/Http/Controller/WorkflowController.php").read_text(
    encoding="utf-8"
)


class UiWorkflowsTests(unittest.TestCase):
    def test_acceptance(self):
        """Lists, creates, toggles with optimistic lock, dry-runs and history."""
        for fragment in [
            "export function WorkflowsPanel",
            "permissions.includes('settings.update')",
            "workflowPath(branchId)",
            "'/action-catalog'",
            "readWorkflows(list)",
            "readCatalog(actions)",
            "kind: 'loading'",
            "kind: 'error'",
            "No hay workflows configurados",
            "No hay ejecuciones registradas",
            "workflow_id: workflowId.trim()",
            """conditions: conditions.map((item) => ({""",
            "validConditions",
            "CONDITION_FIELD.test(item.field.trim())",
            "Agregar condición",
            "Quitar condición",
            "conditions.length >= 20",
            "actions: [{ type: actionType }]",
            "active: false",
            "expected_version: item.version",
            "active: !item.active",
            "Workflow activado.",
            "Workflow desactivado.",
            "status === 409",
            "await load(); // También refresca después de un 409",
            "'/dry-run'",
            "event_type: item.trigger, event: {}",
            "result.persisted !== false",
            "persisted: false",
            "'/history?limit=50'",
            "readHistory(response)",
            "epoch !== detailRequest.current",
            "credentials: 'same-origin'",
            "'X-CSRF-Token': csrfToken ?? ''",
            "No se ejecutaron acciones ni se registró una ejecución.",
        ]:
            with self.subTest(fragment=fragment):
                self.assertIn(fragment, PANEL)

        self.assertIn("import { WorkflowsPanel } from './WorkflowsPanel';", APP)
        self.assertIn("href: '/admin#workflows'", APP)
        self.assertIn("<WorkflowsPanel", APP)
        self.assertIn("key={'workflows-' + context.data.active_branch.id}", APP)
        self.assertIn("branchId={context.data.active_branch.id}", APP)
        self.assertIn("permissions={context.data.permissions}", APP)
        self.assertIn("csrfToken={accessToken}", APP)

    def test_backend_contract_and_safe_boundaries(self):
        for fragment in [
            "name: 'api_workflows_index'",
            "name: 'api_workflows_actions'",
            "name: 'api_workflows_create'",
            "name: 'api_workflows_update'",
            "name: 'api_workflows_history'",
            "name: 'api_workflows_dry_run'",
            "$this->requireCsrf($request)",
            "authorizedBranchScope($branchId, 'settings.update')",
            "expected_version",
            "Response::HTTP_CREATED",
            "'persisted' => false",
        ]:
            self.assertIn(fragment, CONTROLLER)
        self.assertNotIn("localStorage", PANEL)
        self.assertNotIn("style={{", PANEL)
        self.assertNotIn("Math.random()", PANEL)
        self.assertNotIn("window.location.href", PANEL)
        self.assertIn("<label htmlFor=", PANEL)
        self.assertIn('aria-live="polite"', PANEL)
        self.assertIn('aria-labelledby="workflows-title"', PANEL)


if __name__ == "__main__":
    unittest.main()
