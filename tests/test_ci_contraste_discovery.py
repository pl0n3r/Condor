#!/usr/bin/env python3
"""Regresión del contrato de ejecución de las hojas de contraste D."""
from __future__ import annotations

import fnmatch
import re
import unittest
from pathlib import Path

ROOT = Path(__file__).resolve().parents[1]
WORKFLOW = ROOT / ".github/workflows/ci.yml"
STEP = "Probar contratos de contraste D"
COMMAND = "python3 -m unittest discover -s tests -p 'test_contraste_d_l*.py'"


def extract_job(content: str) -> str:
    match = re.search(
        r"(?ms)^  pruebas-base:\n(.*?)(?=^  [a-z][a-z0-9-]*:\n|\Z)", content
    )
    if match is None:
        raise AssertionError("Falta job de contratos/pruebas-base")
    return match.group(1)


def required_step(job: str) -> str:
    entries = job.split("\n      - name: ")
    matching = [part for part in entries if part.startswith(STEP + "\n")]
    if len(matching) != 1:
        raise AssertionError("Falta paso único de contraste D")
    lines = matching[0].splitlines()
    required = (
        "        run: |",
        "          set -euo pipefail",
        "          if compgen -G 'tests/test_contraste_d_l*.py' > /dev/null; then",
        "            " + COMMAND,
        "          else",
        "            echo 'NO_VERIFICABLE: aún no existen hojas D-L en este SHA; no se atribuye cobertura.'",
        "          fi",
    )
    if any(line not in lines for line in required):
        raise AssertionError("Paso fail-closed de contraste D ausente o alterado")
    if any("|| true" in line or "continue-on-error" in line or "if: false" in line
           for line in lines):
        raise AssertionError("Paso de contraste D silenciado")
    return matching[0]


class ContrastDiscoveryTests(unittest.TestCase):
    def test_workflow_executes_contrast_d(self) -> None:
        job = extract_job(WORKFLOW.read_text(encoding="utf-8"))
        self.assertIn(COMMAND, required_step(job))
        self.assertTrue(fnmatch.fnmatch("test_contraste_d_l3.py", "test_contraste_d_l*.py"))
        self.assertTrue(fnmatch.fnmatch("test_contraste_d_l5.py", "test_contraste_d_l*.py"))
        self.assertFalse(fnmatch.fnmatch("test_ci_contraste_discovery.py", "test_contraste_d_l*.py"))
        self.assertFalse(fnmatch.fnmatch("test_contraste_cd_d1.py", "test_contraste_d_l*.py"))

    def test_rejects_bypass(self) -> None:
        job = extract_job(WORKFLOW.read_text(encoding="utf-8"))
        step = required_step(job)
        for mutation in (
            job.replace("            " + COMMAND, "            " + COMMAND + " || true"),
            job.replace("      - name: " + STEP, "      - name: Paso omitido"),
            job.replace("            " + COMMAND, "            echo omitido"),
            job.replace(
                "          if compgen -G 'tests/test_contraste_d_l*.py' > /dev/null; then",
                "          if false; then",
            ),
            job.replace(step, step + "\n        continue-on-error: true"),
        ):
            with self.subTest(mutation=mutation[-100:]):
                with self.assertRaises(AssertionError):
                    required_step(mutation)

    def test_preserves_existing_tests(self) -> None:
        job = extract_job(WORKFLOW.read_text(encoding="utf-8"))
        commands = (
            "python3 -m unittest discover -s tests -p 'test_ci_*.py'",
            "python3 tests/test_release_identity_guard.py",
            "python3 tests/test_symfony_lts_dependency_guard.py",
            "python3 tests/test_factory_deploy_adapters.py",
            "python3 -m unittest discover -s tests/contract -p 'test_*.py'",
            "python3 -m unittest discover -s tests/integration -p 'test_*.py'",
        )
        for command in commands:
            with self.subTest(command=command):
                self.assertIn("run: " + command, job)
        self.assertLess(job.index(commands[0]), job.index(COMMAND))

    def test_isolation(self) -> None:
        job = extract_job(WORKFLOW.read_text(encoding="utf-8"))
        self.assertIn("needs: preflight", job)
        self.assertIn("if: needs.preflight.outputs.pruebas_base == 'true'", job)
        self.assertIn("runs-on: ubuntu-latest", job)
        self.assertNotIn("continue-on-error: true", job)
        self.assertNotIn("workflow_dispatch:", job)


if __name__ == "__main__":
    unittest.main()
