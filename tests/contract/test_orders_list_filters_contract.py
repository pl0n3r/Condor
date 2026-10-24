#!/usr/bin/env python3
"""Discovery bridge: CI discovers tests/contract/test_*.py, not root acceptance."""
from __future__ import annotations

import unittest
from pathlib import Path

ROOT = Path(__file__).resolve().parents[2]


class PedidosFiltrosContratoTests(unittest.TestCase):
    def test_acceptance_is_executed_in_ci(self) -> None:
        suite = unittest.defaultTestLoader.discover(
            start_dir=str(ROOT / "tests"),
            pattern="test_orders_list_filters.py",
        )
        result = unittest.TextTestRunner(verbosity=1).run(suite)
        self.assertTrue(result.wasSuccessful(), "El AC-01 PHP debe pasar realmente")


if __name__ == "__main__":
    unittest.main()
