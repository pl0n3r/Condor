#!/usr/bin/env python3
"""#1489 AC-03: runbooks con RPO/RTO como decisión pendiente, sin valores inventados."""

from __future__ import annotations

import re
import unittest
from pathlib import Path

ROOT = Path(__file__).resolve().parents[1]
RUNBOOKS = ("conciliacion-ledger.md", "recuperacion-tenant.md")


class OperationalRunbooksTests(unittest.TestCase):
    def test_acceptance(self) -> None:
        for name in RUNBOOKS:
            text = (ROOT / "docs/runbooks" / name).read_text(encoding="utf-8")
            for objective in ("RPO", "RTO"):
                match = re.search(rf"^- {objective}: (.+)$", text, re.M)
                self.assertIsNotNone(match, f"{name}: falta {objective}")
                self.assertIn("decisión pendiente", match.group(1))
                self.assertIsNone(
                    re.search(r"\d", match.group(1)), f"{name}: {objective} no puede llevar cifras inventadas"
                )


if __name__ == "__main__":
    unittest.main()
