"""Run real synthetic #725 TypeScript acceptance from the canonical Python CI.

The contract job lacks npm dependencies: install the lockfile in an isolated
temporary directory, as for reports acceptance; never change the checkout.
"""
import os
import shutil
import subprocess
import tempfile
from pathlib import Path

ROOT = Path(__file__).resolve().parents[1]
_DEPENDENCIES = None
if not (ROOT / "node_modules/typescript/lib/typescript.js").is_file():
    _DEPENDENCIES = tempfile.TemporaryDirectory(prefix="condor-employees-acceptance-")
    isolated = Path(_DEPENDENCIES.name)
    for manifest in ("package.json", "package-lock.json"):
        shutil.copyfile(ROOT / manifest, isolated / manifest)
    done = subprocess.run(
        ["npm", "ci", "--prefix", str(isolated), "--ignore-scripts",
         "--no-audit", "--no-fund", "--prefer-offline"],
        cwd=ROOT, text=True, capture_output=True, timeout=180, check=False,
    )
    if done.returncode or not (isolated / "node_modules/typescript/lib/typescript.js").is_file():
        raise RuntimeError("TypeScript dependency unavailable: " + done.stderr[-2500:])
    os.environ["NODE_PATH"] = str(isolated / "node_modules") + (
        os.pathsep + os.environ["NODE_PATH"] if os.environ.get("NODE_PATH") else ""
    )

from test_employees_admin_ui import EmpleadosUiAdminTests

__all__ = ["EmpleadosUiAdminTests"]
