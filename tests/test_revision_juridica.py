"""Tests of exact legal copies, drift detection and least-privilege sync."""
from __future__ import annotations
import shutil
import subprocess
import sys
import tempfile
import unittest
from pathlib import Path

from scripts.generar_revision_juridica import ROOT, SOURCES, render_package

class RevisionJuridicaTests(unittest.TestCase):
    def test_package_and_workflow(self):
        dest = ROOT / "revision-juridica"
        self.assertTrue(dest.is_dir())
        expected_names = {name for _, name, _ in SOURCES}
        expected_names |= {"00-LEEME.md", "paquete-completo.md"}
        self.assertEqual({p.name for p in dest.iterdir() if p.is_file()},
                         expected_names)
        sources = {}
        for source, name, _ in SOURCES:
            raw = (ROOT / source).read_bytes()
            sources[source] = raw
            self.assertEqual((dest / name).read_bytes(), raw)
        self.assertEqual((dest / "paquete-completo.md").read_bytes(),
                         render_package(sources))
        readme = (dest / "00-LEEME.md").read_text(encoding="utf-8")
        self.assertRegex(readme.splitlines()[0],
                         r"^Última actualización automática: \d{4}-\d{2}-\d{2} "
                         r"· SHA de origen: [0-9a-f]{40}$")
        self.assertIn("documented_not_legally_approved", readme)
        self.assertIn("- [ ] Razón social", readme)
        self.assertIn("- [ ] Dirección", readme)
        self.assertIn("sentry", readme.lower())
        self.assertIn("Hostinger", readme)
        workflow = (ROOT / ".github/workflows/revision-juridica-sync.yml").read_text(
            encoding="utf-8"
        )
        for fragment in ("push:", "schedule:", "concurrency:",
                         "contents: read", "issues: write",
                         "gh issue list", "revision-juridica-desfase",
                         "factory-acceptance", "RevisionJuridicaTests::test_package_and_workflow",
                         "python scripts/generar_revision_juridica.py --check"):
            self.assertIn(fragment, workflow)
        for forbidden in ("pull_request_target", "contents: write", "git push"):
            self.assertNotIn(forbidden, workflow)
        result = subprocess.run(
            [sys.executable, str(ROOT / "scripts/generar_revision_juridica.py"),
             "--check"], cwd=ROOT, text=True, capture_output=True, check=False
        )
        self.assertEqual(result.returncode, 0, result.stderr)

    def test_check_detects_drift_without_writing(self):
        with tempfile.TemporaryDirectory() as tmp:
            root = Path(tmp)
            files = ["scripts/generar_revision_juridica.py",
                     "datos.yml", "config/version.php"]
            files += [src for src, _, _ in SOURCES]
            files += ["revision-juridica/" + name for _, name, _ in SOURCES]
            files += ["revision-juridica/00-LEEME.md",
                      "revision-juridica/paquete-completo.md"]
            for path in files:
                dest = root / path
                dest.parent.mkdir(parents=True, exist_ok=True)
                shutil.copyfile(ROOT / path, dest)
            source = root / SOURCES[0][0]
            source.write_bytes(source.read_bytes() + b"\nTEST_DRIFT\n")
            old = (root / "revision-juridica" / SOURCES[0][1]).read_bytes()
            pkg = (root / "revision-juridica/paquete-completo.md").read_bytes()
            check = subprocess.run(
                [sys.executable, str(root / "scripts/generar_revision_juridica.py"),
                 "--check"], cwd=root, capture_output=True, text=True, check=False
            )
            self.assertNotEqual(check.returncode, 0)
            self.assertEqual((root / "revision-juridica" / SOURCES[0][1]).read_bytes(), old)
            self.assertEqual((root / "revision-juridica/paquete-completo.md").read_bytes(), pkg)
            (root / "revision-juridica" / SOURCES[0][1]).unlink()
            missing = subprocess.run(
                [sys.executable, str(root / "scripts/generar_revision_juridica.py"),
                 "--check"], cwd=root, capture_output=True, text=True, check=False
            )
            self.assertNotEqual(missing.returncode, 0)

if __name__ == "__main__":
    unittest.main()
