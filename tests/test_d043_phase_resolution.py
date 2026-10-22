"""Regresiones del resolver local fail-closed para D-043 (Condor #826)."""

from __future__ import annotations

import importlib.util
import json
import subprocess
import sys
import tempfile
import unittest
from pathlib import Path

ROOT = Path(__file__).resolve().parents[1]
SCRIPT = ROOT / "scripts" / "d043_phase_resolution.py"
WORKFLOW = ROOT / ".github" / "workflows" / "observar-deploy-automatico.yml"
spec = importlib.util.spec_from_file_location("d043_phase_resolution", SCRIPT)
assert spec is not None and spec.loader is not None
module = importlib.util.module_from_spec(spec)
spec.loader.exec_module(module)


class D043PhaseResolutionTests(unittest.TestCase):
    def phase(self, *, phase: str = "construccion", repository: str = "pl0n3r/Condor", **extra: object) -> str:
        with tempfile.TemporaryDirectory() as tmp:
            target = Path(tmp) / "datos.yml"
            target.write_text(json.dumps({"version": 1, "project": repository, "phase": phase, **extra}), encoding="utf-8")
            return module.resolve_phase(target, "pl0n3r/Condor")

    def test_construction_phase_is_independent_of_closed_go_live_issue(self) -> None:
        self.assertEqual(self.phase(phase="construccion"), "construccion")
        # No hay dependencia de una consulta a GitHub/#389 en la API o CLI.
        with tempfile.TemporaryDirectory() as tmp:
            path = Path(tmp) / "datos.yml"
            path.write_text(json.dumps({"version": 1, "project": "pl0n3r/Condor", "phase": "construccion"}), encoding="utf-8")
            result = subprocess.run([sys.executable, str(SCRIPT), "--source", str(path), "--repository", "pl0n3r/Condor"], capture_output=True, text=True, check=False)
        self.assertEqual(result.returncode, 0, result.stderr)
        self.assertEqual(result.stdout.strip(), "construccion")

    def test_live_and_unknown_fail_closed(self) -> None:
        self.assertEqual(self.phase(phase="live"), "live")
        self.assertEqual(self.phase(phase="staging"), "desconocida")
        self.assertEqual(self.phase(phase=""), "desconocida")
        self.assertEqual(self.phase(repository="pl0n3r/Other"), "desconocida")
        self.assertEqual(self.phase(version=2), "desconocida")
        self.assertEqual(self.phase(version=True), "desconocida")
        self.assertEqual(module.resolve_phase(Path("does-not-exist"), "pl0n3r/Condor"), "desconocida")
        self.assertEqual(module.resolve_phase(Path("does-not-exist"), "other/repo"), "desconocida")

    def test_malformed_or_missing_source_fails_closed(self) -> None:
        with tempfile.TemporaryDirectory() as tmp:
            path = Path(tmp) / "datos.yml"
            for content in ('', '{broken', '[]', '{}', '{"version":1,"version":1,"project":"pl0n3r/Condor","phase":"construccion"}', '{"version":1,"project":"pl0n3r/Condor"}'):
                with self.subTest(content=content):
                    path.write_text(content, encoding="utf-8")
                    self.assertEqual(module.resolve_phase(path, "pl0n3r/Condor"), "desconocida")
            path.unlink()
            self.assertEqual(module.resolve_phase(path, "pl0n3r/Condor"), "desconocida")

    def test_workflow_uses_local_phase_resolver(self) -> None:
        text = WORKFLOW.read_text(encoding="utf-8")
        start = text.index("- name: Resolver fase D-043 desde la puerta canónica")
        end = text.index("- name: Auto-validar baseline D-043 previa en construcción", start)
        step = text[start:end]
        self.assertIn("scripts/d043_phase_resolution.py", step)
        self.assertIn("--source datos.yml", step)
        self.assertIn("--repository", step)
        self.assertIn('echo "value=$phase" >> "$GITHUB_OUTPUT"', step)
        self.assertNotIn("issues/389", step)
        self.assertIn("for issue_number in 1 389; do", text[end:])
        self.assertIn('FASE_CONDOR: $' + '{{ steps.fase.outputs.value }}', text[end:])


if __name__ == "__main__":
    unittest.main()
