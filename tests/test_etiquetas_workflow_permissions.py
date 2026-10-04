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

        self.assertEqual(workflow.count(reference), 3)
        for name in ("sync", "validar-issue", "sweep"):
            with self.subTest(job=name):
                self.assertIn("contents: read", jobs[name])
                self.assertIn("issues: write", jobs[name])
                self.assertIn(reference, jobs[name])

        pr_reference = (
            "pl0n3r/factory/.github/workflows/etiquetas-pr.yml@"
            "a2a2350b8ce686fda5aa06f49cd0e9accaa9ed98"
        )
        self.assertEqual(workflow.count(pr_reference), 1)
        self.assertIn(pr_reference, jobs["validar-pr"])

        self.assertNotIn("secrets: inherit", workflow)
        self.assertNotIn("contents: write", workflow)

    def test_pr_validation_uses_exact_factory_split_sha(self) -> None:
        workflow = self.workflow()
        jobs = job_blocks(workflow)
        split = (
            "pl0n3r/factory/.github/workflows/etiquetas-pr.yml@"
            "a2a2350b8ce686fda5aa06f49cd0e9accaa9ed98"
        )
        block = jobs["validar-pr"]
        self.assertIn(split, block)
        self.assertIn("pull-requests: write", block)
        self.assertNotIn(
            "pl0n3r/factory/.github/workflows/etiquetas.yml@v1",
            block,
        )
        self.assertEqual(workflow.count(split), 1)

    def test_non_pr_jobs_stay_on_factory_v1_read_only(self) -> None:
        workflow = self.workflow()
        jobs = job_blocks(workflow)
        general = "pl0n3r/factory/.github/workflows/etiquetas.yml@v1"
        for name in ("sync", "validar-issue", "sweep"):
            with self.subTest(job=name):
                block = jobs[name]
                self.assertIn(general, block)
                self.assertIn("pull-requests: read", block)
                self.assertNotIn("pull-requests: write", block)
        self.assertEqual(workflow.count(general), 3)


if __name__ == "__main__":
    unittest.main()
