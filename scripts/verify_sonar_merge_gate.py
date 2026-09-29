#!/usr/bin/env python3
"""Valida que la ruleset de main exija Validar y SonarQube Cloud."""

from __future__ import annotations

import json
import sys
from typing import Any


MAX_INPUT = 1_000_000
RULESET_ID = 23709472
DEFAULT_BRANCH_REF = "~DEFAULT_BRANCH"
REQUIRED_CHECKS = {
    "Validar": 15368,
    "SonarCloud Code Analysis": 12526,
}


class SonarMergeGateError(ValueError):
    """La ruleset viva no demuestra el contrato de merge requerido."""


def _string_list(value: Any, field: str) -> list[str]:
    if not isinstance(value, list) or len(value) > 500:
        raise SonarMergeGateError(f"{field} debe ser una lista acotada.")
    if any(not isinstance(item, str) for item in value):
        raise SonarMergeGateError(f"{field} contiene valores inválidos.")
    return value


def _rules(value: Any) -> list[dict[str, Any]]:
    if not isinstance(value, list) or not 1 <= len(value) <= 100:
        raise SonarMergeGateError("rules debe ser una lista acotada y no vacía.")
    if any(not isinstance(item, dict) for item in value):
        raise SonarMergeGateError("rules contiene entradas inválidas.")
    return value


def _required_status_rule(rules: list[dict[str, Any]]) -> dict[str, Any]:
    matches = [item for item in rules if item.get("type") == "required_status_checks"]
    if len(matches) != 1:
        raise SonarMergeGateError(
            "Debe existir exactamente una regla required_status_checks."
        )
    return matches[0]


def _bypass_visibility(ruleset: dict[str, Any]) -> str:
    if "bypass_actors" not in ruleset:
        return "unknown"
    bypass = ruleset["bypass_actors"]
    if not isinstance(bypass, list):
        raise SonarMergeGateError("bypass_actors visible debe ser una lista.")
    if bypass:
        raise SonarMergeGateError("La ruleset no admite bypass actors.")
    return "known-empty"


def validate_ruleset(payload: Any) -> dict[str, int | str]:
    """Valida estructura, identidad y checks requeridos del ruleset de main."""
    if not isinstance(payload, dict):
        raise SonarMergeGateError("El payload de ruleset debe ser un objeto.")

    identifier = payload.get("id")
    if (
        isinstance(identifier, bool)
        or not isinstance(identifier, int)
        or identifier != RULESET_ID
    ):
        raise SonarMergeGateError("Ruleset id inesperado o inválido.")
    if payload.get("target") != "branch":
        raise SonarMergeGateError("La ruleset debe aplicar a branches.")
    if payload.get("enforcement") != "active":
        raise SonarMergeGateError("La ruleset debe estar activa.")

    conditions = payload.get("conditions")
    if not isinstance(conditions, dict):
        raise SonarMergeGateError("conditions inválido.")
    ref_name = conditions.get("ref_name")
    if not isinstance(ref_name, dict):
        raise SonarMergeGateError("conditions.ref_name inválido.")
    includes = _string_list(ref_name.get("include"), "conditions.ref_name.include")
    excludes = _string_list(ref_name.get("exclude"), "conditions.ref_name.exclude")
    if DEFAULT_BRANCH_REF not in includes:
        raise SonarMergeGateError("La default branch no está incluida.")
    if excludes:
        raise SonarMergeGateError("La ruleset no debe excluir refs.")

    bypass_visibility = _bypass_visibility(payload)
    required_rule = _required_status_rule(_rules(payload.get("rules")))
    parameters = required_rule.get("parameters")
    if not isinstance(parameters, dict):
        raise SonarMergeGateError("required_status_checks.parameters inválido.")
    if parameters.get("strict_required_status_checks_policy") is not False:
        raise SonarMergeGateError("strict_required_status_checks_policy cambió.")
    if parameters.get("do_not_enforce_on_create") is not False:
        raise SonarMergeGateError("do_not_enforce_on_create cambió.")

    checks = parameters.get("required_status_checks")
    if not isinstance(checks, list) or not 1 <= len(checks) <= 100:
        raise SonarMergeGateError("required_status_checks debe ser una lista acotada.")

    seen: dict[str, int] = {}
    for item in checks:
        if not isinstance(item, dict):
            raise SonarMergeGateError("required_status_checks contiene entrada inválida.")
        context = item.get("context")
        integration_id = item.get("integration_id")
        if (
            not isinstance(context, str)
            or not context
            or len(context) > 120
            or isinstance(integration_id, bool)
            or not isinstance(integration_id, int)
            or integration_id < 1
        ):
            raise SonarMergeGateError("Required check inválido.")
        if context in seen:
            raise SonarMergeGateError(f"Required check duplicado: {context}.")
        seen[context] = integration_id

    for context, expected_integration in REQUIRED_CHECKS.items():
        actual = seen.get(context)
        if actual != expected_integration:
            raise SonarMergeGateError(
                f"Required check incorrecto: {context}@{actual!r}; "
                f"se requiere {context}@{expected_integration}."
            )

    return {
        "status": "protected",
        "ruleset_id": RULESET_ID,
        "required_checks": len(REQUIRED_CHECKS),
        "bypass_visibility": bypass_visibility,
    }


def main() -> int:
    raw = sys.stdin.read(MAX_INPUT + 1)
    if len(raw) > MAX_INPUT:
        print("ERROR: payload de ruleset demasiado grande.", file=sys.stderr)
        return 2
    try:
        payload = json.loads(raw)
        result = validate_ruleset(payload)
    except (json.JSONDecodeError, SonarMergeGateError) as exc:
        print(f"ERROR: {exc}", file=sys.stderr)
        return 2
    print(json.dumps(result, sort_keys=True))
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
