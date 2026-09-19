#!/usr/bin/env python3
"""Pruebas de integración de las herramientas base del repositorio."""

from __future__ import annotations

import json
import subprocess
import unittest
from pathlib import Path


ROOT = Path(__file__).resolve().parents[2]


class ToolingIntegrationTests(unittest.TestCase):
    """Ejercita CLIs reales a través de sus fronteras de proceso."""

    def test_documentation_validator_accepts_current_repository(self) -> None:
        """La documentación canónica completa pasa su validador real."""
        result = subprocess.run(
            ["python3", "scripts/validar_documentacion.py"],
            cwd=ROOT,
            text=True,
            capture_output=True,
            check=False,
        )
        self.assertEqual(result.returncode, 0, result.stderr)
        self.assertIn("Documentación validada correctamente.", result.stdout)

    def test_throughput_report_round_trip_to_human_summary(self) -> None:
        """Reporte JSON y resumen humano interoperan de extremo a extremo."""
        payload = {
            "jobs": [
                {
                    "jobs": [
                        {
                            "name": "Preflight",
                            "conclusion": "success",
                            "started_at": "2026-09-19T10:00:00Z",
                            "completed_at": "2026-09-19T10:00:03Z",
                        },
                        {
                            "name": "Validar coordinación",
                            "conclusion": "success",
                            "started_at": "2026-09-19T10:00:03Z",
                            "completed_at": "2026-09-19T10:00:08Z",
                        },
                        {
                            "name": "Validar",
                            "conclusion": "success",
                            "started_at": "2026-09-19T10:00:08Z",
                            "completed_at": "2026-09-19T10:00:10Z",
                        },
                    ]
                }
            ],
            "history": {"workflow_runs": []},
        }
        report = subprocess.run(
            [
                "python3",
                "scripts/ci_throughput_report.py",
                "report",
                "--run-id",
                "99",
                "--source-sha",
                "def456",
                "--event",
                "push",
                "--conclusion",
                "success",
                "--started-at",
                "2026-09-19T10:00:00Z",
                "--updated-at",
                "2026-09-19T10:00:10Z",
            ],
            cwd=ROOT,
            input=json.dumps(payload),
            text=True,
            capture_output=True,
            check=False,
        )
        self.assertEqual(report.returncode, 0, report.stderr)

        summary = subprocess.run(
            ["python3", "scripts/ci_throughput_report.py", "summary"],
            cwd=ROOT,
            input=report.stdout,
            text=True,
            capture_output=True,
            check=False,
        )
        self.assertEqual(summary.returncode, 0, summary.stderr)
        self.assertIn("Throughput del CI de Condor", summary.stdout)
        self.assertIn("Validar coordinación", summary.stdout)
        self.assertIn("10s", summary.stdout)


if __name__ == "__main__":
    unittest.main()
