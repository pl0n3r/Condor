#!/usr/bin/env python3
"""Contratos públicos de la infraestructura de pruebas de Condor."""

from __future__ import annotations

import json
import subprocess
import unittest
from pathlib import Path


ROOT = Path(__file__).resolve().parents[2]


class ToolingContractTests(unittest.TestCase):
    """Protege interfaces estables usadas por personas, agentes y CI."""

    def test_package_exposes_canonical_test_commands(self) -> None:
        """Los comandos públicos de pruebas permanecen disponibles."""
        package = json.loads((ROOT / "package.json").read_text(encoding="utf-8"))
        scripts = package["scripts"]
        self.assertIn("test:contract", scripts)
        self.assertIn("test:integration", scripts)
        self.assertIn("test:e2e", scripts)
        self.assertIn("test:e2e:webkit", scripts)

    def test_playwright_dependency_is_exact_and_locked(self) -> None:
        """Playwright usa la misma versión exacta en manifest y lockfile."""
        package = json.loads((ROOT / "package.json").read_text(encoding="utf-8"))
        lock = json.loads((ROOT / "package-lock.json").read_text(encoding="utf-8"))
        requested = package["devDependencies"]["@playwright/test"]
        locked = lock["packages"]["node_modules/@playwright/test"]["version"]
        self.assertRegex(requested, r"^\d+\.\d+\.\d+$")
        self.assertEqual(requested, locked)

    def test_release_workflow_requires_canonical_semver_and_recovers_partial_release(self) -> None:
        """El release automático rechaza ceros iniciales y completa estados parciales."""
        workflow = (
            ROOT / ".github/workflows/tag-release.yml"
        ).read_text(encoding="utf-8")

        self.assertIn(
            r"^(0|[1-9][0-9]*)\.(0|[1-9][0-9]*)\.(0|[1-9][0-9]*)$",
            workflow,
        )
        self.assertIn('git show-ref --verify --quiet "refs/tags/$TAG"', workflow)
        self.assertIn('gh release view "$TAG"', workflow)
        self.assertIn('gh release create "$TAG"', workflow)
        self.assertIn('--title "Release $TAG (V ${TAG#v})"', workflow)
        self.assertNotIn("if: steps.tag.outputs.created == 'true'", workflow)

    def test_throughput_cli_preserves_json_report_contract(self) -> None:
        """El CLI de throughput entrega el esquema consumido por automatizaciones."""
        payload = {
            "jobs": [
                {
                    "jobs": [
                        {
                            "name": "Preflight",
                            "conclusion": "success",
                            "started_at": "2026-09-19T10:00:00Z",
                            "completed_at": "2026-09-19T10:00:02Z",
                        },
                        {
                            "name": "Validar",
                            "conclusion": "success",
                            "started_at": "2026-09-19T10:00:02Z",
                            "completed_at": "2026-09-19T10:00:04Z",
                        },
                    ]
                }
            ],
            "history": {"workflow_runs": []},
        }
        command = [
            "python3",
            "scripts/ci_throughput_report.py",
            "report",
            "--run-id",
            "42",
            "--source-sha",
            "abc123",
            "--event",
            "push",
            "--conclusion",
            "success",
            "--started-at",
            "2026-09-19T10:00:00Z",
            "--updated-at",
            "2026-09-19T10:00:04Z",
        ]
        result = subprocess.run(
            command,
            cwd=ROOT,
            input=json.dumps(payload),
            text=True,
            capture_output=True,
            check=False,
        )
        self.assertEqual(result.returncode, 0, result.stderr)
        report = json.loads(result.stdout)
        self.assertEqual(report["run_id"], 42)
        self.assertEqual(report["source_sha"], "abc123")
        self.assertEqual(report["workflow_wall_seconds"], 4)
        self.assertIn("critical_path", report)
        self.assertIn("regression", report)
        self.assertIsInstance(report["jobs"], list)


if __name__ == "__main__":
    unittest.main()
