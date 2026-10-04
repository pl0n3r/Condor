#!/usr/bin/env python3
"""Regression: Factory policy caller least-privilege envelope."""

from __future__ import annotations

import re
import unittest
from pathlib import Path

ROOT = Path(__file__).resolve().parents[1]
CALLER = ROOT / ".github/workflows/politica.yml"


def _permission_block(text: str, header: str, indent: int) -> dict[str, str]:
    prefix = " " * indent + header
    lines = text.splitlines()
    try:
        start = lines.index(prefix) + 1
    except ValueError as exc:
        raise AssertionError(f"Falta bloque {header}") from exc

    result: dict[str, str] = {}
    for line in lines[start:]:
        if not line.strip():
            continue
        current_indent = len(line) - len(line.lstrip(" "))
        if current_indent <= indent:
            break
        if current_indent != indent + 2 or ":" not in line:
            continue
        key, value = line.strip().split(":", 1)
        result[key.strip()] = value.strip()
    return result


class PolicyCallerPermissionsTests(unittest.TestCase):
    def _text(self) -> str:
        return CALLER.read_text(encoding="utf-8")

    def _job_permissions(self) -> dict[str, str]:
        text = self._text()
        job = text.split("  politica:\n", 1)[1]
        match = re.search(
            r"^    permissions:\n(?P<body>(?:^      [a-z-]+: [a-z]+\n)+)",
            job,
            flags=re.MULTILINE,
        )
        self.assertIsNotNone(match)
        assert match is not None
        return {
            key: value
            for key, value in (
                line.strip().split(": ", 1)
                for line in match.group("body").splitlines()
            )
        }

    def test_policy_caller_keeps_legacy_minimal_permissions(self) -> None:
        expected = {
            "contents": "read",
            "pull-requests": "read",
        }
        text = self._text()
        self.assertEqual(expected, _permission_block(text, "permissions:", 0))
        self.assertEqual(expected, self._job_permissions())

    def test_policy_caller_does_not_expand_permissions(self) -> None:
        text = self._text()
        self.assertNotIn("issues: write", text)
        self.assertNotIn("checks: read", text)
        write_lines = [
            line.strip()
            for line in text.splitlines()
            if line.strip().endswith(": write")
        ]
        self.assertEqual([], write_lines)

    def test_policy_caller_keeps_factory_v1_and_existing_contract(self) -> None:
        text = self._text()
        self.assertIn(
            "uses: pl0n3r/factory/.github/workflows/politica.yml@v1",
            text,
        )
        self.assertIn("types: [opened, synchronize, reopened, edited]", text)
        self.assertIn("pr_number: ${{ github.event.pull_request.number }}", text)
        self.assertEqual(1, text.count("uses: "))


if __name__ == "__main__":
    unittest.main()
