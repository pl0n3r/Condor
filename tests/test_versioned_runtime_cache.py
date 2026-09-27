#!/usr/bin/env python3
"""Aceptación ejecutable del hotfix de cache versionado #286."""

from __future__ import annotations

import subprocess
import unittest
from pathlib import Path

ROOT = Path(__file__).resolve().parents[1]
PHP_TEST = ROOT / "tests/php/KernelCacheIsolationTest.php"


class VersionedRuntimeCacheTests(unittest.TestCase):
    def phpunit(self, name: str) -> None:
        runner = ROOT / "vendor/bin/simple-phpunit"
        if not runner.exists():
            self.fail("vendor/bin/simple-phpunit no está disponible")

        result = subprocess.run(
            [str(runner), "--filter", name, str(PHP_TEST)],
            cwd=ROOT,
            text=True,
            capture_output=True,
            check=False,
        )
        self.assertEqual(0, result.returncode, result.stdout + result.stderr)

    def test_prod_cache_dir_is_versioned_by_release(self) -> None:
        self.phpunit("testDefaultProdCacheIsVersionedByRelease")

    def test_ephemeral_cache_contract_is_preserved(self) -> None:
        self.phpunit("testEphemeralCacheDoesNotReuseLiveProdContainer")

    def test_configurator_routes_are_present_in_compiled_router(self) -> None:
        for route in (
            "app_plan_configurator",
            "api_plan_configurator_catalog",
        ):
            with self.subTest(route=route):
                result = subprocess.run(
                    [
                        "php",
                        "bin/console",
                        "debug:router",
                        route,
                        "--env=prod",
                    ],
                    cwd=ROOT,
                    text=True,
                    capture_output=True,
                    check=False,
                )
                self.assertEqual(
                    0,
                    result.returncode,
                    result.stdout + result.stderr,
                )
                self.assertIn(route, result.stdout)


if __name__ == "__main__":
    unittest.main()
