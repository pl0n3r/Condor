"""Run the real #727 TypeScript acceptance without polluting the CI checkout.

The contracts job does not run npm ci. Install exactly the committed lockfile
inside a temporary directory, not the repository: the documentation validator
traverses the checkout and should never scan npm's third-party README files.
No lifecycle scripts, skips, mocks, or changes to the shipped application.
"""

import os
import shutil
import subprocess
import tempfile
from pathlib import Path

ROOT = Path(__file__).resolve().parents[1]
TYPESCRIPT = ROOT / "node_modules" / "typescript" / "lib" / "typescript.js"

_NODE_DEPENDENCIES = None
if not TYPESCRIPT.is_file():
    _NODE_DEPENDENCIES = tempfile.TemporaryDirectory(prefix="condor-reports-acceptance-")
    isolated = Path(_NODE_DEPENDENCIES.name)
    for manifest in ("package.json", "package-lock.json"):
        shutil.copyfile(ROOT / manifest, isolated / manifest)
    install = subprocess.run(
        [
            "npm", "ci", "--prefix", str(isolated),
            "--ignore-scripts", "--no-audit", "--no-fund", "--prefer-offline",
        ],
        cwd=ROOT, text=True, capture_output=True, timeout=180,
        check=False,
    )
    if install.returncode != 0 or not (isolated / "node_modules" / "typescript" / "lib" / "typescript.js").is_file():
        raise RuntimeError(
            "Cannot run real TSX acceptance with isolated locked TypeScript: "
            + install.stderr[-3500:]
        )
    # Child Node processes in the imported test discover TypeScript via NODE_PATH.
    os.environ["NODE_PATH"] = str(isolated / "node_modules") + (
        os.pathsep + os.environ["NODE_PATH"] if os.environ.get("NODE_PATH") else ""
    )

from test_reports_admin_ui import ReportesUiAdminTests

__all__ = ["ReportesUiAdminTests"]
