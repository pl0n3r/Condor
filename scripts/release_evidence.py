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
ROOT = SCRIPT_DIR.parent
if str(SCRIPT_DIR) not in sys.path:
    sys.path.insert(0, str(SCRIPT_DIR))

from ci_change_classifier import classify, script_requires_release_transition

SCHEMA = "condor.release-evidence.v1"
CANONICAL_VERSION_FILE = ROOT / "config" / "version.php"
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
STOREFRONT_CHECKS = ("storefront", "slug_desconocido")


def public_checks_for_version(version: str) -> list[str]:
    checks = list(PUBLIC_CHECKS)
    if tuple(map(int, version.split("."))) >= (0, 1, 13):
        checks.extend(STOREFRONT_CHECKS)
    return checks
CHECK_IDS = (
    "migraciones",
    "roles",
    "comandos",
    "configuracion",
    "cache",
)
OBSERVATION_STATES = {
    "NO_OBSERVADO",
    "DEPLOY_OBSERVED",
    "VALIDATED_IN_PRODUCTION",
}


class EvidenceError(ValueError):
    """Error determinista presentable sin datos sensibles."""


def normalized_paths(paths: Iterable[str]) -> list[str]:
    """Normaliza el diff sin alterar nombres válidos de archivos."""
    return sorted({path.strip() for path in paths if path.strip()})


def read_version(path: Path) -> str:
    """Lee una fuente de versión controlada por el repositorio."""
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
        (path.startswith("config/") and path != "config/version.php")
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
        "public_checks": public_checks_for_version(version),
        "transition": {
            "required": selection.transicion_release,
            "checks": [
                {"id": check_id, "required": requirements[check_id]}
                for check_id in CHECK_IDS
            ],
        },
    }


def validated_transition_check(item: object) -> tuple[object, bool]:
    """Valida una entrada individual del checklist y devuelve sus campos canónicos."""
    if not isinstance(item, dict):
        raise EvidenceError("Cada comprobación de transición debe ser un objeto.")
    required = item.get("required")
    if not isinstance(required, bool):
        raise EvidenceError("Cada checks[*].required debe ser booleano.")
    return item.get("id"), required


def validate_transition(transition: object) -> None:
    """Valida forma, tipos y coherencia interna del checklist de transición."""
    if not isinstance(transition, dict):
        raise EvidenceError("El manifiesto no contiene checklist de transición.")

    transition_required = transition.get("required")
    if not isinstance(transition_required, bool):
        raise EvidenceError("transition.required debe ser booleano.")

    checks = transition.get("checks")
    if not isinstance(checks, list) or len(checks) != len(CHECK_IDS):
        raise EvidenceError("El checklist de transición es inválido.")

    normalized = [validated_transition_check(item) for item in checks]
    ids = [check_id for check_id, _ in normalized]
    required_flags = [required for _, required in normalized]

    if ids != list(CHECK_IDS):
        raise EvidenceError("El checklist de transición no coincide con el contrato.")
    if transition_required != any(required_flags):
        raise EvidenceError("transition.required no coincide con el checklist.")
    if transition_required and not required_flags[-1]:
        raise EvidenceError("Una transición requerida debe exigir verificación de caché.")


def validated_release_identity(value: object) -> tuple[str, str]:
    """Valida una identidad mínima de release sin aceptar campos implícitos."""
    if not isinstance(value, dict):
        raise EvidenceError("La identidad de release debe ser un objeto.")

    version = value.get("version")
    sha = value.get("sha")
    if not isinstance(version, str) or VERSION_PATTERN.fullmatch(version) is None:
        raise EvidenceError("La identidad de release contiene una versión inválida.")
    if not isinstance(sha, str) or SHA_PATTERN.fullmatch(sha) is None:
        raise EvidenceError("La identidad de release contiene un SHA inválido.")
    return version, sha


def version_key(version: str) -> tuple[int, int, int]:
    """Convierte X.Y.Z en una clave comparable sin inferir saltos válidos."""
    major, minor, patch = map(int, version.split("."))
    return major, minor, patch


def validate_covered_releases(manifest: dict[str, Any]) -> None:
    """Valida cobertura explícita de releases anteriores sin inferirla por versión."""
    covered = manifest.get("covered_releases", [])
    if not isinstance(covered, list):
        raise EvidenceError("covered_releases debe ser una lista.")

    current_version, current_sha = validated_release_identity(manifest)
    current_key = version_key(current_version)
    seen_versions: dict[str, str] = {current_version: current_sha}
    seen_shas: dict[str, str] = {current_sha: current_version}
    previous_key: tuple[int, int, int] | None = None

    for item in covered:
        version, sha = validated_release_identity(item)
        key = version_key(version)
        if key >= current_key:
            raise EvidenceError(
                "Las releases cubiertas deben ser anteriores a la release actual."
            )

        known_sha = seen_versions.get(version)
        if known_sha is not None:
            if known_sha != sha:
                raise EvidenceError(
                    "Una versión cubierta no puede apuntar a dos SHAs distintos."
                )
            raise EvidenceError("covered_releases contiene una identidad duplicada.")

        known_version = seen_shas.get(sha)
        if known_version is not None:
            raise EvidenceError(
                "Un SHA cubierto no puede pertenecer a dos versiones distintas."
            )

        if previous_key is not None and key <= previous_key:
            raise EvidenceError("covered_releases no está en orden canónico.")

        seen_versions[version] = sha
        seen_shas[sha] = version
        previous_key = key


def validate_manifest(manifest: dict[str, Any]) -> None:
    """Valida identidad y checklist antes de permitir cualquier promoción de estado."""
    if manifest.get("schema") != SCHEMA:
        raise EvidenceError("El manifiesto de release usa un schema no soportado.")

    version = manifest.get("version")
    if not isinstance(version, str) or VERSION_PATTERN.fullmatch(version) is None:
        raise EvidenceError("El manifiesto contiene una versión inválida.")

    sha = manifest.get("sha")
    if not isinstance(sha, str) or SHA_PATTERN.fullmatch(sha) is None:
        raise EvidenceError("El manifiesto contiene un SHA inválido.")

    if manifest.get("public_checks") != public_checks_for_version(version):
        raise EvidenceError("El manifiesto no contiene el contrato público esperado.")

    validate_transition(manifest.get("transition"))
    validate_covered_releases(manifest)


def accumulate_pending_manifests(
    current_manifest: dict[str, Any],
    pending_manifests: object,
) -> dict[str, Any]:
    """Acumula checks pendientes explícitos sin mutar los manifiestos de entrada."""
    validate_manifest(current_manifest)
    if not isinstance(pending_manifests, list):
        raise EvidenceError("pending_manifests debe ser una lista.")

    current_version, current_sha = validated_release_identity(current_manifest)
    current_key = version_key(current_version)
    requirements = {
        item["id"]: item["required"] is True
        for item in current_manifest["transition"]["checks"]
    }
    coverage: dict[tuple[str, str], dict[str, str]] = {}
    version_to_sha: dict[str, str] = {}
    sha_to_version: dict[str, str] = {}

    def add_coverage(version: str, sha: str) -> None:
        identity = (version, sha)
        if identity == (current_version, current_sha):
            raise EvidenceError(
                "La release actual no puede aparecer como release pendiente cubierta."
            )
        if version_key(version) >= current_key:
            raise EvidenceError(
                "Una release pendiente debe ser anterior a la release actual."
            )

        known_sha = version_to_sha.get(version)
        if known_sha is not None and known_sha != sha:
            raise EvidenceError(
                "Una versión pendiente no puede apuntar a dos SHAs distintos."
            )
        known_version = sha_to_version.get(sha)
        if known_version is not None and known_version != version:
            raise EvidenceError(
                "Un SHA pendiente no puede pertenecer a dos versiones distintas."
            )
        if identity in coverage:
            return

        version_to_sha[version] = sha
        sha_to_version[sha] = version
        coverage[identity] = {"version": version, "sha": sha}

    for item in current_manifest.get("covered_releases", []):
        version, sha = validated_release_identity(item)
        add_coverage(version, sha)

    for pending in pending_manifests:
        if not isinstance(pending, dict):
            raise EvidenceError("Cada manifiesto pendiente debe ser un objeto.")
        validate_manifest(pending)

        version, sha = validated_release_identity(pending)
        add_coverage(version, sha)
        for item in pending.get("covered_releases", []):
            covered_version, covered_sha = validated_release_identity(item)
            add_coverage(covered_version, covered_sha)

        for item in pending["transition"]["checks"]:
            check_id = item["id"]
            requirements[check_id] = (
                requirements[check_id] or item["required"] is True
            )

    result = dict(current_manifest)
    result["covered_releases"] = sorted(
        coverage.values(),
        key=lambda item: (version_key(item["version"]), item["sha"]),
    )
    result["transition"] = {
        "required": any(requirements.values()),
        "checks": [
            {"id": check_id, "required": requirements[check_id]}
            for check_id in CHECK_IDS
        ],
    }
    validate_manifest(result)
    return result


def public_evidence(
    observation: dict[str, Any], public_checks: list[str],
) -> dict[str, dict[str, Any]]:
    """Normaliza el contrato de smoke y marca comprobaciones ausentes."""
    raw_checks = observation.get("comprobaciones")
    observation_checks = raw_checks if isinstance(raw_checks, dict) else {}
    public: dict[str, dict[str, Any]] = {}

    for check_id in public_checks:
        raw = observation_checks.get(check_id)
        if not isinstance(raw, dict):
            public[check_id] = {
                "ok": False,
                "clase": "funcional",
                "detalle": "No se registró esta comprobación.",
            }
            continue

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

    return public


def transition_evidence(
    manifest: dict[str, Any],
    verified: set[str],
) -> tuple[dict[str, dict[str, bool]], list[str]]:
    """Evalúa únicamente el checklist requerido por el manifiesto."""
    checks: dict[str, dict[str, bool]] = {}
    pending: list[str] = []

    for item in manifest["transition"]["checks"]:
        check_id = item["id"]
        required = item.get("required") is True
        is_verified = check_id in verified
        checks[check_id] = {
            "required": required,
            "verified": is_verified,
            "ok": (not required) or is_verified,
        }
        if required and not is_verified:
            pending.append(check_id)

    return checks, pending


def release_state(
    *,
    same_identity: bool,
    observation_state: object,
    public: dict[str, dict[str, Any]],
    required_pending: list[str],
) -> str:
    """Mantiene separados identidad observada, deploy y validación."""
    if observation_state not in OBSERVATION_STATES:
        raise EvidenceError("La observación contiene un estado no soportado.")

    identity_observed = same_identity and public["health"]["ok"]
    if not identity_observed or observation_state == "NO_OBSERVADO":
        return "NO_OBSERVADO"
    if observation_state == "DEPLOY_OBSERVED":
        return "DEPLOY_OBSERVED"
    if all(item["ok"] for item in public.values()) and not required_pending:
        return "VALIDATED_IN_PRODUCTION"
    return "DEPLOY_OBSERVED"


def finalize(
    manifest: dict[str, Any],
    observation: dict[str, Any],
    verified: Iterable[str],
) -> dict[str, Any]:
    """Combina identidad, smoke y transición sin convertir deploy en validación implícita."""
    validate_manifest(manifest)
    verified_set = set(verified)
    if verified_set.difference(CHECK_IDS):
        raise EvidenceError("Se intentó verificar una comprobación de transición desconocida.")

    same_identity = (
        observation.get("version_esperada") == manifest["version"]
        and observation.get("sha_esperado") == manifest["sha"]
    )
    public = public_evidence(observation, manifest["public_checks"])
    transition_checks, required_pending = transition_evidence(
        manifest,
        verified_set,
    )
    state = release_state(
        same_identity=same_identity,
        observation_state=observation.get("estado"),
        public=public,
        required_pending=required_pending,
    )

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
    """Renderiza la misma evidencia estructurada como resumen humano."""
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


def load_envelope() -> tuple[dict[str, Any], dict[str, Any]]:
    """Lee manifiesto y observación por stdin; el CLI no acepta rutas arbitrarias."""
    try:
        payload = json.load(sys.stdin)
    except ValueError as error:
        raise EvidenceError("La entrada de evidencia no es JSON válido.") from error
    if not isinstance(payload, dict):
        raise EvidenceError("La entrada de evidencia debe ser un objeto JSON.")

    manifest = payload.get("manifest")
    observation = payload.get("observation")
    if not isinstance(manifest, dict) or not isinstance(observation, dict):
        raise EvidenceError("La entrada debe contener manifest y observation.")
    return manifest, observation


def main(argv: list[str] | None = None) -> int:
    """Expone generación y consolidación como CLI determinista."""
    parser = argparse.ArgumentParser(description=__doc__)
    subparsers = parser.add_subparsers(dest="command", required=True)

    manifest_parser = subparsers.add_parser("manifest")
    manifest_parser.add_argument("--sha", required=True)
    manifest_parser.add_argument("--expected-version")

    finalize_parser = subparsers.add_parser("finalize")
    finalize_parser.add_argument(
        "--verified",
        action="append",
        default=[],
        choices=CHECK_IDS,
    )
    finalize_parser.add_argument("--markdown", action="store_true")

    args = parser.parse_args(argv)
    try:
        if args.command == "manifest":
            manifest = build_manifest(
                version_file=CANONICAL_VERSION_FILE,
                sha=args.sha,
                changed_paths=sys.stdin.read().splitlines(),
                expected_version=args.expected_version,
            )
            print(json.dumps(manifest, ensure_ascii=False, indent=2, sort_keys=True))
            return 0

        manifest, observation = load_envelope()
        evidence = finalize(manifest, observation, args.verified)
        payload = json.dumps(evidence, ensure_ascii=False, indent=2, sort_keys=True) + "\n"
        print(markdown(evidence) if args.markdown else payload, end="")
        return 0 if evidence["estado"] == "VALIDATED_IN_PRODUCTION" else 1
    except EvidenceError as error:
        parser.error(str(error))

    return 2


if __name__ == "__main__":
    raise SystemExit(main())
