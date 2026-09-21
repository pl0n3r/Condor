#!/usr/bin/env python3
from __future__ import annotations

import tempfile
import unittest
from pathlib import Path

from scripts.ci_self_audit import (
    audit_main_ci,
    audit_release_observer,
    audit_repository,
    audit_workflow,
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


if __name__ == "__main__":
    unittest.main()
