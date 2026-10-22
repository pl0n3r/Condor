#!/usr/bin/env python3
"""Resolver la fase D-043 exclusivamente del inventario local datos.yml.

No consulta Issues ni certifica una release; cualquier ambigüedad falla cerrada.
"""

from __future__ import annotations

import argparse
import json
from pathlib import Path


PHASE_UNKNOWN = "desconocida"
VALID_PHASES = frozenset({"construccion", "live"})


def _unique_object(pairs: list[tuple[str, object]]) -> dict[str, object]:
    result: dict[str, object] = {}
    for key, value in pairs:
        if key in result:
            raise ValueError("Clave duplicada en datos.yml")
        result[key] = value
    return result


def resolve_phase(source: Path, repository: str) -> str:
    """Devuelve construccion/live sólo con identidad y esquema verificables."""
    if repository != "pl0n3r/Condor":
        return PHASE_UNKNOWN
    try:
        # El formato canónico actual de datos.yml es JSON válido.
        # No interpretar YAML alternativo/ambiguo de manera permisiva.
        raw = source.read_text(encoding="utf-8")
        if len(raw) > 1_000_000:
            return PHASE_UNKNOWN
        payload = json.loads(raw, object_pairs_hook=_unique_object)
    except (OSError, UnicodeError, ValueError):
        return PHASE_UNKNOWN
    if not isinstance(payload, dict):
        return PHASE_UNKNOWN
    if type(payload.get("version")) is not int or payload["version"] != 1:
        return PHASE_UNKNOWN
    if payload.get("project") != repository:
        return PHASE_UNKNOWN
    phase = payload.get("phase")
    return phase if isinstance(phase, str) and phase in VALID_PHASES else PHASE_UNKNOWN


def main() -> int:
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument("--source", type=Path, required=True)
    parser.add_argument("--repository", required=True)
    args = parser.parse_args()
    print(resolve_phase(args.source, args.repository))
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
