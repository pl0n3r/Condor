#!/usr/bin/env python3
"""#1489 AC-02: la puerta de release falla ante un GAP-P0 sin Issue o runbook."""

from __future__ import annotations

import re
import shutil
import subprocess
import unittest
from pathlib import Path

ROOT = Path(__file__).resolve().parents[1]
PHPUNIT = ROOT / "vendor/bin/simple-phpunit"
REGISTRY = ROOT / "docs/architecture/capability-registry.md"


class OperationalReleaseGateTests(unittest.TestCase):
    def test_acceptance(self) -> None:
        rows = re.findall(r"^\|\s*(GAP-P0-[\w-]+)\s*\|.*\|\s*(#\d+)\s*\|\s*(docs/runbooks/[\w-]+\.md)\s*\|$",
                          REGISTRY.read_text(encoding="utf-8"), re.M)
        self.assertGreaterEqual(len(rows), 1, "El registro debe listar GAP-P0")
        for gap, _issue, runbook in rows:
            self.assertTrue((ROOT / runbook).is_file(), f"{gap}: runbook ausente {runbook}")
        if shutil.which("php") is None or not PHPUNIT.is_file():
            self.skipTest("PHP/Composer no disponible: la CI ejecuta PHPUnit.")
        result = subprocess.run(
            ["php", str(PHPUNIT), "tests/php/Application/Observability/CapabilityReleaseGateTest.php"],
            cwd=ROOT, text=True, capture_output=True, check=False,
        )
        self.assertEqual(result.returncode, 0, result.stdout[-2000:])


if __name__ == "__main__":
    unittest.main()
