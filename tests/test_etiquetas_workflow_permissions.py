#!/usr/bin/env python3
"""Regresión de mínimo privilegio para el caller Factory Labels de Condor."""

from __future__ import annotations

import unittest
from pathlib import Path

from scripts.ci_self_audit import job_blocks


ROOT = Path(__file__).resolve().parents[1]
WORKFLOW = ROOT / ".github/workflows/sincronizar-gobierno.yml"
GENERAL = "pl0n3r/factory/.github/workflows/etiquetas.yml@v1"
PR_SPLIT = (
    "pl0n3r/factory/.github/workflows/etiquetas-pr.yml@"
    "a2a2350b8ce686fda5aa06f49cd0e9accaa9ed98"
)


class EtiquetasWorkflowPermissionsTests(unittest.TestCase):
    def workflow(self) -> str:
        return WORKFLOW.read_text(encoding="utf-8")

    def test_pr_validation_uses_exact_factory_split_sha(self) -> None:
        workflow = self.workflow()
        jobs = job_blocks(workflow)

        self.assertIn(PR_SPLIT, jobs["validar-pr"])
        self.assertNotIn(GENERAL, jobs["validar-pr"])
        self.assertEqual(workflow.count(PR_SPLIT), 1)
        self.assertNotIn("@main", workflow)

        self.assertIn("contents: read", jobs["validar-pr"])
        self.assertIn("issues: write", jobs["validar-pr"])
        self.assertIn("pull-requests: write", jobs["validar-pr"])

    def test_non_pr_jobs_stay_on_factory_v1_read_only(self) -> None:
        workflow = self.workflow()
        jobs = job_blocks(workflow)

        self.assertEqual(workflow.count(GENERAL), 3)
        for name in ("sync", "validar-issue", "sweep"):
            with self.subTest(job=name):
                self.assertIn(GENERAL, jobs[name])
                self.assertIn("contents: read", jobs[name])
                self.assertIn("issues: write", jobs[name])
                self.assertIn("pull-requests: read", jobs[name])
                self.assertNotIn("pull-requests: write", jobs[name])

        self.assertNotIn("secrets: inherit", workflow)
        self.assertNotIn("contents: write", workflow)


if __name__ == "__main__":
    unittest.main()
