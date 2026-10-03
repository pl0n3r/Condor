"""Contrato del reconciliador D-043 post-validación."""

from __future__ import annotations

import unittest
from pathlib import Path

WORKFLOW = Path(__file__).resolve().parents[1] / ".github" / "workflows" / "observar-release.yml"


class D043ReconciliationWorkflowContractTests(unittest.TestCase):
    def workflow(self) -> str:
        return WORKFLOW.read_text(encoding="utf-8")

    def test_manual_observer_reconciles_only_after_successful_finalize_with_issue_write_permission(self) -> None:
        workflow = self.workflow()
        self.assertIn("issues: write", workflow)
        self.assertIn("scripts/d043_reconcile_issue.py", workflow)
        self.assertIn("Reconciliar una sola hoja D-043", workflow)
        self.assertLess(
            workflow.index("Consolidar evidencia y checklist de transición"),
            workflow.index("Reconciliar una sola hoja D-043"),
        )
        self.assertIn("set -euo pipefail", workflow)

    def test_workflow_refetches_selected_issue_and_never_mutates_more_than_one_candidate(self) -> None:
        workflow = self.workflow()
        self.assertIn("issues/${issue_number}", workflow)
        self.assertIn("state=all", workflow)
        self.assertIn("action.issue", workflow)
        self.assertNotIn("for issue_number in", workflow)
        for label in (
            "estado: reservado", "estado: en revisión", "estado: completado",
            "estado: bloqueado", "estado: disponible",
        ):
            self.assertIn(label, workflow)


if __name__ == "__main__":
    unittest.main()
