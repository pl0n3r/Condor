#!/usr/bin/env python3
from __future__ import annotations

import tempfile
import unittest
from pathlib import Path

from scripts.ci_self_audit import audit_main_ci, audit_repository, audit_workflow


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
