#!/usr/bin/env python3
"""#1489 AC-01: snapshot operativo owner-only, aislado por tenant y redactado."""

from __future__ import annotations

import shutil
import subprocess
import unittest
from pathlib import Path

ROOT = Path(__file__).resolve().parents[1]
PHPUNIT = ROOT / "vendor/bin/simple-phpunit"
TEST = "tests/php/Http/PlatformOperationalSnapshotControllerTest.php"


class OperationalSnapshotEndpointTests(unittest.TestCase):
    def test_acceptance(self) -> None:
        controller = (ROOT / "src/Http/Controller/PlatformOperationalSnapshotController.php").read_text(encoding="utf-8")
        self.assertIn("/adminpl0n3r/api/tenants/{tenantId}/operational-snapshot", controller)
        self.assertIn("$this->platformOwner();", controller)
        self.assertIn("no-store", controller)
        if shutil.which("php") is None or not PHPUNIT.is_file():
            self.skipTest("PHP/Composer no disponible: la CI ejecuta PHPUnit con MariaDB.")
        result = subprocess.run(
            ["php", str(PHPUNIT), TEST], cwd=ROOT, text=True, capture_output=True, check=False
        )
        self.assertEqual(result.returncode, 0, result.stdout[-2000:] + result.stderr[-1000:])


if __name__ == "__main__":
    unittest.main()
