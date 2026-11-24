#!/usr/bin/env python3
from __future__ import annotations

import os
import subprocess
import tempfile
import unittest
from pathlib import Path

from scripts.ci_change_classifier import classify
from scripts.ci_self_audit import (
    audit_main_ci,
    audit_release_observer,
    audit_repository,
    audit_workflow,
    job_blocks,
    job_commands,
    job_value,
)


class SelfAuditTests(unittest.TestCase):
    def write_workflow(self, tmp: str, content: str) -> Path:
        path = Path(tmp) / "bad.yml"
        path.write_text(content, encoding="utf-8")
        return path

    def test_current_repository_passes_self_audit(self) -> None:
        self.assertEqual(audit_repository(), [])

    def test_detects_missing_timeout_and_unpinned_action(self) -> None:
        with tempfile.TemporaryDirectory() as tmp:
            path = self.write_workflow(
                tmp,
                """name: Bad
on: push
jobs:
  test:
    runs-on: ubuntu-latest
    steps:
      - uses: actions/checkout@main
""",
            )
            findings = audit_workflow(path)
        self.assertTrue(any("timeout-minutes" in item for item in findings))
        self.assertTrue(any("no fijada a SHA" in item for item in findings))
        self.assertTrue(any("persist-credentials" in item for item in findings))

    def test_checkout_cannot_be_spoofed_by_comment_or_other_step(self) -> None:
        with tempfile.TemporaryDirectory() as tmp:
            path = self.write_workflow(
                tmp,
                """name: Bad
on: push
jobs:
  test:
    runs-on: ubuntu-latest
    timeout-minutes: 5
    steps:
      - name: Checkout inseguro
        uses: actions/checkout@3d3c42e5aac5ba805825da76410c181273ba90b1
        with:
          fetch-depth: 0
        # persist-credentials: false
      - name: Texto ajeno
        run: echo "persist-credentials: false"
""",
            )
            findings = audit_workflow(path)
        self.assertTrue(any("persist-credentials" in item for item in findings))

    def test_detects_job_level_write_all(self) -> None:
        with tempfile.TemporaryDirectory() as tmp:
            path = self.write_workflow(
                tmp,
                """name: Bad
on: push
jobs:
  test:
    permissions: write-all
    runs-on: ubuntu-latest
    timeout-minutes: 5
    steps:
      - run: true
""",
            )
            findings = audit_workflow(path)
        self.assertTrue(any("write-all" in item for item in findings))

    def test_commented_timeout_does_not_satisfy_job_contract(self) -> None:
        with tempfile.TemporaryDirectory() as tmp:
            path = self.write_workflow(
                tmp,
                """name: Bad
on: push
jobs:
  test:
    runs-on: ubuntu-latest
    # timeout-minutes: 5
    steps:
      - run: true
""",
            )
            findings = audit_workflow(path)
        self.assertTrue(any("timeout-minutes" in item for item in findings))

    def test_factory_reusable_job_does_not_count_as_unpinned_action(self) -> None:
        with tempfile.TemporaryDirectory() as tmp:
            path = self.write_workflow(
                tmp,
                """name: Dependabot
on: workflow_run
jobs:
  verificar:
    runs-on: ubuntu-24.04
    timeout-minutes: 5
    steps:
      - run: echo verificado
  dependabot:
    needs: verificar
    uses: pl0n3r/factory/.github/workflows/coordinacion.yml@v1
  sweep:
    uses: pl0n3r/factory/.github/workflows/coordinacion.yml@v1
""",
            )
            findings = audit_workflow(path)
        self.assertFalse(
            any("action no fijada" in item for item in findings), findings
        )
        self.assertFalse(
            any("workflow externo" in item for item in findings), findings
        )

    def test_real_step_action_is_still_pinned(self) -> None:
        with tempfile.TemporaryDirectory() as tmp:
            path = self.write_workflow(
                tmp,
                """name: Dependabot
on: pull_request
jobs:
  verificar:
    runs-on: ubuntu-24.04
    timeout-minutes: 5
    steps:
      - uses: actions/checkout@main
        with:
          persist-credentials: false
  dependabot:
    uses: pl0n3r/factory/.github/workflows/coordinacion.yml@v1
""",
            )
            findings = audit_workflow(path)
        unpinned = [item for item in findings if "action no fijada" in item]
        self.assertEqual(len(unpinned), 1, findings)
        self.assertIn("actions/checkout@main", unpinned[0])
        self.assertFalse(
            any("coordinacion.yml@v1" in item for item in unpinned), findings
        )

    def test_detects_unpinned_job_level_reusable_workflow(self) -> None:
        with tempfile.TemporaryDirectory() as tmp:
            path = self.write_workflow(
                tmp,
                """name: Bad
on: push
jobs:
  reuse:
    uses: org/repo/.github/workflows/ci.yml@main
""",
            )
            findings = audit_workflow(path)
        self.assertTrue(any("workflow externo" in item for item in findings))

    def test_allows_factory_v1_job_level_reusable_workflow(self) -> None:
        with tempfile.TemporaryDirectory() as tmp:
            path = self.write_workflow(
                tmp,
                """name: Factory
on: pull_request
jobs:
  ci:
    uses: pl0n3r/factory/.github/workflows/ci.yml@v1
""",
            )
            findings = audit_workflow(path)
        self.assertFalse(any("workflow externo" in item for item in findings))

    def test_rejects_factory_unapproved_job_level_ref(self) -> None:
        with tempfile.TemporaryDirectory() as tmp:
            path = self.write_workflow(
                tmp,
                """name: Factory
on: pull_request
jobs:
  ci:
    uses: pl0n3r/factory/.github/workflows/ci.yml@main
""",
            )
            findings = audit_workflow(path)
        self.assertTrue(any("workflow externo" in item for item in findings))

    def test_allows_local_job_level_reusable_workflow(self) -> None:
        with tempfile.TemporaryDirectory() as tmp:
            path = self.write_workflow(
                tmp,
                """name: Good
on: push
jobs:
  reuse:
    uses: ./.github/workflows/reuse.yml
""",
            )
            findings = audit_workflow(path)
        self.assertFalse(any("workflow local" in item for item in findings))

    def test_allows_dollar_local_job_level_reusable_workflow(self) -> None:
        """Acepta $/ solo para reusable workflows locales del mismo commit."""
        with tempfile.TemporaryDirectory() as tmp:
            path = self.write_workflow(
                tmp,
                """name: Good
on: push
jobs:
  reuse:
    uses: $/.github/workflows/reuse.yml
""",
            )
            findings = audit_workflow(path)
        self.assertFalse(any("workflow local" in item for item in findings))
        self.assertFalse(any("workflow externo" in item for item in findings))

    def test_rejects_invalid_dollar_local_workflow_reference(self) -> None:
        """Rechaza $/ fuera de .github/workflows o con un ref explícito."""
        with tempfile.TemporaryDirectory() as tmp:
            path = self.write_workflow(
                tmp,
                """name: Bad
on: push
jobs:
  reuse:
    uses: $/other/reuse.yml@main
""",
            )
            findings = audit_workflow(path)
        self.assertTrue(any("workflow local inválido" in item for item in findings))

    def test_detects_continue_on_error(self) -> None:
        with tempfile.TemporaryDirectory() as tmp:
            path = self.write_workflow(
                tmp,
                """name: Bad
on: push
jobs:
  test:
    runs-on: ubuntu-latest
    timeout-minutes: 5
    steps:
      - run: false
        continue-on-error: true
""",
            )
            findings = audit_workflow(path)
        self.assertTrue(any("continue-on-error" in item for item in findings))

    def test_main_ci_does_not_accept_gate_conditions_from_comments(self) -> None:
        with tempfile.TemporaryDirectory() as tmp:
            path = self.write_workflow(
                tmp,
                """name: CI
on: push
concurrency:
  cancel-in-progress: true
jobs:
  pruebas-base:
    runs-on: ubuntu-latest
    timeout-minutes: 5
    # if: needs.preflight.outputs.pruebas_base == 'true'
    steps:
      - run: python3 scripts/ci_change_classifier.py
  frontend:
    runs-on: ubuntu-latest
    timeout-minutes: 5
    steps:
      - run: npm run typecheck
  backend-php:
    runs-on: ubuntu-latest
    timeout-minutes: 5
    steps:
      - run: python3 scripts/ci_retry.py -- npm ci
  e2e:
    runs-on: ubuntu-latest
    timeout-minutes: 5
    steps:
      - run: python3 scripts/ci_self_audit.py
  validar:
    runs-on: ubuntu-latest
    timeout-minutes: 5
    steps:
      - run: |
          case "$PRUEBAS_BASE" in
            success|skipped) ;;
          esac
          case "$BACKEND_PHP" in
            success|skipped) ;;
          esac
          case "$E2E" in
            success|skipped) ;;
          esac
""",
            )
            findings = audit_main_ci(path)
        self.assertTrue(any("gate base selectivo" in item for item in findings))
        self.assertTrue(any("frontend selectivo" in item for item in findings))
        self.assertTrue(any("backend selectivo" in item for item in findings))
        self.assertTrue(any("E2E selectivo" in item for item in findings))

    def test_main_ci_requires_explanatory_preflight_summary(self) -> None:
        with tempfile.TemporaryDirectory() as tmp:
            path = self.write_workflow(
                tmp,
                """name: CI
on: push
concurrency:
  cancel-in-progress: true
jobs:
  preflight:
    runs-on: ubuntu-latest
    timeout-minutes: 5
    steps:
      - run: python3 scripts/ci_change_classifier.py
  pruebas-base:
    if: needs.preflight.outputs.pruebas_base == 'true'
    runs-on: ubuntu-latest
    timeout-minutes: 5
    steps:
      - run: python3 scripts/ci_self_audit.py
  frontend:
    if: needs.preflight.outputs.frontend == 'true'
    runs-on: ubuntu-latest
    timeout-minutes: 5
    steps:
      - run: python3 scripts/ci_retry.py -- npm ci
  backend-php:
    if: needs.preflight.outputs.backend == 'true'
    runs-on: ubuntu-latest
    timeout-minutes: 5
    steps:
      - run: python3 scripts/ci_retry.py -- composer install
  e2e:
    if: needs.preflight.outputs.e2e == 'true'
    runs-on: ubuntu-latest
    timeout-minutes: 5
    steps:
      - run: true
  validar:
    runs-on: ubuntu-latest
    timeout-minutes: 5
    steps:
      - run: |
          case "$PRUEBAS_BASE" in success|skipped) ;; esac
          case "$FRONTEND" in success|skipped) ;; esac
          case "$BACKEND_PHP" in success|skipped) ;; esac
          case "$E2E" in success|skipped) ;; esac
""",
            )
            findings = audit_main_ci(path)
        self.assertTrue(
            any("resumen explicativo de gates" in item for item in findings)
        )
        self.assertTrue(any("output de modo" in item for item in findings))

    def test_release_observer_must_reuse_transition_classifier(self) -> None:
        with tempfile.TemporaryDirectory() as tmp:
            path = self.write_workflow(
                tmp,
                """name: Release
on: workflow_dispatch
jobs:
  observar:
    runs-on: ubuntu-latest
    timeout-minutes: 5
    steps:
      - run: |
          requiere=false
          if grep -Eq '^(migrations/|config/)' cambios.txt; then
            requiere=true
          fi
""",
            )
            findings = audit_release_observer(path)
        self.assertTrue(any("ci_change_classifier.py" in item for item in findings))

    def test_release_observer_rejects_ignored_classifier_output(self) -> None:
        with tempfile.TemporaryDirectory() as tmp:
            path = self.write_workflow(
                tmp,
                """name: Release
on: workflow_dispatch
jobs:
  observar:
    runs-on: ubuntu-latest
    timeout-minutes: 5
    steps:
      - run: |
          python3 scripts/ci_change_classifier.py \
            --event pull_request \
            --format json < cambios.txt
          requiere=false
          echo "requerida=$requiere" >> "$GITHUB_OUTPUT"
""",
            )
            findings = audit_release_observer(path)
        self.assertTrue(any("transicion_release" in item for item in findings))

    def test_release_observer_rejects_commented_transition_flow(self) -> None:
        with tempfile.TemporaryDirectory() as tmp:
            path = self.write_workflow(
                tmp,
                """name: Release
on: workflow_dispatch
jobs:
  observar:
    runs-on: ubuntu-latest
    timeout-minutes: 5
    steps:
      - run: |
          # requiere="$(
          #   python3 scripts/ci_change_classifier.py --format json
          #   | python3 -c 'print(data["transicion_release"])'
          # )"
          # echo "requerida=$requiere" >> "$GITHUB_OUTPUT"
          echo "sin clasificador"
""",
            )
            findings = audit_release_observer(path)
        self.assertTrue(any("transicion_release" in item for item in findings))

    def test_release_observer_rejects_transition_without_github_output(self) -> None:
        with tempfile.TemporaryDirectory() as tmp:
            path = self.write_workflow(
                tmp,
                """name: Release
on: workflow_dispatch
jobs:
  observar:
    runs-on: ubuntu-latest
    timeout-minutes: 5
    steps:
      - run: |
          requiere="$(
            python3 scripts/ci_change_classifier.py \
              --event pull_request \
              --format json < cambios.txt \
            | python3 -c 'import json, sys; print(str(json.load(sys.stdin)["transicion_release"]).lower())'
          )"
          echo "requerida=$requiere" > /tmp/requerida.txt
""",
            )
            findings = audit_release_observer(path)
        self.assertTrue(any("GITHUB_OUTPUT" in item for item in findings))

    def test_release_observer_rejects_transition_reassignment(self) -> None:
        with tempfile.TemporaryDirectory() as tmp:
            path = self.write_workflow(
                tmp,
                """name: Release
on: workflow_dispatch
jobs:
  observar:
    runs-on: ubuntu-latest
    timeout-minutes: 5
    steps:
      - run: |
          requiere="$(
            python3 scripts/ci_change_classifier.py \
              --event pull_request \
              --format json < cambios.txt \
            | python3 -c 'import json, sys; print(str(json.load(sys.stdin)["transicion_release"]).lower())'
          )"
          echo "requerida=$requiere" >> "$GITHUB_OUTPUT"
          requiere=false
""",
            )
            findings = audit_release_observer(path)
        self.assertTrue(any("sobrescribir" in item for item in findings))

    def test_release_observer_rejects_transition_inside_dead_control_flow(self) -> None:
        with tempfile.TemporaryDirectory() as tmp:
            path = self.write_workflow(
                tmp,
                """name: Release
on: workflow_dispatch
jobs:
  observar:
    runs-on: ubuntu-latest
    timeout-minutes: 5
    steps:
      - run: |
          if false; then
            requiere="$(
              python3 scripts/ci_change_classifier.py \
                --event pull_request \
                --format json < cambios.txt \
              | python3 -c 'import json, sys; print(str(json.load(sys.stdin)["transicion_release"]).lower())'
            )"
            echo "requerida=$requiere" >> "$GITHUB_OUTPUT"
          fi
""",
            )
            findings = audit_release_observer(path)
        self.assertTrue(any("transicion_release" in item for item in findings))

    def test_release_observer_accepts_effective_transition_classifier(self) -> None:
        with tempfile.TemporaryDirectory() as tmp:
            path = self.write_workflow(
                tmp,
                """name: Release
on: workflow_dispatch
jobs:
  observar:
    runs-on: ubuntu-latest
    timeout-minutes: 5
    steps:
      - run: |
          requiere="$(
            python3 scripts/ci_change_classifier.py \
              --event pull_request \
              --format json < cambios.txt \
            | python3 -c 'import json, sys; print(str(json.load(sys.stdin)["transicion_release"]).lower())'
          )"
          echo "requerida=$requiere" >> "$GITHUB_OUTPUT"
""",
            )
            findings = audit_release_observer(path)
        self.assertEqual(findings, [])

    def test_retry_must_wrap_each_install_command(self) -> None:
        with tempfile.TemporaryDirectory() as tmp:
            path = self.write_workflow(
                tmp,
                """name: CI
on: push
concurrency:
  cancel-in-progress: true
jobs:
  test:
    runs-on: ubuntu-latest
    timeout-minutes: 5
    steps:
      - name: Dependencias
        run: |
          python3 scripts/ci_retry.py -- npm ci
          composer install --no-interaction
""",
            )
            text = path.read_text(encoding="utf-8")
            text += """
# scripts/ci_change_classifier.py
# scripts/ci_self_audit.py
# needs.preflight.outputs.pruebas_base == 'true'
# needs.preflight.outputs.backend == 'true'
# needs.preflight.outputs.e2e == 'true'
# case "$PRUEBAS_BASE" in
# case "$BACKEND_PHP" in
"""
            path.write_text(text, encoding="utf-8")
            findings = audit_main_ci(path)
        self.assertFalse(any("'npm ci'" in item for item in findings))
        self.assertTrue(any("'composer install'" in item for item in findings))


    def _run_validate_aggregate(
        self, required: str, base_result: str, scripts_result: str
    ) -> subprocess.CompletedProcess[str]:
        """Ejecuta el bash real de Validar con resultados sintéticos de jobs."""
        root = Path(__file__).resolve().parent.parent
        jobs = job_blocks((root / ".github/workflows/ci.yml").read_text(encoding="utf-8"))
        script = "\n".join(job_commands(jobs["validar"]))
        env = {
            **os.environ,
            **{
                key: "success"
                for key in (
                    "FACTORY_CI", "PREFLIGHT", "DOCUMENTACION", "GOBIERNO",
                    "COORDINACION", "STATIC_ANALYSIS", "RECTOR_DRY_RUN",
                    "FRONTEND", "AUDIT_NPM", "AUDIT_COMPOSER", "BACKEND_PHP",
                    "BACKUP_RESTORE", "E2E",
                )
            },
            "PRUEBAS_BASE": base_result,
            "PRUEBAS_BASE_REQUERIDAS": required,
            "TESTS_SCRIPTS": scripts_result,
            "MODO": "rapido" if required == "false" else "completo",
            "MOTIVO": "test-sintetico",
            "CATEGORIAS": "documentacion",
        }
        with tempfile.TemporaryDirectory() as tmp:
            env["GITHUB_STEP_SUMMARY"] = str(Path(tmp) / "summary.md")
            return subprocess.run(
                ["bash", "-c", script], cwd=root, env=env,
                capture_output=True, text=True, check=False,
            )

    def test_documentation_lane_requires_real_tests(self) -> None:
        """AC-02: docs rápidos mantienen tests reales y Validar falla cerrado."""
        root = Path(__file__).resolve().parent.parent
        jobs = job_blocks((root / ".github/workflows/ci.yml").read_text(encoding="utf-8"))
        light = jobs["tests-scripts"]
        self.assertEqual(job_value(light, "name"), "Tests de scripts")
        self.assertIsNone(job_value(light, "if"))
        self.assertIsNone(job_value(light, "needs"))
        script = "\n".join(job_commands(light))
        self.assertIn("set -euo pipefail", script)
        for pattern in (
            "test_ci_change_classifier.py",
            "test_ci_self_audit.py",
            "test_revision_juridica.py",
        ):
            with self.subTest(suite=pattern):
                self.assertIn(
                    "python3 -m unittest discover -s tests -p '" + pattern + "' -v",
                    script,
                )
        for path in ("README.md", "docs/manual/propietario/guia.md"):
            with self.subTest(path=path):
                decision = classify([path], "pull_request")
                self.assertEqual(decision.modo, "rapido")
                self.assertFalse(decision.pruebas_base)
        self.assertEqual(
            self._run_validate_aggregate("false", "skipped", "success").returncode, 0
        )
        self.assertNotEqual(
            self._run_validate_aggregate("false", "skipped", "failure").returncode, 0
        )

    def test_lightweight_tests_fail_aggregate_and_script_on_failure(self) -> None:
        root = Path(__file__).resolve().parent.parent
        jobs = job_blocks((root / ".github/workflows/ci.yml").read_text(encoding="utf-8"))
        self.assertIn("tests-scripts", job_value(jobs["validar"], "needs") or "")
        script = "\n".join(job_commands(jobs["tests-scripts"]))
        with tempfile.TemporaryDirectory() as tmp:
            fake_python = Path(tmp) / "python3"
            fake_python.write_text("#!/bin/sh\nexit 27\n", encoding="utf-8")
            fake_python.chmod(0o755)
            run = subprocess.run(
                ["bash", "-c", script], cwd=root,
                env={**os.environ, "PATH": tmp + os.pathsep + os.environ["PATH"]},
                capture_output=True, text=True, check=False,
            )
            self.assertEqual(run.returncode, 27, run.stderr)
        self.assertNotEqual(
            self._run_validate_aggregate("false", "skipped", "cancelled").returncode, 0
        )

    def test_runtime_requires_base_tests_and_rejects_invalid_skip(self) -> None:
        runtime = classify(["src/Http/Controller/AdminController.php"], "pull_request")
        self.assertTrue(runtime.pruebas_base)
        self.assertTrue(runtime.backend)
        self.assertNotEqual(
            self._run_validate_aggregate("true", "skipped", "success").returncode, 0
        )
        self.assertNotEqual(
            self._run_validate_aggregate("", "skipped", "success").returncode, 0
        )
        self.assertNotEqual(
            self._run_validate_aggregate("true", "failure", "success").returncode, 0
        )
        self.assertEqual(
            self._run_validate_aggregate("true", "success", "success").returncode, 0
        )

if __name__ == "__main__":
    unittest.main()
