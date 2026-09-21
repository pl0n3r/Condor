#!/usr/bin/env python3
"""Clasifica cambios para ejecutar CI proporcional sin abrir huecos de cobertura."""

from __future__ import annotations

import argparse
import json
from dataclasses import asdict, dataclass
from typing import Iterable


@dataclass(frozen=True)
class Selection:
    """Categorías detectadas y gates efectivos del run."""

    categoria_documentacion: bool
    categoria_gobierno: bool
    categoria_frontend: bool
    categoria_backend: bool
    categoria_migraciones: bool
    categoria_dependencias: bool
    categoria_seguridad: bool
    categoria_release: bool
    documentacion: bool
    pruebas_base: bool
    frontend: bool
    backend: bool
    e2e: bool
    validacion_completa: bool
    transicion_release: bool
    modo: str
    motivo: str
    categorias: str


CANONICAL_DOCS = {
    "AGENTES.md",
    "ESPECIFICACIONES.md",
    "GLOSARIO.md",
    "README.md",
    "ROADMAP.md",
}

GITHUB_PREFIX = ".github/"
WORKFLOW_PREFIX = ".github/workflows/"
DOCS_PREFIX = "docs/"
TESTS_PREFIX = "tests/"
SRC_PREFIX = "src/"
CONFIG_PREFIX = "config/"
MIGRATIONS_PREFIX = "migrations/"
TEMPLATES_PREFIX = "templates/"
FRONTEND_PREFIX = "frontend/"
PUBLIC_PREFIX = "public/"
SCRIPTS_PREFIX = "scripts/"

PHPUNIT_CONFIG = "phpunit.xml.dist"
PACKAGE_JSON = "package.json"
PACKAGE_LOCK = "package-lock.json"
PLAYWRIGHT_CONFIG = "playwright.config.mjs"
TSCONFIG = "tsconfig.json"
VITE_CONFIG = "vite.config.ts"

DEPENDENCY_FILES = {
    "composer.json",
    "composer.lock",
    PACKAGE_JSON,
    PACKAGE_LOCK,
}
FRONTEND_CONTROL_FILES = {
    TSCONFIG,
    VITE_CONFIG,
    PLAYWRIGHT_CONFIG,
}
GOVERNANCE_ROOT_FILES = {
    ".coderabbit.yaml",
    ".gitignore",
} | CANONICAL_DOCS
RELEASE_ROOT_FILES = {
    ".env.example",
    ".htaccess",
    "config/version.php",
}
KNOWN_ROOT_FILES = (
    DEPENDENCY_FILES
    | FRONTEND_CONTROL_FILES
    | GOVERNANCE_ROOT_FILES
    | RELEASE_ROOT_FILES
    | {PHPUNIT_CONFIG, "index.html", "bin/console"}
)

TRANSITION_PREFIXES = (
    MIGRATIONS_PREFIX,
    CONFIG_PREFIX,
    "src/Console/",
    "src/Http/Controller/",
    "src/Domain/Identity/",
    "src/Application/Identity/",
)
TRANSITION_SCRIPT_MARKERS = (
    "backfill",
    "deploy",
    "migrat",
    "provision",
    "release",
)
TRANSITION_FALLBACK_PREFIXES = (
    "src/Application/",
    "src/Infrastructure/",
)

SECURITY_PREFIXES = (
    "src/Domain/Identity/",
    "src/Application/Identity/",
    "src/Infrastructure/Security/",
)
SECURITY_FILES = {
    "config/packages/security.yaml",
    "src/Http/Controller/SecurityController.php",
    "src/Infrastructure/Http/SecurityHeadersSubscriber.php",
}

CI_CRITICAL = {
    "scripts/ci_change_classifier.py",
    "scripts/ci_retry.py",
    "scripts/ci_self_audit.py",
    "scripts/ci_throughput_report.py",
    "tests/test_ci_change_classifier.py",
    "tests/test_ci_retry.py",
    "tests/test_ci_self_audit.py",
    "tests/test_ci_throughput_report.py",
    "tests/contract/test_tooling_contract.py",
    "tests/integration/test_tooling_integration.py",
}


def normalized(paths: Iterable[str]) -> list[str]:
    """Normaliza paths y descarta entradas vacías."""
    cleaned: set[str] = set()
    for value in paths:
        candidate = value.strip()
        if not candidate:
            continue
        if candidate.startswith("./"):
            candidate = candidate[2:]
        cleaned.add(candidate)
    return sorted(cleaned)


def starts(path: str, prefixes: tuple[str, ...]) -> bool:
    return path.startswith(prefixes)


def source_requires_release_transition(path: str) -> bool:
    """Clasifica cambios runtime bajo src/ sin penalizar queries read-only."""
    if path.endswith("Query.php"):
        return False
    return (
        "/Entity/" in path
        or "/Service/" in path
        or path.endswith("Service.php")
        or path == "src/Kernel.php"
        or path.startswith(TRANSITION_FALLBACK_PREFIXES)
    )


def script_requires_release_transition(path: str) -> bool:
    """Detecta scripts explícitamente asociados a una transición operativa."""
    lowered = path.lower()
    return any(marker in lowered for marker in TRANSITION_SCRIPT_MARKERS)


def requires_release_transition(path: str) -> bool:
    """Clasifica cambios que requieren verificar transición operativa."""
    if path.endswith(".md") or path.startswith((DOCS_PREFIX, TESTS_PREFIX)):
        return False
    if path == "bin/console" or path.startswith(TRANSITION_PREFIXES):
        return True
    if path.startswith(SRC_PREFIX):
        return source_requires_release_transition(path)
    if path.startswith(SCRIPTS_PREFIX):
        return script_requires_release_transition(path)
    return path in RELEASE_ROOT_FILES


def is_security_path(path: str) -> bool:
    return path in SECURITY_FILES or path.startswith(SECURITY_PREFIXES)


def is_release_path(path: str) -> bool:
    return (
        path in RELEASE_ROOT_FILES
        or path == ".github/workflows/observar-release.yml"
        or (
            path.startswith(SCRIPTS_PREFIX)
            and script_requires_release_transition(path)
        )
    )


def is_frontend_runtime(path: str) -> bool:
    return (
        path.startswith((FRONTEND_PREFIX, TEMPLATES_PREFIX))
        or (path.startswith(PUBLIC_PREFIX) and not path.endswith(".php"))
        or path == "index.html"
        or path in FRONTEND_CONTROL_FILES
    )


def is_backend_runtime(path: str) -> bool:
    return (
        path.startswith((SRC_PREFIX, CONFIG_PREFIX, MIGRATIONS_PREFIX))
        or path == "bin/console"
        or (path.startswith(PUBLIC_PREFIX) and path.endswith(".php"))
        or path == PHPUNIT_CONFIG
    )


def is_known(path: str) -> bool:
    return (
        path.endswith(".md")
        or path in KNOWN_ROOT_FILES
        or path.startswith(
            (
                DOCS_PREFIX,
                GITHUB_PREFIX,
                SCRIPTS_PREFIX,
                TESTS_PREFIX,
                SRC_PREFIX,
                CONFIG_PREFIX,
                MIGRATIONS_PREFIX,
                TEMPLATES_PREFIX,
                FRONTEND_PREFIX,
                PUBLIC_PREFIX,
            )
        )
    )


def categories(files: list[str]) -> dict[str, bool]:
    """Devuelve categorías semánticas independientes de los gates."""
    return {
        "categoria_documentacion": any(
            path.endswith(".md") or path.startswith(DOCS_PREFIX)
            for path in files
        ),
        "categoria_gobierno": any(
            path.startswith(GITHUB_PREFIX)
            or path in GOVERNANCE_ROOT_FILES
            or path.startswith("scripts/ci_")
            or path.startswith("tests/test_ci_")
            for path in files
        ),
        "categoria_frontend": any(
            is_frontend_runtime(path)
            or path.startswith("tests/e2e/")
            for path in files
        ),
        "categoria_backend": any(
            is_backend_runtime(path)
            or path.startswith("tests/php/")
            for path in files
        ),
        "categoria_migraciones": any(
            path.startswith(MIGRATIONS_PREFIX)
            for path in files
        ),
        "categoria_dependencias": any(
            path in DEPENDENCY_FILES
            for path in files
        ),
        "categoria_seguridad": any(
            is_security_path(path)
            for path in files
        ),
        "categoria_release": any(
            is_release_path(path)
            for path in files
        ),
    }


def category_text(values: dict[str, bool]) -> str:
    names = [
        key.removeprefix("categoria_")
        for key, enabled in values.items()
        if enabled
    ]
    return ",".join(names) if names else "sin-categoria"


def full_selection(
    values: dict[str, bool],
    *,
    transicion_release: bool,
    motivo: str,
) -> Selection:
    return Selection(
        **values,
        documentacion=values["categoria_documentacion"],
        pruebas_base=True,
        frontend=True,
        backend=True,
        e2e=True,
        validacion_completa=True,
        transicion_release=transicion_release,
        modo="completo",
        motivo=motivo,
        categorias=category_text(values),
    )


def classify(paths: Iterable[str], event: str) -> Selection:
    """Devuelve categorías y gates con fallback fail-safe."""
    files = normalized(paths)
    values = categories(files)
    transition = any(requires_release_transition(path) for path in files)

    # Main/dispatch nunca heredan optimizaciones del PR.
    if event != "pull_request":
        return full_selection(
            values,
            transicion_release=transition,
            motivo="main-o-dispatch-validan-stack-completo",
        )
    if not files:
        return full_selection(
            values,
            transicion_release=transition,
            motivo="diff-vacio-fail-safe",
        )

    unknown = any(not is_known(path) for path in files)
    if unknown:
        return full_selection(
            values,
            transicion_release=transition,
            motivo="ruta-desconocida-fail-safe",
        )

    workflow_changed = any(path.startswith(WORKFLOW_PREFIX) for path in files)
    ci_critical = any(path in CI_CRITICAL for path in files)
    runtime_changed = (
        values["categoria_frontend"]
        or values["categoria_backend"]
        or values["categoria_migraciones"]
    )
    high_risk = (
        runtime_changed
        or values["categoria_dependencias"]
        or values["categoria_seguridad"]
        or values["categoria_release"]
        or workflow_changed
        or ci_critical
    )
    if high_risk:
        reason_parts: list[str] = []
        if runtime_changed:
            reason_parts.append("runtime")
        if values["categoria_seguridad"]:
            reason_parts.append("seguridad")
        if values["categoria_migraciones"]:
            reason_parts.append("migraciones")
        if values["categoria_dependencias"]:
            reason_parts.append("dependencias")
        if values["categoria_release"]:
            reason_parts.append("release")
        if workflow_changed:
            reason_parts.append("workflow")
        if ci_critical:
            reason_parts.append("ci-critico")

        return full_selection(
            values,
            transicion_release=transition,
            motivo="stack-completo:" + ",".join(dict.fromkeys(reason_parts)),
        )

    pruebas_base = any(
        path.startswith((SCRIPTS_PREFIX, TESTS_PREFIX))
        or path == "pyproject.toml"
        for path in files
    )
    backend = any(path.startswith("tests/php/") for path in files)
    frontend = any(path.startswith("tests/e2e/") for path in files)
    e2e = frontend

    quick = not (pruebas_base or backend or frontend or e2e)
    return Selection(
        **values,
        documentacion=values["categoria_documentacion"],
        pruebas_base=pruebas_base,
        frontend=frontend,
        backend=backend,
        e2e=e2e,
        validacion_completa=False,
        transicion_release=transition,
        modo="rapido" if quick else "selectivo",
        motivo=(
            "documentacion-gobierno-sin-runtime"
            if quick
            else "pruebas-o-herramientas-selectivas"
        ),
        categorias=category_text(values),
    )


def bool_text(value: bool) -> str:
    return "true" if value else "false"


def render_github_output(selection: Selection) -> str:
    """Renderiza outputs de una línea sin exponer paths del diff."""
    lines: list[str] = []
    for key, value in asdict(selection).items():
        if isinstance(value, bool):
            lines.append(f"{key}={bool_text(value)}")
        else:
            lines.append(f"{key}={value}")
    return "\n".join(lines)


def main() -> int:
    parser = argparse.ArgumentParser()
    parser.add_argument("--event", required=True)
    parser.add_argument(
        "--format",
        choices=("json", "github"),
        default="json",
    )
    args = parser.parse_args()

    selection = classify(sys.stdin, args.event)
    if args.format == "github":
        print(render_github_output(selection))
    else:
        print(json.dumps(asdict(selection), sort_keys=True))

    return 0


if __name__ == "__main__":
    raise SystemExit(main())
