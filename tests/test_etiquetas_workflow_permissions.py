#!/usr/bin/env python3
"""Regresión de mínimo privilegio para el caller Factory Labels de Condor."""

from __future__ import annotations

import unittest
from pathlib import Path

from scripts.ci_self_audit import job_blocks


ROOT = Path(__file__).resolve().parents[1]
WORKFLOW = ROOT / ".github/workflows/sincronizar-gobierno.yml"


class EtiquetasWorkflowPermissionsTests(unittest.TestCase):
    def workflow(self) -> str:
        return WORKFLOW.read_text(encoding="utf-8")

    def test_only_pr_validation_requests_pull_requests_write(self) -> None:
        jobs = job_blocks(self.workflow())

        self.assertIn("pull-requests: write", jobs["validar-pr"])
        for name in ("sync", "validar-issue", "sweep"):
            with self.subTest(job=name):
                self.assertIn("pull-requests: read", jobs[name])
                self.assertNotIn("pull-requests: write", jobs[name])

    def test_non_pr_jobs_remain_read_only_and_factory_v1_is_preserved(self) -> None:
        workflow = self.workflow()
        jobs = job_blocks(workflow)
        reference = "pl0n3r/factory/.github/workflows/etiquetas.yml@v1"

        self.assertEqual(workflow.count(reference), 4)
        for name in ("sync", "validar-issue", "validar-pr", "sweep"):
            with self.subTest(job=name):
                self.assertIn("contents: read", jobs[name])
                self.assertIn("issues: write", jobs[name])
                self.assertIn(reference, jobs[name])

        self.assertNotIn("secrets: inherit", workflow)
        self.assertNotIn("contents: write", workflow)


if __name__ == "__main__":
    unittest.main()
