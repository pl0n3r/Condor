"""CI-discovered real acceptance for the isolated reports CSV panel (#727).

The contracts job does not install Node dependencies.  Install only the
repository's locked dependencies without lifecycle scripts before importing
the real TypeScript-backed TestCase. Fail closed if installation fails.
Integration into the shipped admin remains gated by #726 V3 and D-043.
"""

import subprocess
from pathlib import Path

ROOT = Path(__file__).resolve().parents[1]
TYPESCRIPT = ROOT / "node_modules" / "typescript" / "lib" / "typescript.js"

if not TYPESCRIPT.is_file():
    install = subprocess.run(
        ["npm", "ci", "--ignore-scripts", "--no-audit", "--no-fund", "--prefer-offline"],
        cwd=ROOT, text=True, capture_output=True, timeout=180,
        check=False,
    )
    if install.returncode != 0 or not TYPESCRIPT.is_file():
        raise RuntimeError(
            "Cannot run real TSX acceptance without locked TypeScript: "
            + install.stderr[-3500:]
        )

from test_reports_admin_ui import ReportesUiAdminTests

__all__ = ["ReportesUiAdminTests"]
