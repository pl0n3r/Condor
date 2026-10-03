#!/usr/bin/env python3
"""Construye la tarjeta humana D-043 sin ejecutar ni confirmar la transición."""

from __future__ import annotations

import argparse
import json
import re
import sys
from typing import Any

from release_evidence import CHECK_IDS, EvidenceError, validate_manifest

CARD_SCHEMA_VERSION = 1
REPO_PATTERN = re.compile(r"[A-Za-z0-9_.-]+/[A-Za-z0-9_.-]+\Z", re.ASCII)
TENANT_PATTERN = re.compile(r"[a-z0-9][a-z0-9-]{0,63}\Z", re.ASCII)

INPUT_BY_CHECK = {
    "migraciones": "migraciones_verificadas",
    "roles": "roles_verificados",
    "comandos": "comandos_verificados",
    "configuracion": "configuracion_verificada",
    "cache": "cache_verificada",
}

EXPLANATION_BY_CHECK = {
    "migraciones": "Confirmo que las migraciones requeridas por esta release fueron verificadas.",
    "roles": "Confirmo que los roles y permisos requeridos por esta release fueron verificados.",
    "comandos": "Confirmo que los comandos o aprovisionamiento requeridos fueron verificados.",
    "configuracion": "Confirmo que la configuración operativa requerida fue verificada.",
    "cache": "Confirmo que la limpieza o warmup de caché requerida fue verificada.",
}


def validation_marker(manifest: dict[str, Any]) -> str:
    """Devuelve un marker estable para el SHA exacto de la release."""
    validate_manifest(manifest)
    payload = json.dumps(
        {"version": CARD_SCHEMA_VERSION, "sha": manifest["sha"]},
        sort_keys=True,
        separators=(",", ":"),
    )
    return f"<!-- condor-d043-validation-card {payload} -->"


def required_checks(manifest: dict[str, Any]) -> list[str]:
    """Extrae solo las confirmaciones humanas requeridas por el manifiesto."""
    validate_manifest(manifest)
    transition = manifest["transition"]
    if transition["required"] is not True:
        raise EvidenceError("La tarjeta D-043 requiere una transición pendiente.")

    checks = [
        item["id"]
        for item in transition["checks"]
        if item["required"] is True
    ]
    if not checks:
        raise EvidenceError("La transición requerida no contiene flags humanos.")
    if any(check_id not in CHECK_IDS for check_id in checks):
        raise EvidenceError("El manifiesto contiene una transición desconocida.")
    return checks


def workflow_command(
    manifest: dict[str, Any],
    *,
    repository: str,
    tenant_slug: str | None = None,
) -> str:
    """Construye un comando de texto con valores previamente validados."""
    validate_manifest(manifest)
    if REPO_PATTERN.fullmatch(repository) is None:
        raise EvidenceError("repository debe usar owner/name.")
    if tenant_slug is not None and TENANT_PATTERN.fullmatch(tenant_slug) is None:
        raise EvidenceError("tenant_slug inválido.")

    checks = required_checks(manifest)
    version = manifest["version"]
    sha = manifest["sha"]

    parts = [
        "gh",
        "workflow",
        "run",
        "observar-release.yml",
        "--repo",
        repository,
        "-f",
        f"version={version}",
        "-f",
        f"sha={sha}",
    ]
    if tenant_slug:
        parts.extend(["-f", f"tenant_slug={tenant_slug}"])

    for check_id in checks:
        parts.extend(["-f", f"{INPUT_BY_CHECK[check_id]}=true"])

    # Todos los valores variables usan alfabetos cerrados validados arriba.
    # La salida es únicamente texto mostrado al dueño; este módulo no ejecuta comandos.
    return " ".join(parts)


def build_card(
    manifest: dict[str, Any],
    *,
    repository: str,
    tenant_slug: str | None = None,
) -> str:
    """Renderiza una tarjeta informativa; nunca ejecuta ni marca la validación."""
    checks = required_checks(manifest)
    command = workflow_command(
        manifest,
        repository=repository,
        tenant_slug=tenant_slug,
    )

    lines = [
        validation_marker(manifest),
        "## Validación humana D-043 pendiente",
        "",
        "El observer confirmó la identidad exacta del deploy, pero esta release "
        "requiere confirmaciones humanas antes de declarar producción validada.",
        "",
        f"- Versión: `V{manifest['version']}`",
        f"- SHA exacto: `{manifest['sha']}`",
    ]
    if tenant_slug:
        lines.append(f"- Tenant slug vigente: `{tenant_slug}`")

    lines.extend(
        [
            "",
            "| Flag requerido | Qué confirma el dueño |",
            "| --- | --- |",
        ]
    )
    for check_id in checks:
        input_name = INPUT_BY_CHECK[check_id]
        lines.append(
            f"| `{input_name}=true` | {EXPLANATION_BY_CHECK[check_id]} |"
        )

    lines.extend(
        [
            "",
            "### Comando listo para ejecutar por el dueño",
            "",
            "```bash",
            command,
            "```",
            "",
            "Este comentario **no ejecuta** el workflow, no marca ningún flag como "
            "verificado por sí mismo y no declara `VALIDATED_IN_PRODUCTION`. "
            "La confirmación ocurre únicamente si el dueño ejecuta el comando.",
        ]
    )
    return "\n".join(lines) + "\n"


def load_manifest() -> dict[str, Any]:
    """Lee un único manifiesto JSON desde stdin."""
    try:
        payload = json.load(sys.stdin)
    except ValueError as error:
        raise EvidenceError("El manifiesto no es JSON válido.") from error
    if not isinstance(payload, dict):
        raise EvidenceError("El manifiesto debe ser un objeto JSON.")
    return payload


def main(argv: list[str] | None = None) -> int:
    """CLI determinista para uso del observer automático."""
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument("--repo", required=True)
    parser.add_argument("--tenant-slug")
    args = parser.parse_args(argv)

    try:
        manifest = load_manifest()
        print(
            build_card(
                manifest,
                repository=args.repo,
                tenant_slug=args.tenant_slug,
            ),
            end="",
        )
        return 0
    except EvidenceError as error:
        parser.error(str(error))

    return 2


if __name__ == "__main__":
    raise SystemExit(main())
