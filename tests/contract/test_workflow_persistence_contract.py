#!/usr/bin/env python3
"""Vincula la aceptación #856 al descubrimiento canónico de tests/contract."""
from __future__ import annotations

import subprocess
import sys
import unittest
from pathlib import Path

ROOT = Path(__file__).resolve().parents[2]


class WorkflowPersistenceCiContractTests(unittest.TestCase):
    def test_workflow_persistence_acceptance(self) -> None:
        result = subprocess.run(
            [sys.executable, "-m", "unittest", "discover", "-s", "tests",
             "-p", "test_workflow_persistence.py", "-v"],
            cwd=ROOT, capture_output=True, text=True, check=False, timeout=90,
        )
        self.assertEqual(0, result.returncode, result.stdout + result.stderr)
        self.assertIn("test_acceptance", result.stdout + result.stderr)


if __name__ == "__main__":
    unittest.main()
