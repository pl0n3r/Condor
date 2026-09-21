#!/usr/bin/env python3
"""Genera evidencia de throughput para el CI canónico de Condor."""

from __future__ import annotations

import argparse
import json
import statistics
import sys
from datetime import datetime
from typing import Any

WORKFLOW_NAME = "CI Condor (V 0.1.0)"
BASELINE_LIMIT = 5
MIN_BASELINE_SAMPLES = 3
RELATIVE_THRESHOLD = 1.25
ABSOLUTE_THRESHOLD_SECONDS = 15
EDGE_JOBS = {"Preflight", "Validar"}


def parse_time(value: Any) -> datetime | None:
    """Convierte un timestamp ISO de GitHub en datetime."""
    if not value:
        return None
    try:
        return datetime.fromisoformat(str(value).replace("Z", "+00:00"))
    except ValueError:
        return None


def elapsed_seconds(start: Any, end: Any) -> int | None:
    """Calcula segundos transcurridos entre dos timestamps válidos."""
    started = parse_time(start)
    completed = parse_time(end)
    if started is None or completed is None:
        return None
    return max(0, round((completed - started).total_seconds()))


def flatten_jobs(pages: Any) -> list[dict[str, Any]]:
    """Aplana la respuesta paginada del endpoint de jobs."""
    if not isinstance(pages, list):
        return []
    jobs: list[dict[str, Any]] = []
    for page in pages:
        if not isinstance(page, dict):
            continue
        values = page.get("jobs")
        if isinstance(values, list):
            jobs.extend(item for item in values if isinstance(item, dict))
    return jobs


def execution_profile(job_pages: Any) -> tuple[str, ...]:
    """Identifica la topología realmente ejecutada, excluyendo bordes fijos."""
    names = {
        str(job.get("name") or "")
        for job in flatten_jobs(job_pages)
        if job.get("conclusion") != "skipped"
        and str(job.get("name") or "") not in EDGE_JOBS
    }
    names.discard("")
    return tuple(sorted(names))


def historical_job_pages(history_jobs: Any, run_id: int) -> Any:
    """Recupera los jobs capturados para un run histórico."""
    if not isinstance(history_jobs, list):
        return None
    for item in history_jobs:
        if not isinstance(item, dict):
            continue
        if int(item.get("run_id") or 0) != run_id:
            continue
        if "pages" not in item:
            return None
        return item["pages"]
    return None


def timed_job(job: dict[str, Any]) -> dict[str, Any]:
    """Reduce un job de GitHub a los campos necesarios para telemetría."""
    return {
        "name": str(job.get("name") or ""),
        "conclusion": job.get("conclusion"),
        "started_at": job.get("started_at"),
        "completed_at": job.get("completed_at"),
        "duration_seconds": elapsed_seconds(
            job.get("started_at"),
            job.get("completed_at"),
        ),
    }


def run_wall_seconds(run: dict[str, Any]) -> int | None:
    """Obtiene wall time de una ejecución histórica de Actions."""
    return elapsed_seconds(
        run.get("run_started_at"),
        run.get("updated_at"),
    )


def comparable_run(
    run: dict[str, Any],
    current_run_id: int,
    event: str,
    current_profile: tuple[str, ...] | None = None,
    history_jobs: Any = None,
) -> dict[str, Any] | None:
    """Normaliza un run si sirve como muestra de línea base."""
    if int(run.get("id") or 0) == current_run_id:
        return None
    if run.get("name") != WORKFLOW_NAME:
        return None
    if run.get("conclusion") != "success":
        return None
    if run.get("event") != event:
        return None

    run_id = int(run["id"])
    if current_profile is not None:
        candidate_pages = historical_job_pages(history_jobs, run_id)
        if candidate_pages is None:
            return None
        candidate_profile = execution_profile(candidate_pages)
        if candidate_profile != current_profile:
            return None

    duration = run_wall_seconds(run)
    if duration is None:
        return None
    return {
        "run_id": run_id,
        "head_sha": str(run.get("head_sha") or ""),
        "duration_seconds": duration,
        "run_started_at": run.get("run_started_at"),
    }


def comparable_runs(
    history: Any,
    current_run_id: int,
    event: str,
    current_profile: tuple[str, ...] | None = None,
    history_jobs: Any = None,
) -> list[dict[str, Any]]:
    """Selecciona hasta cinco runs exitosos comparables al run observado."""
    if not isinstance(history, dict):
        return []
    values = history.get("workflow_runs")
    if not isinstance(values, list):
        return []

    candidates: list[dict[str, Any]] = []
    for run in values:
        if not isinstance(run, dict):
            continue
        candidate = comparable_run(
            run,
            current_run_id,
            event,
            current_profile,
            history_jobs,
        )
        if candidate is not None:
            candidates.append(candidate)

    candidates.sort(
        key=lambda item: str(item.get("run_started_at") or ""),
        reverse=True,
    )
    return candidates[:BASELINE_LIMIT]


def dominant_gate(jobs: list[dict[str, Any]]) -> dict[str, Any] | None:
    """Identifica el gate paralelo exitoso que más tiempo consumió."""
    eligible = [
        job
        for job in jobs
        if job.get("name") not in EDGE_JOBS
        and job.get("conclusion") == "success"
        and isinstance(job.get("duration_seconds"), int)
    ]
    return max(
        eligible,
        key=lambda item: int(item["duration_seconds"]),
        default=None,
    )


def critical_path(jobs: list[dict[str, Any]]) -> dict[str, Any]:
    """Estima Preflight → gate dominante → Validar."""
    preflight = next((job for job in jobs if job.get("name") == "Preflight"), None)
    final = next((job for job in jobs if job.get("name") == "Validar"), None)
    dominant = dominant_gate(jobs)
    ordered = [job for job in (preflight, dominant, final) if job is not None]
    durations = [
        int(job["duration_seconds"])
        for job in ordered
        if isinstance(job.get("duration_seconds"), int)
    ]
    estimate = sum(durations) if len(durations) == len(ordered) and ordered else None
    return {
        "jobs": [str(job.get("name") or "") for job in ordered],
        "estimated_seconds": estimate,
        "dominant_job": dominant.get("name") if dominant else None,
        "dominant_job_seconds": dominant.get("duration_seconds") if dominant else None,
    }


def regression_result(
    current_wall: int | None,
    baseline: list[dict[str, Any]],
    conclusion: str,
) -> dict[str, Any]:
    """Clasifica la regresión usando mediana + umbral relativo y absoluto."""
    durations = [
        int(item["duration_seconds"])
        for item in baseline
        if isinstance(item.get("duration_seconds"), int)
    ]
    median_value = (
        float(statistics.median(durations))
        if durations
        else None
    )
    result: dict[str, Any] = {
        "status": "insufficient_baseline",
        "sample_count": len(durations),
        "median_wall_seconds": median_value,
        "relative_threshold": RELATIVE_THRESHOLD,
        "absolute_threshold_seconds": ABSOLUTE_THRESHOLD_SECONDS,
        "delta_seconds": None,
        "ratio": None,
    }

    if conclusion != "success":
        result["status"] = "not_evaluated"
        return result
    if current_wall is None or len(durations) < MIN_BASELINE_SAMPLES:
        return result
    if median_value is None or median_value <= 0:
        return result

    delta = current_wall - median_value
    ratio = current_wall / median_value
    result["delta_seconds"] = round(delta, 2)
    result["ratio"] = round(ratio, 3)
    result["status"] = (
        "regression"
        if delta >= ABSOLUTE_THRESHOLD_SECONDS
        and ratio >= RELATIVE_THRESHOLD
        else "normal"
    )
    return result


def build_report(
    job_pages: Any,
    history: Any,
    metadata: dict[str, Any],
) -> dict[str, Any]:
    """Construye el reporte completo del run observado."""
    raw_jobs = flatten_jobs(job_pages)
    jobs = [timed_job(job) for job in raw_jobs]
    run_id = int(metadata["run_id"])
    event = str(metadata["event"])
    conclusion = str(metadata["conclusion"])
    wall = elapsed_seconds(metadata["started_at"], metadata["updated_at"])
    profile = execution_profile(job_pages)
    history_jobs = metadata.get("history_jobs", [])
    baseline = comparable_runs(
        history,
        run_id,
        event,
        profile,
        history_jobs,
    )
    return {
        "run_id": run_id,
        "source_sha": str(metadata["source_sha"]),
        "event": event,
        "conclusion": conclusion,
        "workflow_started_at": metadata["started_at"],
        "workflow_updated_at": metadata["updated_at"],
        "workflow_wall_seconds": wall,
        "execution_profile": list(profile),
        "critical_path": critical_path(jobs),
        "baseline": {
            "runs": baseline,
            "sample_count": len(baseline),
        },
        "regression": regression_result(wall, baseline, conclusion),
        "jobs": jobs,
    }


def display_seconds(value: Any) -> str:
    """Formatea una duración corta para el resumen humano."""
    if value is None:
        return "n/a"
    numeric = round(float(value))
    sign = "-" if numeric < 0 else ""
    total = abs(numeric)
    minutes, seconds = divmod(total, 60)
    rendered = f"{minutes}m {seconds}s" if minutes else f"{seconds}s"
    return f"{sign}{rendered}"


def status_label(status: str) -> str:
    """Traduce el estado técnico de regresión a texto ejecutivo."""
    labels = {
        "normal": "✅ Normal",
        "regression": "⚠️ Regresión detectada",
        "insufficient_baseline": "ℹ️ Línea base insuficiente",
        "not_evaluated": "ℹ️ No evaluado por resultado del CI",
    }
    return labels.get(status, status)


def markdown_summary(report: dict[str, Any]) -> str:
    """Produce un Job Summary legible en español."""
    regression = report["regression"]
    critical = report["critical_path"]
    baseline = regression.get("median_wall_seconds")
    lines = [
        "# Throughput del CI de Condor",
        "",
        f"- SHA observado: `{report['source_sha']}`",
        f"- Evento: `{report['event']}`",
        f"- Resultado del CI: **{report['conclusion']}**",
        f"- Wall time: **{display_seconds(report['workflow_wall_seconds'])}**",
        "- Perfil ejecutado: **"
        + (", ".join(report.get("execution_profile", [])) or "sin gates variables")
        + "**",
        f"- Línea base: **{display_seconds(baseline)}** "
        f"({regression['sample_count']} runs comparables)",
        f"- Estado: **{status_label(str(regression['status']))}**",
    ]

    dominant = critical.get("dominant_job")
    if dominant:
        lines.append(
            f"- Gate dominante: **{dominant}** "
            f"({display_seconds(critical.get('dominant_job_seconds'))})"
        )

    if regression.get("delta_seconds") is not None:
        lines.append(
            f"- Diferencia vs mediana: "
            f"**{display_seconds(regression['delta_seconds'])}** "
            f"(×{regression['ratio']})"
        )

    lines.extend(
        [
            "",
            "| Job | Resultado | Duración |",
            "|---|---|---:|",
        ]
    )
    for job in report["jobs"]:
        lines.append(
            f"| {job['name']} | {job['conclusion'] or 'n/a'} | "
            f"{display_seconds(job['duration_seconds'])} |"
        )
    return "\n".join(lines) + "\n"


def main() -> None:
    """Despacha generación de reporte o resumen desde JSON por stdin."""
    parser = argparse.ArgumentParser()
    sub = parser.add_subparsers(dest="command", required=True)

    report = sub.add_parser("report")
    report.add_argument("--run-id", required=True)
    report.add_argument("--source-sha", required=True)
    report.add_argument("--event", required=True)
    report.add_argument("--conclusion", required=True)
    report.add_argument("--started-at", required=True)
    report.add_argument("--updated-at", required=True)

    sub.add_parser("summary")

    args = parser.parse_args()
    payload = json.load(sys.stdin)
    if args.command == "summary":
        print(markdown_summary(payload), end="")
        return

    jobs = payload.get("jobs", []) if isinstance(payload, dict) else []
    history = payload.get("history", {}) if isinstance(payload, dict) else {}
    history_jobs = payload.get("history_jobs", []) if isinstance(payload, dict) else []
    output = build_report(
        jobs,
        history,
        {
            "run_id": args.run_id,
            "source_sha": args.source_sha,
            "event": args.event,
            "conclusion": args.conclusion,
            "started_at": args.started_at,
            "updated_at": args.updated_at,
            "history_jobs": history_jobs,
        },
    )
    print(json.dumps(output, indent=2, sort_keys=True))


if __name__ == "__main__":
    main()
