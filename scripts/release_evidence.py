#!/usr/bin/env python3
"""Genera y consolida evidencia reproducible de release sin mutar producción."""

from __future__ import annotations

import argparse
import json
import re
import sys
from pathlib import Path
from typing import Any, Iterable

SCRIPT_DIR = Path(__file__).resolve().parent
if str(SCRIPT_DIR) not in sys.path:
    sys.path.insert(0, str(SCRIPT_DIR))

from ci_change_classifier import classify, script_requires_release_transition

SCHEMA = "condor.release-evidence.v1"
SHA_PATTERN = re.compile(r"[0-9a-f]{40}\Z", re.ASCII)
VERSION_PATTERN = re.compile(r"\d+\.\d+\.\d+\Z", re.ASCII)
VERSION_ASSIGNMENT = re.compile(
    r"['\"]version['\"]\s*=>\s*['\"](\d+\.\d+\.\d+)['\"]",
    re.ASCII,
)
PUBLIC_CHECKS = (
    "health",
    "home",
    "admin_login",
    "css_publico",
    "css_admin",
    "js_admin",
)
CHECK_IDS = (
    "migraciones",
    "roles",
    "comandos",
    "configuracion",
    "cache",
)


class EvidenceError(ValueError):
    """Error determinista presentable sin datos sensibles."""


def normalized_paths(paths: Iterable[str]) -> list[str]:
    return sorted({path.strip() for path in paths if path.strip()})


def read_version(path: Path) -> str:
    """Lee la única versión canónica de config/version.php."""
    try:
        text = path.read_text(encoding="utf-8")
    except OSError as error:
        raise EvidenceError("No se pudo leer la fuente canónica de versión.") from error

    matches = VERSION_ASSIGNMENT.findall(text)
    if len(matches) != 1 or VERSION_PATTERN.fullmatch(matches[0]) is None:
        raise EvidenceError("La fuente canónica no contiene una única versión X.Y.Z.")
    return matches[0]


def transition_requirements(paths: list[str], required: bool) -> dict[str, bool]:
    """Deriva un checklist operativo sin ejecutar ninguna transición."""
    migrations = any(path.startswith("migrations/") for path in paths)
    roles = any(
        path == "config/packages/security.yaml"
        or path.startswith(
            (
                "src/Domain/Identity/",
                "src/Application/Identity/",
                "src/Infrastructure/Security/",
            )
        )
        for path in paths
    )
    commands = any(
        path == "bin/console"
        or path.startswith("src/Console/")
        or (path.startswith("scripts/") and script_requires_release_transition(path))
        for path in paths
    )
    configuration = any(
        path.startswith("config/")
        or path in {".env.example", ".htaccess", "public/index.php"}
        for path in paths
    )

    return {
        "migraciones": migrations,
        "roles": roles,
        "comandos": commands,
        "configuracion": configuration,
        "cache": required,
    }


def build_manifest(
    *,
    version_file: Path,
    sha: str,
    changed_paths: Iterable[str],
    expected_version: str | None = None,
) -> dict[str, Any]:
    """Construye la identidad y el checklist verificable de una release."""
    if SHA_PATTERN.fullmatch(sha) is None:
        raise EvidenceError("El SHA de release debe tener 40 hexadecimales minúsculos.")

    version = read_version(version_file)
    if expected_version is not None and version != expected_version:
        raise EvidenceError("La versión solicitada no coincide con config/version.php.")

    paths = normalized_paths(changed_paths)
    selection = classify(paths, "pull_request")
    requirements = transition_requirements(paths, selection.transicion_release)

    return {
        "schema": SCHEMA,
        "version": version,
        "sha": sha,
        "version_source": "config/version.php",
        "change_count": len(paths),
        "categories": selection.categorias,
        "selection_mode": selection.modo,
        "selection_reason": selection.motivo,
        "public_checks": list(PUBLIC_CHECKS),
        "transition": {
            "required": selection.transicion_release,
            "checks": [
                {"id": check_id, "required": requirements[check_id]}
                for check_id in CHECK_IDS
            ],
        },
    }


def validate_manifest(manifest: dict[str, Any]) -> None:
    if manifest.get("schema") != SCHEMA:
        raise EvidenceError("El manifiesto de release usa un schema no soportado.")
    version = manifest.get("version")
    sha = manifest.get("sha")
    if not isinstance(version, str) or VERSION_PATTERN.fullmatch(version) is None:
        raise EvidenceError("El manifiesto contiene una versión inválida.")
    if not isinstance(sha, str) or SHA_PATTERN.fullmatch(sha) is None:
        raise EvidenceError("El manifiesto contiene un SHA inválido.")
    if manifest.get("public_checks") != list(PUBLIC_CHECKS):
        raise EvidenceError("El manifiesto no contiene el contrato público esperado.")

    transition = manifest.get("transition")
    if not isinstance(transition, dict):
        raise EvidenceError("El manifiesto no contiene checklist de transición.")
    checks = transition.get("checks")
    if not isinstance(checks, list):
        raise EvidenceError("El checklist de transición es inválido.")
    ids = [
        item.get("id")
        for item in checks
        if isinstance(item, dict)
    ]
    if ids != list(CHECK_IDS):
        raise EvidenceError("El checklist de transición no coincide con el contrato.")


def finalize(
    manifest: dict[str, Any],
    observation: dict[str, Any],
    verified: Iterable[str],
) -> dict[str, Any]:
    """Combina identidad, smoke y transición sin convertir deploy en validación implícita."""
    validate_manifest(manifest)
    verified_set = set(verified)
    unknown = verified_set.difference(CHECK_IDS)
    if unknown:
        raise EvidenceError("Se intentó verificar una comprobación de transición desconocida.")

    same_identity = (
        observation.get("version_esperada") == manifest["version"]
        and observation.get("sha_esperado") == manifest["sha"]
    )
    observation_checks = observation.get("comprobaciones")
    if not isinstance(observation_checks, dict):
        observation_checks = {}

    public: dict[str, dict[str, Any]] = {}
    for check_id in PUBLIC_CHECKS:
        raw = observation_checks.get(check_id)
        if isinstance(raw, dict):
            public[check_id] = {
                "ok": raw.get("ok") is True,
                "clase": raw.get("clase", "desconocido"),
                "detalle": str(raw.get("detalle", "Sin evidencia.")),
                **(
                    {"intento": raw["intento"]}
                    if isinstance(raw.get("intento"), int)
                    else {}
                ),
            }
        else:
            public[check_id] = {
                "ok": False,
                "clase": "funcional",
                "detalle": "No se registró esta comprobación.",
            }

    transition_checks: dict[str, dict[str, bool]] = {}
    required_pending: list[str] = []
    for item in manifest["transition"]["checks"]:
        check_id = item["id"]
        required = item.get("required") is True
        is_verified = check_id in verified_set
        transition_checks[check_id] = {
            "required": required,
            "verified": is_verified,
            "ok": (not required) or is_verified,
        }
        if required and not is_verified:
            required_pending.append(check_id)

    public_ok = all(item["ok"] for item in public.values())
    identity_observed = same_identity and public["health"]["ok"]
    if not identity_observed or observation.get("estado") == "NO_OBSERVADO":
        state = "NO_OBSERVADO"
    elif public_ok and not required_pending:
        state = "VALIDATED_IN_PRODUCTION"
    else:
        state = "DEPLOY_OBSERVED"

    return {
        "schema": SCHEMA,
        "estado": state,
        "version": manifest["version"],
        "sha": manifest["sha"],
        "identity_match": same_identity,
        "public_checks": public,
        "transition": {
            "required": manifest["transition"]["required"] is True,
            "checks": transition_checks,
            "pending": required_pending,
        },
    }


def markdown(evidence: dict[str, Any]) -> str:
    lines = [
        "## Evidencia de release Condor",
        "",
        f"- Estado: **{evidence['estado']}**",
        f"- Versión: `{evidence['version']}`",
        f"- SHA: `{evidence['sha']}`",
        "",
        "| Smoke read-only | Resultado | Clase | Detalle |",
        "| --- | --- | --- | --- |",
    ]
    for check_id, item in evidence["public_checks"].items():
        icon = "✅" if item["ok"] else "❌"
        detail = str(item["detalle"]).replace("|", "\\|").replace("\n", " ")
        lines.append(
            f"| `{check_id}` | {icon} | `{item['clase']}` | {detail} |"
        )

    lines.extend(
        [
            "",
            "| Transición | Requerida | Verificada | Resultado |",
            "| --- | --- | --- | --- |",
        ]
    )
    for check_id, item in evidence["transition"]["checks"].items():
        icon = "✅" if item["ok"] else "❌"
        lines.append(
            f"| `{check_id}` | "
            f"{'sí' if item['required'] else 'no'} | "
            f"{'sí' if item['verified'] else 'no'} | {icon} |"
        )

    if evidence["estado"] != "VALIDATED_IN_PRODUCTION":
        lines.extend(
            [
                "",
                "Esta evidencia **no** declara producción validada. "
                "Deploy, transición operativa y validación permanecen separados.",
            ]
        )

    payload = json.dumps(evidence, ensure_ascii=False, sort_keys=True)
    lines.extend(
        [
            "",
            "<details>",
            "<summary>Evidencia JSON</summary>",
            "",
            "```json",
            payload,
            "```",
            "</details>",
        ]
    )
    return "\n".join(lines) + "\n"


def load_json(path: Path, label: str) -> dict[str, Any]:
    try:
        value = json.loads(path.read_text(encoding="utf-8"))
    except (OSError, ValueError) as error:
        raise EvidenceError(f"No se pudo leer {label} como JSON válido.") from error
    if not isinstance(value, dict):
        raise EvidenceError(f"{label} debe ser un objeto JSON.")
    return value


def parse_changed_paths(path: Path | None) -> list[str]:
    if path is None:
        return [line.rstrip("\n") for line in sys.stdin]
    try:
        return path.read_text(encoding="utf-8").splitlines()
    except OSError as error:
        raise EvidenceError("No se pudo leer la lista de cambios.") from error


def main(argv: list[str] | None = None) -> int:
    parser = argparse.ArgumentParser(description=__doc__)
    subparsers = parser.add_subparsers(dest="command", required=True)

    manifest_parser = subparsers.add_parser("manifest")
    manifest_parser.add_argument("--sha", required=True)
    manifest_parser.add_argument("--expected-version")
    manifest_parser.add_argument(
        "--version-file",
        type=Path,
        default=Path("config/version.php"),
    )
    manifest_parser.add_argument("--changes", type=Path)

    finalize_parser = subparsers.add_parser("finalize")
    finalize_parser.add_argument("--manifest", type=Path, required=True)
    finalize_parser.add_argument("--observation", type=Path, required=True)
    finalize_parser.add_argument(
        "--verified",
        action="append",
        default=[],
        choices=CHECK_IDS,
    )
    finalize_parser.add_argument("--json-out", type=Path)
    finalize_parser.add_argument("--markdown", action="store_true")

    args = parser.parse_args(argv)
    try:
        if args.command == "manifest":
            manifest = build_manifest(
                version_file=args.version_file,
                sha=args.sha,
                changed_paths=parse_changed_paths(args.changes),
                expected_version=args.expected_version,
            )
            print(json.dumps(manifest, ensure_ascii=False, indent=2, sort_keys=True))
            return 0

        manifest = load_json(args.manifest, "el manifiesto")
        observation = load_json(args.observation, "la observación")
        evidence = finalize(manifest, observation, args.verified)
        payload = json.dumps(evidence, ensure_ascii=False, indent=2, sort_keys=True) + "\n"
        if args.json_out is not None:
            args.json_out.write_text(payload, encoding="utf-8")
        print(markdown(evidence) if args.markdown else payload, end="")
        return 0 if evidence["estado"] == "VALIDATED_IN_PRODUCTION" else 1
    except EvidenceError as error:
        parser.error(str(error))

    return 2


if __name__ == "__main__":
    raise SystemExit(main())
