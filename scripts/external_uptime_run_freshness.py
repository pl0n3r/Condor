#!/usr/bin/env python3
"""Pure, offline projection of Condor's external-monitor run freshness.

This function never probes production or queries GitHub. An independent,
authorized observer must supply a trusted snapshot and its observation time.
"""

from __future__ import annotations

import re
from datetime import datetime, timezone
from typing import Any

WORKFLOW_PATH = ".github/workflows/external-uptime.yml"
WORKFLOW_REPOSITORY = "pl0n3r/Condor"
# GitHub Actions workflow identity verified on scheduled run #38095633954.
WORKFLOW_ID = 376208478
SHA_RE = re.compile(r"[0-9a-f]{40}\Z")
CONCLUSIONS = {"success", "failure", "cancelled", "timed_out", "skipped", "action_required", "neutral"}
EVIDENCED_CONCLUSIONS = {"success", "failure"}
MAX_RUNS = 100
MAX_AGE_LIMIT = 86400


def _utc(value: object) -> datetime | None:
    if not isinstance(value, str) or not 1 <= len(value) <= 40:
        return None
    try:
        parsed = datetime.fromisoformat(value.replace("Z", "+00:00"))
    except ValueError:
        return None
    if parsed.tzinfo is None or parsed.utcoffset() is None:
        return None
    return parsed.astimezone(timezone.utc)


def _stamp(value: datetime) -> str:
    return value.isoformat(timespec="microseconds" if value.microsecond else "seconds").replace("+00:00", "Z")


def _unknown(reason: str) -> dict[str, Any]:
    """Fail closed without echoing any untrusted metadata."""
    return {
        "monitor_freshness": "UNKNOWN", "product_health": "UNKNOWN",
        "reason": reason, "workflow_path": WORKFLOW_PATH,
        "run_id": None, "run_started_at": None, "age_seconds": None,
        "head_sha": None, "run_conclusion": None,
    }


def project_run_freshness(
    runs: object, *, now: datetime, max_age_seconds: int = 1800,
    snapshot_complete: bool = False, snapshot_total_count: int | None = None
) -> dict[str, Any]:
    """Classify last verified monitor execution, NOT site health.

    Input is a bounded list of GitHub Actions run metadata, not an HTTP result.
    Any inconsistent record invalidates the snapshot rather than being ignored.
    """
    if (not isinstance(now, datetime) or now.tzinfo is None
            or now.utcoffset() is None or not isinstance(max_age_seconds, int)
            or isinstance(max_age_seconds, bool)
            or not 1 <= max_age_seconds <= MAX_AGE_LIMIT):
        return _unknown("invalid_parameters")
    # El llamador autorizado certifica paginación completa. Una lista plana
    # arbitraria (o el primer page de una API) nunca demuestra ausencia de runs.
    if (snapshot_complete is not True or type(snapshot_total_count) is not int
            or not isinstance(runs, list) or not 1 <= len(runs) <= MAX_RUNS
            or snapshot_total_count != len(runs)):
        return _unknown("missing_or_incomplete_runs")

    instant = now.astimezone(timezone.utc)
    seen: dict[int, tuple[object, ...]] = {}
    observations: list[tuple[datetime, int, str, str]] = []
    for raw in runs:
        if not isinstance(raw, dict):
            return _unknown("invalid_run_metadata")
        run_id = raw.get("id")
        started = _utc(raw.get("run_started_at"))
        sha = raw.get("head_sha")
        conclusion = raw.get("conclusion")
        status = raw.get("status")
        if (type(run_id) is not int or run_id <= 0 or raw.get("path") != WORKFLOW_PATH
                or raw.get("event") != "schedule" or raw.get("head_branch") != "main"
                or type(raw.get("workflow_id")) is not int or raw["workflow_id"] != WORKFLOW_ID
                or not isinstance(raw.get("repository"), dict)
                or raw["repository"].get("full_name") != WORKFLOW_REPOSITORY
                or not isinstance(raw.get("head_repository"), dict)
                or raw["head_repository"].get("full_name") != WORKFLOW_REPOSITORY
                or started is None or not isinstance(sha, str)
                or SHA_RE.fullmatch(sha) is None
                or not isinstance(status, str)
                or status not in {"completed", "in_progress", "queued", "waiting"}
                or (status == "completed" and (not isinstance(conclusion, str)
                                               or conclusion not in CONCLUSIONS))
                or (status != "completed" and conclusion is not None)):
            return _unknown("invalid_run_metadata")
        fingerprint = (_stamp(started), sha, status, conclusion,
                       raw["path"], raw["event"], raw["head_branch"])
        if run_id in seen:
            # Las páginas completas contienen runs únicos: repetir una fila
            # puede ocultar otra ejecución más reciente y alterar el veredicto.
            return _unknown("duplicate_run_identity")
        seen[run_id] = fingerprint
        observations.append((started, run_id, sha, status if status != "completed" else str(conclusion)))

    latest_started, latest_id, latest_sha, latest_outcome = max(
        observations, key=lambda item: (item[0], item[1])
    )
    age = (instant - latest_started).total_seconds()
    if age < 0:
        return _unknown("future_run")
    if latest_outcome in {"in_progress", "queued", "waiting"}:
        return _unknown("latest_run_not_terminal")
    # Un run omitido, cancelado o sin probes acreditados no aporta frescura.
    if latest_outcome not in EVIDENCED_CONCLUSIONS:
        return _unknown("latest_run_without_probe_evidence")
    age_seconds = max(0, int(age))
    freshness = "FRESH" if age <= max_age_seconds else "STALE"
    return {
        "monitor_freshness": freshness, "product_health": "UNKNOWN",
        "reason": "recent_monitor_run" if freshness == "FRESH" else "monitor_run_stale",
        "workflow_path": WORKFLOW_PATH, "run_id": latest_id,
        "run_started_at": _stamp(latest_started), "age_seconds": age_seconds,
        "head_sha": latest_sha, "run_conclusion": latest_outcome,
    }
