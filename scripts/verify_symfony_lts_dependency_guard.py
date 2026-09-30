#!/usr/bin/env python3
from __future__ import annotations

import argparse
import hashlib
import json
import re
import subprocess
from pathlib import Path
from typing import Any

SHA_RE = re.compile(r"^[0-9a-f]{40}$")
STABLE_VERSION_RE = re.compile(r"^v?(\d+)\.(\d+)\.(\d+)$")
DEPENDABOT_GROUP_RE = re.compile(r"\bcomposer-(?:minor|patch)\b", re.IGNORECASE)
COMPOSER_JSON_LABEL = "composer.json"

# Symfony packages that intentionally do not follow the Framework release train.
INDEPENDENT_SYMFONY_PACKAGES = frozenset({"symfony/monolog-bundle"})
SPECIAL_BRIDGE = "symfony/psr-http-message-bridge"
RELEVANT_COMPOSER_KEYS = (
    "name", "version", "require", "require-dev", "conflict", "replace", "provide",
    "minimum-stability", "prefer-stable", "repositories", "extra",
)


class GuardError(RuntimeError):
    pass


def _load_json(path: Path, label: str) -> dict[str, Any]:
    try:
        value = json.loads(path.read_text(encoding="utf-8"))
    except (OSError, json.JSONDecodeError) as exc:
        raise GuardError(f"symfony_lts_guard: {label} inválido: {exc}") from exc
    if not isinstance(value, dict):
        raise GuardError(f"symfony_lts_guard: {label} debe ser un objeto JSON.")
    return value


def _parse_json_text(raw: str, label: str) -> dict[str, Any]:
    try:
        value = json.loads(raw)
    except json.JSONDecodeError as exc:
        raise GuardError(f"symfony_lts_guard: {label} inválido: {exc}") from exc
    if not isinstance(value, dict):
        raise GuardError(f"symfony_lts_guard: {label} debe ser un objeto JSON.")
    return value


def _stable_version(value: Any) -> tuple[int, int, int] | None:
    if not isinstance(value, str):
        return None
    match = STABLE_VERSION_RE.fullmatch(value)
    return tuple(map(int, match.groups())) if match else None


def _packages(lock: dict[str, Any]) -> dict[str, dict[str, Any]]:
    index: dict[str, dict[str, Any]] = {}
    for section in ("packages", "packages-dev"):
        rows = lock.get(section, [])
        if not isinstance(rows, list):
            raise GuardError(f"symfony_lts_guard: composer.lock {section} debe ser una lista.")
        for row in rows:
            if not isinstance(row, dict) or not isinstance(row.get("name"), str) or not isinstance(row.get("version"), str):
                raise GuardError(f"symfony_lts_guard: paquete inválido en {section}.")
            name = row["name"]
            if name in index:
                raise GuardError(f"symfony_lts_guard: paquete duplicado en lock: {name}.")
            index[name] = row
    return index


def _independent_symfony(name: str) -> bool:
    return (
        name in INDEPENDENT_SYMFONY_PACKAGES
        or name.startswith("symfony/polyfill-")
        or name.endswith("-contracts")
        or name.startswith("symfony/ux-")
    )


def _bridge_is_74_compatible(package: dict[str, Any]) -> bool:
    requires = package.get("require", {})
    if not isinstance(requires, dict):
        return False
    constraint = requires.get("symfony/http-foundation")
    return isinstance(constraint, str) and re.search(r"(?:^|[| ])\^?7\.4(?:$|[| .])", constraint) is not None


def composer_content_hash(composer: dict[str, Any]) -> str:
    relevant: dict[str, Any] = {key: composer[key] for key in RELEVANT_COMPOSER_KEYS if key in composer}
    config = composer.get("config")
    if isinstance(config, dict) and "platform" in config:
        relevant["config"] = {"platform": config["platform"]}
    relevant = {key: relevant[key] for key in sorted(relevant)}
    encoded = json.dumps(relevant, ensure_ascii=True, separators=(",", ":")).replace("/", r"\/")
    return hashlib.md5(encoded.encode("utf-8"), usedforsecurity=False).hexdigest()


def _direct_symfony_requirements(composer: dict[str, Any], errors: list[str]) -> set[str]:
    direct: set[str] = set()
    for section in ("require", "require-dev"):
        requirements = composer.get(section, {})
        if not isinstance(requirements, dict):
            errors.append(f"{COMPOSER_JSON_LABEL} {section} debe ser un objeto")
            continue
        direct.update(requirements)
        for name, constraint in requirements.items():
            if not isinstance(name, str) or not name.startswith("symfony/"):
                continue
            if _independent_symfony(name) or name == SPECIAL_BRIDGE:
                continue
            if constraint != "7.4.*":
                errors.append(f"{section}:{name} debe permanecer en 7.4.* (actual {constraint!r})")
    return direct


def _transitive_governed(
    packages: dict[str, dict[str, Any]],
    direct: set[str],
    errors: list[str],
) -> list[str]:
    transitive: list[str] = []
    for name, package in packages.items():
        if not name.startswith("symfony/") or _independent_symfony(name):
            continue
        if name == SPECIAL_BRIDGE:
            version = _stable_version(package.get("version"))
            if version is None or not _bridge_is_74_compatible(package):
                errors.append(
                    f"{name} solo se exceptúa con versión estable y compatibilidad explícita con http-foundation ^7.4"
                )
            continue
        version = _stable_version(package.get("version"))
        if version is None or version[:2] != (7, 4):
            errors.append(f"{name} debe permanecer en Symfony 7.4 LTS (actual {package.get('version')!r})")
            continue
        if name not in direct:
            transitive.append(name)
    return transitive


def _validate_conflicts(composer: dict[str, Any], transitive: list[str], errors: list[str]) -> None:
    conflicts = composer.get("conflict", {})
    if not isinstance(conflicts, dict):
        errors.append(f"{COMPOSER_JSON_LABEL} conflict debe ser un objeto")
        return
    for name in sorted(transitive):
        if conflicts.get(name) != ">=8":
            errors.append(f"{COMPOSER_JSON_LABEL} debe declarar conflict {name}: >=8")


def _validate_content_hash(composer: dict[str, Any], lock: dict[str, Any], errors: list[str]) -> None:
    if lock.get("content-hash") != composer_content_hash(composer):
        errors.append(f"composer.lock no está sincronizado con {COMPOSER_JSON_LABEL} (content-hash stale)")


def static_errors(composer: dict[str, Any], lock: dict[str, Any]) -> list[str]:
    errors: list[str] = []
    packages = _packages(lock)
    direct = _direct_symfony_requirements(composer, errors)
    transitive = _transitive_governed(packages, direct, errors)
    _validate_conflicts(composer, transitive, errors)
    _validate_content_hash(composer, lock, errors)
    return sorted(set(errors))


def major_drifts(base_lock: dict[str, Any], candidate_lock: dict[str, Any]) -> list[tuple[str, str, str]]:
    base = _packages(base_lock)
    candidate = _packages(candidate_lock)
    drift: list[tuple[str, str, str]] = []
    for name in sorted(base.keys() & candidate.keys()):
        before = _stable_version(base[name].get("version"))
        after = _stable_version(candidate[name].get("version"))
        if before is None or after is None:
            continue
        if after[0] > before[0]:
            drift.append((name, base[name]["version"], candidate[name]["version"]))
    return drift


def validate_documents(
    composer: dict[str, Any],
    candidate_lock: dict[str, Any],
    *,
    base_lock: dict[str, Any] | None = None,
    pr_title: str = "",
) -> None:
    errors = static_errors(composer, candidate_lock)
    if DEPENDABOT_GROUP_RE.search(pr_title):
        if base_lock is None:
            errors.append("PR composer-minor/patch requiere lock base verificable")
        else:
            for name, before, after in major_drifts(base_lock, candidate_lock):
                errors.append(f"major drift no planificado en composer-minor/patch: {name} {before} -> {after}")
    if errors:
        raise GuardError("symfony_lts_guard: " + "; ".join(sorted(errors)))


def _git_base_lock(repo_root: Path, expected_base_sha: str) -> dict[str, Any]:
    normalized = expected_base_sha.lower()
    if SHA_RE.fullmatch(normalized) is None:
        raise GuardError("symfony_lts_guard: base SHA inválido.")

    parent = subprocess.run(
        ["git", "rev-parse", "HEAD^1"],
        cwd=repo_root,
        text=True,
        capture_output=True,
        check=False,
    )
    if parent.returncode != 0 or parent.stdout.strip().lower() != normalized:
        raise GuardError("symfony_lts_guard: el primer padre del merge no coincide con el base exacto del PR.")

    result = subprocess.run(
        ["git", "show", "HEAD^1:composer.lock"],
        cwd=repo_root,
        text=True,
        capture_output=True,
        check=False,
    )
    if result.returncode != 0:
        raise GuardError("symfony_lts_guard: no fue posible leer composer.lock del base exacto.")
    return _parse_json_text(result.stdout, "composer.lock base")


def _inside(root: Path, raw: str, label: str) -> Path:
    path = (root / raw).resolve() if not Path(raw).is_absolute() else Path(raw).resolve()
    if path != root and root not in path.parents:
        raise GuardError(f"symfony_lts_guard: {label} fuera del repositorio.")
    return path


def main() -> int:
    parser = argparse.ArgumentParser()
    parser.add_argument("--repo-root", default=".")
    parser.add_argument("--composer-json", default=COMPOSER_JSON_LABEL)
    parser.add_argument("--candidate-lock", default="composer.lock")
    parser.add_argument("--base-sha")
    parser.add_argument("--pr-title", default="")
    args = parser.parse_args()

    try:
        root = Path(args.repo_root).resolve()
        composer_path = _inside(root, args.composer_json, COMPOSER_JSON_LABEL)
        lock_path = _inside(root, args.candidate_lock, "composer.lock")
        composer = _load_json(composer_path, COMPOSER_JSON_LABEL)
        candidate = _load_json(lock_path, "composer.lock candidato")
        base = None
        if DEPENDABOT_GROUP_RE.search(args.pr_title):
            if not args.base_sha:
                raise GuardError("symfony_lts_guard: PR composer-minor/patch requiere --base-sha.")
            base = _git_base_lock(root, args.base_sha)
        validate_documents(composer, candidate, base_lock=base, pr_title=args.pr_title)
    except GuardError as exc:
        print(f"::error::{exc}")
        return 1

    mode = "delta" if base is not None else "static"
    print(f"symfony_lts_guard: OK mode={mode} Symfony=7.4")
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
