#!/usr/bin/env python3
"""Aceptación ejecutable de activación Commercial Catalog #275."""

from __future__ import annotations

import subprocess
import unittest
from pathlib import Path

ROOT = Path(__file__).resolve().parents[1]
PHP_TEST = ROOT / "tests/php/Application/Commercial/CommercialCatalogActivationTest.php"
POST_DEPLOY_TEST = ROOT / "tests/test_post_deploy.py"


class CommercialCatalogActivationTests(unittest.TestCase):
    def phpunit(self, pattern: str) -> None:
        runner = ROOT / "vendor/bin/simple-phpunit"
        if not runner.exists():
            self.fail("vendor/bin/simple-phpunit no está disponible")
        result = subprocess.run(
            [str(runner), "--filter", pattern, str(PHP_TEST)],
            cwd=ROOT,
            text=True,
            capture_output=True,
            check=False,
        )
        self.assertEqual(0, result.returncode, result.stdout + result.stderr)

    def post_deploy_test(self, method: str) -> None:
        result = subprocess.run(
            [
                "python3",
                str(POST_DEPLOY_TEST),
                f"PostDeployStageTest.{method}",
            ],
            cwd=ROOT,
            text=True,
            capture_output=True,
            check=False,
        )
        self.assertEqual(0, result.returncode, result.stdout + result.stderr)

    def test_seed_command_is_idempotent(self) -> None:
        self.phpunit("testSeedCommandIsIdempotent")

    def test_seed_command_rolls_back_on_failure(self) -> None:
        self.phpunit("testSeedTransactionRollsBackOnFailure")

    def test_post_deploy_runs_seed_after_schema_reconciliation(self) -> None:
        self.post_deploy_test(
            "test_construction_seeds_catalog_after_schema_before_cache"
        )
        self.post_deploy_test(
            "test_catalog_seed_failure_blocks_cache_and_success"
        )

    def test_successful_activation_exposes_four_plans(self) -> None:
        self.phpunit("testSuccessfulActivationExposesFourPlans")


if __name__ == "__main__":
    unittest.main()
