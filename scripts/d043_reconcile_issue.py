#!/usr/bin/env python3
"""Selecciona como máximo una hoja D-043 elegible para reconciliación."""

from __future__ import annotations

import json
import re
import sys
from pathlib import Path
from typing import Any

SCRIPT_DIR = Path(__file__).resolve().parent
if str(SCRIPT_DIR) not in sys.path:
    sys.path.insert(0, str(SCRIPT_DIR))

from release_evidence import validate_manifest, validated_release_identity

GATE_PATTERN = re.compile(r"<!-- condor-d043-gate (?P<payload>\{[^\n]+\}) -->")
PLAN_PATTERN = re.compile(r"<!-- factory-plan-task (?P<payload>\{[^\n]+\}) -->")
PROTECTED_LABELS = {"estado: reservado", "estado: en revisión", "estado: completado"}
BLOCKED_LABEL = "estado: bloqueado"


class ReconcileError(ValueError):
    """Snapshot inválido o ambiguo: la reconciliación debe fallar cerrado."""


def labels_of(issue: dict[str, Any]) -> set[str]:
    labels = issue.get("labels")
    if not isinstance(labels, list):
        raise ReconcileError("Issue sin lista de labels válida.")
    result: set[str] = set()
    for item in labels:
        if isinstance(item, str):
            result.add(item)
        elif isinstance(item, dict) and isinstance(item.get("name"), str):
            result.add(item["name"])
        else:
            raise ReconcileError("Issue contiene una label inválida.")
    return result


def parse_single_marker(body: str, pattern: re.Pattern[str], name: str) -> dict[str, Any] | None:
    matches = list(pattern.finditer(body))
    if not matches:
        return None
    if len(matches) != 1:
        raise ReconcileError(f"{name} debe aparecer como máximo una vez.")
    try:
        payload = json.loads(matches[0].group("payload"))
    except ValueError as error:
        raise ReconcileError(f"{name} contiene JSON inválido.") from error
    if not isinstance(payload, dict):
        raise ReconcileError(f"{name} debe contener un objeto JSON.")
    return payload


def covered_identities(manifest: dict[str, Any]) -> set[tuple[str, str]]:
    validate_manifest(manifest)
    covered = {validated_release_identity(manifest)}
    for item in manifest.get("covered_releases", []):
        covered.add(validated_release_identity(item))
    return covered


def issue_index(issues: object) -> dict[int, dict[str, Any]]:
    if not isinstance(issues, list):
        raise ReconcileError("issues debe ser una lista JSON.")
    flattened: list[dict[str, Any]] = []
    for item in issues:
        if isinstance(item, dict):
            flattened.append(item)
        elif isinstance(item, list):
            if not all(isinstance(entry, dict) for entry in item):
                raise ReconcileError("Una página de Issues contiene entradas inválidas.")
            flattened.extend(item)
        else:
            raise ReconcileError("Snapshot de Issues contiene una entrada inválida.")
    result: dict[int, dict[str, Any]] = {}
    for issue in flattened:
        if "pull_request" in issue:
            continue
        number = issue.get("number")
        if not isinstance(number, int) or number <= 0:
            raise ReconcileError("Issue sin número válido.")
        if number in result:
            raise ReconcileError("Snapshot contiene un Issue duplicado.")
        result[number] = issue
    return result


def gate_identity(gate: dict[str, Any]) -> tuple[str, str]:
    if gate.get("version") != 1:
        raise ReconcileError("condor-d043-gate usa una versión no soportada.")
    release = gate.get("release")
    sha = gate.get("sha")
    if not isinstance(release, str) or not isinstance(sha, str):
        raise ReconcileError("condor-d043-gate no contiene identidad exacta.")
    return validated_release_identity({"version": release, "sha": sha})


def plan_contract(plan: dict[str, Any]) -> tuple[int, list[int]]:
    if plan.get("version") != 1:
        raise ReconcileError("factory-plan-task usa una versión no soportada.")
    order = plan.get("order")
    depends_on = plan.get("depends_on")
    if not isinstance(order, int) or order <= 0:
        raise ReconcileError("factory-plan-task.order debe ser entero positivo.")
    if (
        not isinstance(depends_on, list)
        or not all(isinstance(item, int) and item > 0 for item in depends_on)
        or len(set(depends_on)) != len(depends_on)
    ):
        raise ReconcileError("factory-plan-task.depends_on es inválido.")
    return order, depends_on


def reconciliation_marker(
    issue_number: int,
    gate: tuple[str, str],
    validated: tuple[str, str],
) -> str:
    payload = {
        "gate_release": gate[0],
        "gate_sha": gate[1],
        "issue": issue_number,
        "validated_sha": validated[1],
        "validated_version": validated[0],
        "version": 1,
    }
    return "<!-- condor-d043-reconciled " + json.dumps(
        payload, sort_keys=True, separators=(",", ":")
    ) + " -->"


def select_action(manifest: dict[str, Any], issues: object) -> dict[str, Any] | None:
    coverage = covered_identities(manifest)
    validated = validated_release_identity(manifest)
    by_number = issue_index(issues)
    candidates: list[tuple[int, int, tuple[str, str]]] = []

    for number, issue in by_number.items():
        if issue.get("state") != "open":
            continue
        labels = labels_of(issue)
        if BLOCKED_LABEL not in labels or labels.intersection(PROTECTED_LABELS):
            continue
        body = issue.get("body")
        if not isinstance(body, str):
            raise ReconcileError("Issue bloqueado sin body válido.")

        gate_payload = parse_single_marker(body, GATE_PATTERN, "condor-d043-gate")
        if gate_payload is None:
            continue
        plan_payload = parse_single_marker(body, PLAN_PATTERN, "factory-plan-task")
        if plan_payload is None:
            raise ReconcileError("Issue D-043 bloqueado carece de factory-plan-task.")

        gate = gate_identity(gate_payload)
        if gate not in coverage:
            continue
        order, depends_on = plan_contract(plan_payload)
        if all(
            dependency in by_number and by_number[dependency].get("state") == "closed"
            for dependency in depends_on
        ):
            candidates.append((order, number, gate))

    if not candidates:
        return None

    order, number, gate = sorted(candidates)[0]
    marker = reconciliation_marker(number, gate, validated)
    comment = (
        f"{marker}\n"
        "RECONCILIACIÓN D-043 automática · evidencia humana ya validada\n\n"
        f"- Issue: #{number}\n"
        f"- Gate cubierta: V{gate[0]} / {gate[1]}\n"
        f"- Evidencia validada: V{validated[0]} / {validated[1]}\n"
        "- Dependencias estructurales: cerradas.\n"
        "- Acción: estado bloqueado → estado disponible.\n\n"
        "Este comentario no marca flags humanos ni valida producción; únicamente "
        "reconcilia una precondición D-043 ya satisfecha."
    )
    return {
        "issue": number,
        "order": order,
        "gate": {"version": gate[0], "sha": gate[1]},
        "validated": {"version": validated[0], "sha": validated[1]},
        "marker": marker,
        "comment": comment,
    }


def main() -> int:
    try:
        payload = json.load(sys.stdin)
        if not isinstance(payload, dict):
            raise ReconcileError("La entrada debe ser un objeto JSON.")
        manifest = payload.get("manifest")
        issues = payload.get("issues")
        if not isinstance(manifest, dict):
            raise ReconcileError("manifest debe ser un objeto JSON.")
        action = select_action(manifest, issues)
    except (ValueError, ReconcileError) as error:
        print(f"error: {error}", file=sys.stderr)
        return 2
    print(json.dumps({"action": action}, ensure_ascii=False, sort_keys=True))
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
