#!/usr/bin/env python3
"""The canonical integration job executes Condor #855 AC-01, not only lint."""
from __future__ import annotations

import subprocess
import sys
import unittest
from pathlib import Path

ROOT = Path(__file__).resolve().parents[2]


class WorkflowCoreCiContractTests(unittest.TestCase):
    def test_workflow_core_acceptance(self) -> None:
        run = subprocess.run(
            [sys.executable, "-m", "unittest", "discover", "-s", "tests", "-p", "test_workflow_core.py", "-v"],
            cwd=ROOT, capture_output=True, text=True, timeout=45, check=False,
        )
        self.assertEqual(0, run.returncode, run.stdout + run.stderr)
        self.assertIn("test_acceptance", run.stdout + run.stderr)


if __name__ == "__main__":
    unittest.main()
