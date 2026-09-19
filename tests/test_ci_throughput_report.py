#!/usr/bin/env python3
"""Pruebas deterministas de telemetría de throughput."""

from __future__ import annotations

import unittest
from datetime import datetime, timedelta, timezone

from scripts.ci_throughput_report import (
    build_report,
    comparable_runs,
    dominant_gate,
    elapsed_seconds,
    markdown_summary,
    regression_result,
)


def run(
    run_id: int,
    seconds: int,
    event: str = "pull_request",
    conclusion: str = "success",
) -> dict:
    """Crea un run histórico mínimo con duración controlada."""
    started = datetime(
        2026,
        9,
        19,
        10,
        0,
        0,
        tzinfo=timezone.utc,
    ) + timedelta(seconds=run_id)
    completed = started + timedelta(seconds=seconds)
    return {
        "id": run_id,
        "name": "CI Condor (V 0.1.0)",
        "event": event,
        "conclusion": conclusion,
        "head_sha": f"sha-{run_id}",
        "run_started_at": started.isoformat().replace("+00:00", "Z"),
        "updated_at": completed.isoformat().replace("+00:00", "Z"),
    }


class ThroughputTests(unittest.TestCase):
    """Cubre cálculo de tiempos, baseline y alertas."""

    def test_elapsed_seconds(self) -> None:
        """Calcula wall time válido."""
        self.assertEqual(
            elapsed_seconds(
                "2026-09-19T10:00:00Z",
                "2026-09-19T10:00:20Z",
            ),
            20,
        )

    def test_history_filters_same_event_and_success(self) -> None:
        """La línea base usa solo runs exitosos del mismo tipo."""
        history = {
            "workflow_runs": [
                run(1, 20),
                run(2, 21, event="push"),
                run(3, 22, conclusion="failure"),
                run(4, 23),
            ]
        }
        selected = comparable_runs(history, 99, "pull_request")
        self.assertEqual([item["run_id"] for item in selected], [4, 1])

    def test_history_is_limited_to_five(self) -> None:
        """La línea base no crece sin límite."""
        history = {"workflow_runs": [run(i, 20 + i) for i in range(1, 9)]}
        selected = comparable_runs(history, 99, "pull_request")
        self.assertEqual(len(selected), 5)

    def test_regression_requires_relative_and_absolute_threshold(self) -> None:
        """Evita alertas si solo uno de los dos umbrales se supera."""
        baseline = [{"duration_seconds": value} for value in [20, 20, 20]]
        self.assertEqual(
            regression_result(30, baseline, "success")["status"],
            "normal",
        )
        self.assertEqual(
            regression_result(36, baseline, "success")["status"],
            "regression",
        )

    def test_regression_requires_three_samples(self) -> None:
        """No clasifica con una línea base demasiado pequeña."""
        baseline = [{"duration_seconds": 20}, {"duration_seconds": 21}]
        self.assertEqual(
            regression_result(60, baseline, "success")["status"],
            "insufficient_baseline",
        )

    def test_failed_run_is_not_evaluated(self) -> None:
        """Un CI fallido no se confunde con regresión de velocidad."""
        baseline = [{"duration_seconds": 20} for _ in range(3)]
        self.assertEqual(
            regression_result(60, baseline, "failure")["status"],
            "not_evaluated",
        )

    def test_dominant_gate_excludes_preflight_and_final(self) -> None:
        """Identifica el gate paralelo más lento."""
        jobs = [
            {"name": "Preflight", "conclusion": "success", "duration_seconds": 5},
            {"name": "Validar gobierno", "conclusion": "success", "duration_seconds": 11},
            {"name": "Validar coordinación", "conclusion": "success", "duration_seconds": 8},
            {"name": "Validar", "conclusion": "success", "duration_seconds": 3},
        ]
        self.assertEqual(dominant_gate(jobs)["name"], "Validar gobierno")

    def test_report_and_summary(self) -> None:
        """Construye evidencia y un resumen humano coherente."""
        jobs = [
            {
                "jobs": [
                    {
                        "name": "Preflight",
                        "conclusion": "success",
                        "started_at": "2026-09-19T10:00:00Z",
                        "completed_at": "2026-09-19T10:00:05Z",
                    },
                    {
                        "name": "Validar gobierno",
                        "conclusion": "success",
                        "started_at": "2026-09-19T10:00:05Z",
                        "completed_at": "2026-09-19T10:00:15Z",
                    },
                    {
                        "name": "Validar",
                        "conclusion": "success",
                        "started_at": "2026-09-19T10:00:15Z",
                        "completed_at": "2026-09-19T10:00:20Z",
                    },
                ]
            }
        ]
        history = {"workflow_runs": [run(1, 20), run(2, 21), run(3, 22)]}
        report = build_report(
            jobs,
            history,
            {
                "run_id": 99,
                "source_sha": "abc123",
                "event": "pull_request",
                "conclusion": "success",
                "started_at": "2026-09-19T10:00:00Z",
                "updated_at": "2026-09-19T10:00:20Z",
            },
        )
        self.assertEqual(report["workflow_wall_seconds"], 20)
        self.assertEqual(report["critical_path"]["dominant_job"], "Validar gobierno")
        self.assertEqual(report["regression"]["status"], "normal")
        summary = markdown_summary(report)
        self.assertIn("Throughput del CI de Condor", summary)
        self.assertIn("Validar gobierno", summary)


if __name__ == "__main__":
    unittest.main()
