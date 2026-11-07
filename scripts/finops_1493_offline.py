#!/usr/bin/env python3
"""Condor #1823: FINOPS simulator, synthetic input only, no side effects.

This file is not imported by Condor's production/runtime paths. It does not send
alerts, contact providers, configure accounts, or authorize any expenditure.
"""
from __future__ import annotations

import argparse
import json
import re
import sys
from typing import Any

THRESHOLDS = (50, 80, 100)
PROVIDER = re.compile(r"SIM_[A-Z][A-Z0-9_]{0,23}\Z")
CURRENCIES = frozenset(("COP", "USD"))
MAX_INPUT_BYTES = 16_384
MAX_PROVIDERS = 20


class SyntheticInputError(ValueError):
    """The fixture is not provably synthetic or mixes incompatible units."""


def _keys(value: Any, required: set[str]) -> None:
    if not isinstance(value, dict) or set(value) != required:
        raise SyntheticInputError("schema must contain only the documented synthetic fields")


def _integer(value: Any, *, positive: bool = False) -> int:
    # bool is a subclass of int, but it is not a monetary amount.
    if type(value) is not int or value < (1 if positive else 0):
        raise SyntheticInputError("invalid synthetic amount")
    return value


def crossings(previous_minor: int, current_minor: int, limit_minor: int) -> list[int]:
    """Exact threshold crossings, with no floating-point or external services."""
    before = _integer(previous_minor)
    after = _integer(current_minor)
    limit = _integer(limit_minor, positive=True)
    if after < before:
        raise SyntheticInputError("decreasing expenditure is unsupported; fail closed")
    return [p for p in THRESHOLDS if before * 100 < p * limit <= after * 100]


def simulate(fixture: Any) -> dict[str, Any]:
    """Produce candidate alerts only. No provider, recipient or webhook accepted."""
    _keys(fixture, {"synthetic", "lines"})
    if fixture["synthetic"] is not True:
        raise SyntheticInputError("explicit synthetic=true is required")
    lines = fixture["lines"]
    if not isinstance(lines, list) or not 1 <= len(lines) <= MAX_PROVIDERS:
        raise SyntheticInputError("invalid synthetic line count")

    seen: set[str] = set()
    currencies: set[str] = set()
    aggregate_limit = aggregate_before = aggregate_after = 0
    alerts: list[dict[str, Any]] = []
    for line in lines:
        _keys(line, {"provider", "currency", "limit_minor", "previous_minor", "current_minor"})
        provider, currency = line["provider"], line["currency"]
        if type(provider) is not str or PROVIDER.fullmatch(provider) is None or provider in seen:
            raise SyntheticInputError("only unique SIM_ provider identifiers are allowed")
        if type(currency) is not str or currency not in CURRENCIES:
            raise SyntheticInputError("unsupported or missing synthetic currency")
        seen.add(provider)
        currencies.add(currency)
        limit = _integer(line["limit_minor"], positive=True)
        before = _integer(line["previous_minor"])
        after = _integer(line["current_minor"])
        for threshold in crossings(before, after, limit):
            alerts.append({
                "scope": "provider", "provider": provider, "currency": currency,
                "threshold_percent": threshold, "delivery": "NOT_SENT",
            })
        aggregate_limit += limit
        aggregate_before += before
        aggregate_after += after

    # Reject mixed currencies; never invent a FX rate or sum COP with USD.
    if len(currencies) != 1:
        raise SyntheticInputError("multiple currencies require separate simulations")
    currency = next(iter(currencies))
    for threshold in crossings(aggregate_before, aggregate_after, aggregate_limit):
        alerts.append({
            "scope": "aggregate", "provider": "SIM_TOTAL", "currency": currency,
            "threshold_percent": threshold, "delivery": "NOT_SENT",
        })
    return {
        "mode": "SYNTHETIC_ONLY", "budget_authorized": False,
        "operation": "NO_APLICAR_CAMBIOS", "purchasing": "NO_COMPRAR",
        "delivery": "NOT_SENT", "currency": currency,
        "alerts": alerts,
    }


def self_test() -> None:
    assert crossings(4900, 5000, 10000) == [50]
    assert crossings(7900, 8000, 10000) == [80]
    assert crossings(9900, 10000, 10000) == [100]
    assert crossings(10000, 10100, 10000) == []
    assert crossings(0, 10100, 10000) == [50, 80, 100]
    print("FINOPS1493_SELF_TEST_PASS")


def main(argv: list[str] | None = None) -> int:
    parser = argparse.ArgumentParser(description="Offline synthetic FinOps thresholds only")
    group = parser.add_mutually_exclusive_group(required=True)
    group.add_argument("--self-test", action="store_true")
    group.add_argument("--simulate", action="store_true")
    args = parser.parse_args(argv)
    if args.self_test:
        self_test()
        return 0
    try:
        raw = sys.stdin.buffer.read(MAX_INPUT_BYTES + 1)
        if len(raw) > MAX_INPUT_BYTES:
            raise SyntheticInputError("synthetic fixture size exceeded")
        fixture = json.loads(raw.decode("utf-8"))
        result = simulate(fixture)
    except (SyntheticInputError, ValueError, UnicodeError, TypeError) as exc:
        # Never echo fixture input, which might contain private customer data.
        print(f"SYNTHETIC_FIXTURE_REJECTED: {type(exc).__name__}", file=sys.stderr)
        return 2
    print(json.dumps(result, ensure_ascii=False, sort_keys=True))
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
