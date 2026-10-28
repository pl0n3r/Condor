"""Expose M02's four legacy AC to Validar and exercise actual TSX handlers.

Unittest discovery uses test_ci_*.py; this module imports the legacy ACs
rather than duplicating text assertions. Node runs behavior without HTTP,
PII, a browser, an npm install, or mutations to production.
"""
from pathlib import Path
import shutil
import subprocess
import unittest

from test_contracts_admin_ui import ModuloTests

ROOT = Path(__file__).resolve().parents[1]
HARNESS = ROOT / "tests/contracts_admin_ui_runtime.mjs"


class ContractsAdminBehaviorTests(unittest.TestCase):
    def _run(self, scenario: str, *, mutation: str | None = None) -> subprocess.CompletedProcess[str]:
        self.assertIsNotNone(shutil.which("node"), "Node is required to exercise M02 behavior")
        args = ["node", str(HARNESS), scenario]
        if mutation:
            args.append(mutation)
        return subprocess.run(args, cwd=ROOT, capture_output=True, text=True,
                              timeout=20, check=False)

    def test_tenant_scope_and_mutation_guard(self):
        normal = self._run("scope")
        self.assertEqual(0, normal.returncode, normal.stdout + normal.stderr)
        for mutation in ("--mutate-scope", "--mutate-transition"):
            with self.subTest(mutation=mutation):
                bypass = self._run("scope", mutation=mutation)
                self.assertNotEqual(0, bypass.returncode,
                                    "scope bypass must break observed behavior")
                self.assertIn("isolation", bypass.stderr,
                              "the mutation must fail on cross-scope behavior")

    def test_invalid_permissions_fixtures_duplicates_and_transitions(self):
        result = self._run("validation")
        self.assertEqual(0, result.returncode, result.stdout + result.stderr)


__all__ = ["ModuloTests", "ContractsAdminBehaviorTests"]
