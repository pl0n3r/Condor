#!/usr/bin/env python3
"""Contratos de reducción de amplificación sobre GitHub."""

from __future__ import annotations

import unittest
from pathlib import Path


ROOT = Path(__file__).resolve().parents[2]


class GitHubLoadPolicyContractTests(unittest.TestCase):
    """Protege la topología de workflows que reduce eventos derivados."""

    def test_telemetry_is_hourly_not_per_ci_run(self) -> None:
        workflow = (
            ROOT / ".github/workflows/ci-throughput-telemetry.yml"
        ).read_text(encoding="utf-8")

        self.assertIn("schedule:", workflow)
        self.assertIn("cron: '23 * * * *'", workflow)
        self.assertNotIn("workflow_run:", workflow)
        self.assertIn("cancel-in-progress: true", workflow)

    def test_sonar_relay_is_manual_only(self) -> None:
        workflow = (
            ROOT / ".github/workflows/sonar-annotation-relay.yml"
        ).read_text(encoding="utf-8")

        self.assertIn("workflow_dispatch:", workflow)
        self.assertNotIn("\n  check_run:", workflow)
        self.assertIn("cancel-in-progress: true", workflow)

    def test_release_observers_cancel_obsolete_runs(self) -> None:
        automatic = (
            ROOT / ".github/workflows/observar-deploy-automatico.yml"
        ).read_text(encoding="utf-8")
        manual = (
            ROOT / ".github/workflows/observar-release.yml"
        ).read_text(encoding="utf-8")

        self.assertIn("group: observar-deploy-", automatic)
        self.assertIn("cancel-in-progress: true", automatic)
        self.assertIn("group: observar-release-", manual)
        self.assertIn("cancel-in-progress: true", manual)


if __name__ == "__main__":
    unittest.main()
