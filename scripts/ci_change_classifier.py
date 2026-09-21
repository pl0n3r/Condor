#!/usr/bin/env python3
"""Clasifica cambios del repositorio para ejecutar solo los gates necesarios."""

from __future__ import annotations

import argparse
import json
import sys
from dataclasses import asdict, dataclass
from typing import Iterable


@dataclass(frozen=True)
class Selection:
    documentacion: bool
    github: bool
    pruebas_base: bool
    backend: bool
    e2e: bool
    transicion_release: bool


CANONICAL_DOCS = {
    "AGENTES.md",
    "ESPECIFICACIONES.md",
    "GLOSARIO.md",
    "README.md",
    "ROADMAP.md",
}

GITHUB_PREFIX = ".github/"
DOCS_PREFIX = "docs/"
TESTS_PREFIX = TESTS_PREFIX
SRC_PREFIX = SRC_PREFIX
CONFIG_PREFIX = "config/"
MIGRATIONS_PREFIX = "migrations/"
TEMPLATES_PREFIX = "templates/"
PUBLIC_PREFIX = "public/"
SCRIPTS_PREFIX = "scripts/"
PHPUNIT_CONFIG = "phpunit.xml.dist"
PACKAGE_JSON = "package.json"
PACKAGE_LOCK = "package-lock.json"
PLAYWRIGHT_CONFIG = "playwright.config.mjs"

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

COMPOSER_FILES = {"composer.json", "composer.lock"}
BACKEND_CONTROL_FILES = COMPOSER_FILES | {PHPUNIT_CONFIG}
E2E_CONTROL_FILES = COMPOSER_FILES | {
    PACKAGE_JSON,
    PACKAGE_LOCK,
    PLAYWRIGHT_CONFIG,
}

CI_CRITICAL = {
    ".github/workflows/ci.yml",
    ".github/workflows/ci-throughput-telemetry.yml",
    ".github/workflows/observar-release.yml",
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
    PACKAGE_JSON,
    PACKAGE_LOCK,
    PLAYWRIGHT_CONFIG,
} | COMPOSER_FILES


def normalized(paths: Iterable[str]) -> list[str]:
    """Normaliza la lista de paths sin aceptar entradas vacías."""
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
    return False


def classify(paths: Iterable[str], event: str) -> Selection:
    """Devuelve la selección fail-safe de gates para un conjunto de cambios."""
    files = normalized(paths)

    documentacion = any(
        path.endswith(".md") or path.startswith(DOCS_PREFIX)
        for path in files
    )
    github = any(
        path.startswith(GITHUB_PREFIX) or path in CANONICAL_DOCS
        for path in files
    )
    transicion_release = any(
        requires_release_transition(path)
        for path in files
    )

    # Push a main y dispatch manual validan el stack completo. Un diff vacío
    # también cae al modo completo para nunca saltarse gates por falta de datos.
    if event != "pull_request" or not files:
        return Selection(
            documentacion,
            github,
            True,
            True,
            True,
            transicion_release,
        )

    critical = any(path in CI_CRITICAL for path in files)
    if critical:
        return Selection(
            documentacion,
            github,
            True,
            True,
            True,
            transicion_release,
        )

    pruebas_base = any(
        starts(path, (SCRIPTS_PREFIX, TESTS_PREFIX, GITHUB_PREFIX))
        or path in {"pyproject.toml", PHPUNIT_CONFIG}
        for path in files
    )

    backend = any(
        starts(
            path,
            (
                SRC_PREFIX,
                CONFIG_PREFIX,
                MIGRATIONS_PREFIX,
                TEMPLATES_PREFIX,
                "tests/php/",
            ),
        )
        or path.startswith(PUBLIC_PREFIX) and path.endswith(".php")
        or path in BACKEND_CONTROL_FILES
        for path in files
    )

    e2e = any(
        starts(
            path,
            (
                SRC_PREFIX,
                "config/",
                "migrations/",
                "templates/",
                "frontend/",
                "tests/e2e/",
            ),
        )
        or path.startswith(PUBLIC_PREFIX)
        or path in E2E_CONTROL_FILES
        for path in files
    )

    # Cambios funcionales siempre conservan contratos/integraciones.
    if backend or e2e:
        pruebas_base = True

    known = all(
        path.endswith(".md")
        or starts(
            path,
            (
                "docs/",
                GITHUB_PREFIX,
                "scripts/",
                TESTS_PREFIX,
                SRC_PREFIX,
                CONFIG_PREFIX,
                MIGRATIONS_PREFIX,
                TEMPLATES_PREFIX,
                "frontend/",
                PUBLIC_PREFIX,
            ),
        )
        or path in {
            "pyproject.toml",
            PHPUNIT_CONFIG,
            PACKAGE_JSON,
            PACKAGE_LOCK,
            PLAYWRIGHT_CONFIG,
            "composer.json",
            "composer.lock",
        }
        for path in files
    )
    if not known:
        return Selection(
            documentacion,
            github,
            True,
            True,
            True,
            transicion_release,
        )

    return Selection(
        documentacion,
        github,
        pruebas_base,
        backend,
        e2e,
        transicion_release,
    )


def bool_text(value: bool) -> str:
    return "true" if value else "false"


def render_github_output(selection: Selection) -> str:
    """Renderiza outputs sin escribir rutas proporcionadas por el caller."""
    return "\n".join(
        f"{key}={bool_text(bool(value))}"
        for key, value in asdict(selection).items()
    )


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
