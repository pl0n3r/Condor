#!/usr/bin/env python3
"""Contratos para reducir fan-out GitHub sin debilitar los gates de Condor."""

from __future__ import annotations

import unittest
from pathlib import Path

ROOT = Path(__file__).resolve().parents[1]
WORKFLOWS = ROOT / ".github" / "workflows"


def read_workflow(name: str) -> str:
    return (WORKFLOWS / name).read_text(encoding="utf-8")


class GitHubLoadPolicyContractTests(unittest.TestCase):
    """Señales que fallan cuando regresan eventos o permisos innecesarios."""

    def test_telemetry_is_hourly_not_per_ci_run(self) -> None:
        workflow = read_workflow("ci-throughput-telemetry.yml")
        trigger = workflow.split("\npermissions:", 1)[0]
        self.assertIn("schedule:", trigger)
        self.assertIn("cron: '37 * * * *'", trigger)
        self.assertIn("workflow_dispatch:", trigger)
        self.assertNotIn("workflow_run:", trigger)
        self.assertIn("actions: write", workflow)
        self.assertIn("contents: read", workflow)
        self.assertIn("actions/workflows/ci.yml/runs?per_page=30&status=success&event=$SOURCE_EVENT", workflow)
        self.assertIn("status == \"completed\"", workflow)
        self.assertIn("now_epoch - updated_epoch > 86400", workflow)
        self.assertIn("run_attempt", workflow)
        self.assertIn("actions/artifacts?name=$artifact_name&per_page=100", workflow)
        self.assertIn("actions/runs/$RUN_ID/artifacts?per_page=100", workflow)
        self.assertIn("actions/artifacts/$artifact_id", workflow)
        self.assertIn(".name == $name and .expired == false", workflow)
        self.assertIn("steps.source.outputs.should_report == 'true'", workflow)
        self.assertIn("ci-throughput-${{ steps.source.outputs.run_id }}-attempt-${{ steps.source.outputs.run_attempt }}", workflow)

    def test_sonar_relay_is_manual_only(self) -> None:
        workflow = read_workflow("sonar-annotation-relay.yml")
        trigger = workflow.split("\npermissions:", 1)[0]
        self.assertIn("workflow_dispatch:", trigger)
        self.assertNotIn("check_run:", trigger)
        self.assertIn("check_run_id:", trigger)
        self.assertIn("pr_number:", trigger)
        self.assertIn("group: sonar-relay-${{ github.repository }}-${{ inputs.pr_number }}", workflow)
        self.assertIn("cancel-in-progress: true", workflow)
        self.assertIn("checks: read", workflow)
        self.assertIn("pull-requests: write", workflow)

    def test_coordination_only_reacts_to_created_comments(self) -> None:
        workflow = read_workflow("coordinacion-trabajo.yml")
        self.assertIn("issue_comment:\n    types: [created]", workflow)
        self.assertNotIn("types: [created, edited]", workflow)
        self.assertIn("github.event.comment.body == '/tomar'", workflow)
        self.assertIn(".factory/scripts/coordinar_trabajo.py", workflow)
        self.assertIn("/adoptar-contrato-huerfana", workflow)
        self.assertIn("/renovar-contrato ", workflow)

    def test_release_observers_cancel_obsolete_runs(self) -> None:
        automatic = read_workflow("observar-deploy-automatico.yml")
        manual = read_workflow("observar-release.yml")
        self.assertIn("group: observer-deploy-${{ github.repository }}-main", automatic)
        self.assertIn("group: observer-release-${{ github.repository }}", manual)
        for workflow in (automatic, manual):
            self.assertIn("cancel-in-progress: true", workflow)
            self.assertIn("contents: read", workflow)
            self.assertIn("timeout-minutes:", workflow)

    def test_agents_document_low_fanout_rules(self) -> None:
        manual = (ROOT / "AGENTES.md").read_text(encoding="utf-8")
        for term in (
            "polling de checks", "Agrupar los pushes",
            "2–3 agentes simultáneos entre repos distintos", "uno por repo",
            "comentarios de progreso",
            "telemetría CI se muestrea una vez por hora",
        ):
            with self.subTest(term=term):
                self.assertIn(term, manual)

    def test_factory_sweep_keeps_minimum_permissions(self) -> None:
        workflow = read_workflow("coordinacion-trabajo.yml")
        self.assertIn("cron: '17 * * * *'", workflow)
        self.assertIn("permissions:\n  contents: read", workflow)
        sweep = workflow.split("\n  sweep:", 1)[1]
        self.assertIn("github.event_name == 'schedule'", sweep)
        self.assertIn("contents: write\n      issues: write", sweep)
        self.assertNotIn("pull-requests: write", sweep)
        self.assertIn(".factory/scripts/coordinar_trabajo.py marcar-inactivas", sweep)

    def test_release_candidate_version_and_snapshot(self) -> None:
        version = (ROOT / "config/version.php").read_text(encoding="utf-8")
        readme = (ROOT / "README.md").read_text(encoding="utf-8")
        self.assertIn("'version' => '0.1.52'", version)
        self.assertIn("V 0.1.52", readme)
        self.assertIn("Issue #247", readme)
        self.assertIn("Dependabot policy V 0.1.52", readme)


if __name__ == "__main__":
    unittest.main()
