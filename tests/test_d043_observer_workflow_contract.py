"""Contrato del horizonte D-043 usado por observers."""

from __future__ import annotations

import os
import re
import subprocess
import tempfile
import textwrap
import unittest
from pathlib import Path

ROOT = Path(__file__).resolve().parents[1]
AUTO = ROOT / ".github" / "workflows" / "observar-deploy-automatico.yml"
MANUAL = ROOT / ".github" / "workflows" / "observar-release.yml"


class D043ObserverWorkflowContractTests(unittest.TestCase):
    def workflow(self, path: Path) -> str:
        return path.read_text(encoding="utf-8")

    def test_automatic_observer_scopes_pending_reducer_to_0_1_122_baseline(self) -> None:
        workflow = self.workflow(AUTO)
        self.assertIn("scripts/d043_pending_releases.py", workflow)
        self.assertIn("--minimum-version 0.1.122", workflow)


    def test_manual_observer_scopes_pending_reducer_to_0_1_122_baseline(self) -> None:
        workflow = self.workflow(MANUAL)
        self.assertIn("scripts/d043_pending_releases.py", workflow)
        self.assertIn("--minimum-version 0.1.122", workflow)


    def _run_git(self, root: Path, *args: str) -> str:
        return subprocess.run(
            ["git", *args], cwd=root, capture_output=True, text=True,
            check=True, timeout=20,
        ).stdout.strip()

    def _commit(self, root: Path, file: str, contents: str) -> str:
        dest = root / file
        dest.parent.mkdir(parents=True, exist_ok=True)
        dest.write_text(contents, encoding="utf-8")
        self._run_git(root, "add", "-A")
        self._run_git(root, "commit", "-qm", "synthetic fixture")
        return self._run_git(root, "rev-parse", "HEAD")

    def _repo(self, root: Path, version: str = "0.1.194") -> str:
        self._run_git(root, "init", "-q")
        self._run_git(root, "config", "user.name", "D043 Fixture")
        self._run_git(root, "config", "user.email", "d043@example.invalid")
        return self._commit(
            root, "config/version.php",
            "<?php\nreturn ['version' => '" + version + "'];\n",
        )

    def _step(self, name: str) -> str:
        match = re.search(
            r"(?ms)^      - name: " + re.escape(name)
            + r"\n(?P<body>.*?)(?=^      - name: |\Z)",
            self.workflow(AUTO),
        )
        self.assertIsNotNone(match, name)
        return match.group("body")

    def _version_run(self, root: Path, before: str, head: str):
        step = self._step("Leer versión humana esperada")
        script = textwrap.dedent(step.split("        run: |\n", 1)[1])
        script = script.replace("${{ github.event.before }}", before)
        script = script.replace("${{ github.sha }}", head)
        output = root / "github-out.txt"
        if output.exists():
            output.unlink()
        execution = subprocess.run(
            ["bash", "-c", script], cwd=root,
            env={**os.environ, "GITHUB_OUTPUT": str(output)},
            capture_output=True, text=True, timeout=25, check=False,
        )
        rows = {}
        if output.exists():
            rows = dict(
                line.split("=", 1)
                for line in output.read_text(encoding="utf-8").splitlines()
                if "=" in line
            )
        return execution.returncode, rows, execution.stdout + execution.stderr

    def test_same_version_code_only_push_never_accumulates_as_new_release(self) -> None:
        with tempfile.TemporaryDirectory() as temp:
            root = Path(temp)
            old = self._repo(root)
            head = self._commit(root, "tests/new_trial_contract.py", "# tests\n")
            code, outputs, log = self._version_run(root, old, head)
            self.assertEqual(0, code, log)
            self.assertEqual("true", outputs.get("version_unchanged"))
            self.assertEqual("false", outputs.get("bootstrap_only"))
        for step in (
            "Detectar cambios de la release",
            "Construir manifiesto canónico actual",
            "Acumular transiciones D-043 pendientes",
            "Observar producción en modo solo lectura",
        ):
            self.assertIn(
                "steps.version.outputs.version_unchanged != 'true'",
                self._step(step),
            )
        notice = self._step("Registrar push sin release D-043")
        self.assertIn("version_unchanged == 'true'", notice)
        self.assertIn("NO_EVALUADO", notice)
        self.assertNotIn("gh issue comment", notice)

    def test_new_version_still_accumulates_all_pending_with_exact_identity(self) -> None:
        with tempfile.TemporaryDirectory() as temp:
            root = Path(temp)
            old = self._repo(root)
            head = self._commit(
                root, "config/version.php",
                "<?php\nreturn ['version' => '0.1.195'];\n",
            )
            code, outputs, log = self._version_run(root, old, head)
            self.assertEqual(0, code, log)
            self.assertEqual("false", outputs.get("version_unchanged"))
            self.assertEqual("true", outputs.get("bootstrap_previous"))
        accum = self._step("Acumular transiciones D-043 pendientes")
        for token in (
            "--minimum-version 0.1.122", "release_evidence.py accumulate",
            'git cat-file -e "${sha}^{commit}"',
            'if [[ "$historical_version" != "$version" ]]',
            "steps.version.outputs.version_unchanged != 'true'",
        ):
            self.assertIn(token, accum)

    def test_code_only_path_cannot_auto_validate_or_erase_pending_deploy(self) -> None:
        with tempfile.TemporaryDirectory() as temp:
            root = Path(temp)
            old = self._repo(root)
            head = self._commit(
                root, "scripts/release_evidence.py", "# bootstrap path\n",
            )
            code, outputs, log = self._version_run(root, old, head)
            self.assertEqual(0, code, log)
            self.assertEqual("true", outputs.get("version_unchanged"))
            self.assertEqual("true", outputs.get("bootstrap_only"))
        for step in (
            "Auto-validar baseline D-043 previa en construcción",
            "Capturar evidencia post-deploy para D-043 desarrollo",
            "Evaluar auto-validación D-043 de desarrollo",
            "Publicar tarjeta humana D-043 pendiente",
            "Registrar estado observado en el roadmap",
        ):
            self.assertIn(
                "steps.version.outputs.version_unchanged != 'true'",
                self._step(step),
            )
        self.assertIn(
            "steps.auto_dev.outputs.validada == 'true'",
            self._step("Registrar auto-validación D-043 de desarrollo"),
        )

    def test_missing_previous_release_identity_fails_closed(self) -> None:
        with tempfile.TemporaryDirectory() as temp:
            root = Path(temp)
            old = self._repo(root)
            head = self._commit(root, "README.md", "no bump\n")
            for before in ("0" * 40, "a" * 40, "not-sha"):
                code, _, log = self._version_run(root, before, head)
                self.assertNotEqual(0, code, log)
        with tempfile.TemporaryDirectory() as temp:
            root = Path(temp)
            old = self._repo(root)
            head = self._commit(
                root, "config/version.php",
                "<?php\nreturn [ 'version' => '0.1.194' ];\n",
            )
            code, _, log = self._version_run(root, old, head)
            self.assertNotEqual(0, code, log)
            self.assertIn("sin incremento", log)
        with tempfile.TemporaryDirectory() as temp:
            root = Path(temp)
            old = self._repo(root, version="")
            head = self._commit(
                root, "config/version.php",
                "<?php\nreturn ['version' => '0.1.194'];\n",
            )
            code, _, log = self._version_run(root, old, head)
            self.assertNotEqual(0, code, log)
            self.assertIn("versión previa", log)


if __name__ == "__main__":
    unittest.main()
