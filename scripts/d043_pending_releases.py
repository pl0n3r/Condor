#!/usr/bin/env python3
"""Reduce comentarios canónicos del roadmap a releases D-043 aún pendientes."""

from __future__ import annotations

import argparse
import json
import re
import sys
from typing import Any, Iterable

BOT_LOGIN = "github-actions[bot]"
VERSION_PATTERN = r"(?P<version>\d+\.\d+\.\d+)"
SHA_PATTERN = r"(?P<sha>[0-9a-f]{40})"
DEPLOY_PATTERN = re.compile(
    rf"^🚧 DEPLOY_OBSERVED automático: producción sirve V {VERSION_PATTERN} "
    rf"\({SHA_PATTERN}\),"
)
VALIDATED_PATTERN = re.compile(
    rf"^✅ VALIDATED_IN_PRODUCTION automático: producción sirve V {VERSION_PATTERN} "
    rf"\({SHA_PATTERN}\)(?:\s|$)"
)


class PendingReleaseError(ValueError):
    """Entrada estructural o identidad de release incompatible con D-043."""


def version_key(version: str) -> tuple[int, int, int]:
    """Devuelve una clave numérica estable para ordenar X.Y.Z."""
    major, minor, patch = map(int, version.split("."))
    return major, minor, patch


def flatten_comments(payload: object) -> list[dict[str, Any]]:
    """Acepta la respuesta plana o el --slurp paginado de gh api."""
    if not isinstance(payload, list):
        raise PendingReleaseError("Los comentarios deben recibirse como una lista JSON.")

    comments: list[dict[str, Any]] = []
    for item in payload:
        if isinstance(item, dict):
            comments.append(item)
            continue
        if isinstance(item, list):
            if not all(isinstance(comment, dict) for comment in item):
                raise PendingReleaseError("Una página contiene comentarios inválidos.")
            comments.extend(item)
            continue
        raise PendingReleaseError("La lista contiene una entrada que no es comentario.")
    return comments


def canonical_event(
    comment: dict[str, Any],
) -> tuple[str, str, str] | None:
    """Extrae solo eventos bot con gramática canónica de observación."""
    user = comment.get("user")
    if not isinstance(user, dict) or user.get("login") != BOT_LOGIN:
        return None

    body = comment.get("body")
    if not isinstance(body, str):
        return None

    for state, pattern in (
        ("pending", DEPLOY_PATTERN),
        ("validated", VALIDATED_PATTERN),
    ):
        match = pattern.match(body)
        if match is not None:
            return state, match.group("version"), match.group("sha")
    return None


def minimum_version_key(minimum_version: str | None) -> tuple[int, int, int] | None:
    """Valida el límite inferior explícito del horizonte D-043."""
    if minimum_version is None:
        return None
    if re.fullmatch(r"\d+\.\d+\.\d+", minimum_version) is None:
        raise PendingReleaseError("La versión mínima D-043 es inválida.")
    return version_key(minimum_version)


def reduce_pending_releases(
    comments: Iterable[dict[str, Any]],
    *,
    minimum_version: str | None = None,
) -> list[dict[str, str]]:
    """Reduce eventos exactos sin inferir cobertura entre versiones."""
    lower_bound = minimum_version_key(minimum_version)
    version_to_sha: dict[str, str] = {}
    sha_to_version: dict[str, str] = {}
    pending: set[tuple[str, str]] = set()
    validated: set[tuple[str, str]] = set()

    for comment in comments:
        event = canonical_event(comment)
        if event is None:
            continue

        state, version, sha = event
        if lower_bound is not None and version_key(version) < lower_bound:
            continue

        known_sha = version_to_sha.get(version)
        if known_sha is not None and known_sha != sha:
            raise PendingReleaseError(
                "Una versión canónica aparece asociada a SHAs distintos."
            )
        known_version = sha_to_version.get(sha)
        if known_version is not None and known_version != version:
            raise PendingReleaseError(
                "Un SHA canónico aparece asociado a versiones distintas."
            )

        version_to_sha[version] = sha
        sha_to_version[sha] = version
        identity = (version, sha)

        if state == "validated":
            validated.add(identity)
            pending.discard(identity)
        elif identity not in validated:
            pending.add(identity)

    return [
        {"version": version, "sha": sha}
        for version, sha in sorted(
            pending,
            key=lambda item: (version_key(item[0]), item[1]),
        )
    ]


def main(argv: list[str] | None = None) -> int:
    """CLI stdin→stdout sin red ni mutaciones."""
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument("--minimum-version")
    args = parser.parse_args(argv)

    try:
        payload = json.load(sys.stdin)
        comments = flatten_comments(payload)
        pending = reduce_pending_releases(
            comments,
            minimum_version=args.minimum_version,
        )
    except (ValueError, PendingReleaseError) as error:
        print(f"error: {error}", file=sys.stderr)
        return 2

    print(json.dumps(pending, ensure_ascii=False, sort_keys=True))
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
