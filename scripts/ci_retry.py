#!/usr/bin/env python3
"""Reintenta únicamente fallos externos con señal transitoria verificable."""

from __future__ import annotations

import argparse
import re
import subprocess
import sys
import time
from collections.abc import Sequence


MAX_ATTEMPTS = 5
MAX_DELAY_SECONDS = 30.0
TRANSIENT_EXIT_CODES = {75}
TRANSIENT_PATTERNS = (
    re.compile(r"\btimed?\s*out\b", re.IGNORECASE),
    re.compile(r"\btimeout\b", re.IGNORECASE),
    re.compile(r"connection reset(?: by peer)?", re.IGNORECASE),
    re.compile(r"\beconnreset\b", re.IGNORECASE),
    re.compile(r"\betimedout\b", re.IGNORECASE),
    re.compile(r"\beconnrefused\b", re.IGNORECASE),
    re.compile(r"\bHTTP(?:/\S+)?\s+(?:429|502|503|504)\b", re.IGNORECASE),
    re.compile(r"\b(?:status|response)(?: code)?[: ]+(?:429|502|503|504)\b", re.IGNORECASE),
    re.compile(r"socket hang up", re.IGNORECASE),
)


def is_transient_failure(returncode: int, output: str) -> bool:
    """Acepta solo códigos/señales acotadas de indisponibilidad temporal."""
    if returncode in TRANSIENT_EXIT_CODES:
        return True
    return any(pattern.search(output) is not None for pattern in TRANSIENT_PATTERNS)


def run_with_retry(
    command: Sequence[str],
    *,
    attempts: int,
    base_delay: float,
    label: str,
) -> int:
    """Ejecuta con backoff solo cuando el fallo observado es transitorio."""
    if not command:
        raise ValueError("El comando no puede estar vacío.")
    if attempts < 1 or attempts > MAX_ATTEMPTS:
        raise ValueError(f"attempts debe estar entre 1 y {MAX_ATTEMPTS}.")
    if base_delay < 0 or base_delay > MAX_DELAY_SECONDS:
        raise ValueError(
            f"base_delay debe estar entre 0 y {MAX_DELAY_SECONDS} segundos."
        )

    for attempt in range(1, attempts + 1):
        result = subprocess.run(
            list(command),
            check=False,
            stdout=subprocess.PIPE,
            stderr=subprocess.STDOUT,
            text=True,
            errors="replace",
        )
        output = result.stdout or ""
        if output:
            print(output, end="" if output.endswith("\n") else "\n")

        if result.returncode == 0:
            return 0

        if not is_transient_failure(result.returncode, output):
            print(
                f"::notice title=CI sin reintento::{label} falló sin señal "
                "transitoria verificable; se conserva el fallo original."
            )
            return result.returncode

        if attempt == attempts:
            return result.returncode

        delay = min(
            MAX_DELAY_SECONDS,
            base_delay * (2 ** (attempt - 1)),
        )
        print(
            f"::warning title=Autocuración CI::{label} presentó un fallo "
            f"transitorio (intento {attempt}/{attempts}); reintento en {delay:g}s."
        )
        time.sleep(delay)

    return 1


def main() -> int:
    parser = argparse.ArgumentParser()
    parser.add_argument("--attempts", type=int, default=3)
    parser.add_argument("--base-delay", type=float, default=2.0)
    parser.add_argument("--label", default="Operación externa")
    parser.add_argument("command", nargs=argparse.REMAINDER)
    args = parser.parse_args()

    command = args.command
    if command and command[0] == "--":
        command = command[1:]

    try:
        return run_with_retry(
            command,
            attempts=args.attempts,
            base_delay=args.base_delay,
            label=args.label,
        )
    except ValueError as exc:
        print(f"ci_retry: {exc}", file=sys.stderr)
        return 2


if __name__ == "__main__":
    raise SystemExit(main())
