"""Contrato del horizonte D-043 usado por observers."""

from __future__ import annotations

import unittest
from pathlib import Path

ROOT = Path(__file__).resolve().parents[1]
AUTO = ROOT / ".github" / "workflows" / "observar-deploy-automatico.yml"
MANUAL = ROOT / ".github" / "workflows" / "observar-release.yml"


class D043ObserverWorkflowContractTests(unittest.TestCase):
    def workflow(self, path: Path) -> str:
        return path.read_text(encoding="utf-8")

    def test_automatic_observer_scopes_pending_reducer_to_0_1_122_baseline(self) -> None:
        workflow = self.workflow(AUTO)
        self.assertIn("scripts/d043_pending_releases.py", workflow)
        self.assertIn("--minimum-version 0.1.122", workflow)


    def test_manual_observer_scopes_pending_reducer_to_0_1_122_baseline(self) -> None:
        workflow = self.workflow(MANUAL)
        self.assertIn("scripts/d043_pending_releases.py", workflow)
        self.assertIn("--minimum-version 0.1.122", workflow)


if __name__ == "__main__":
    unittest.main()
