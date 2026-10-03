"""Pruebas del selector de reconciliación D-043."""

from __future__ import annotations

import importlib.util
import json
import sys
import unittest
from pathlib import Path

SCRIPT = Path(__file__).resolve().parents[1] / "scripts" / "d043_reconcile_issue.py"
spec = importlib.util.spec_from_file_location("d043_reconcile_issue", SCRIPT)
assert spec is not None and spec.loader is not None
module = importlib.util.module_from_spec(spec)
sys.modules[spec.name] = module
spec.loader.exec_module(module)

SHA_122 = "1" * 40
SHA_126 = "2" * 40


def manifest(*, covered: bool = True) -> dict:
    result = {
        "schema": "condor.release-evidence.v1",
        "version": "0.1.126",
        "sha": SHA_126,
        "version_source": "config/version.php",
        "change_count": 1,
        "categories": ["infraestructura"],
        "selection_mode": "conservative",
        "selection_reason": "test",
        "public_checks": [
            "health", "home", "admin_login", "css_publico", "css_admin",
            "js_admin", "storefront", "slug_desconocido",
        ],
        "transition": {
            "required": True,
            "checks": [
                {"id": "migraciones", "required": False},
                {"id": "roles", "required": False},
                {"id": "comandos", "required": False},
                {"id": "configuracion", "required": False},
                {"id": "cache", "required": True},
            ],
        },
    }
    if covered:
        result["covered_releases"] = [{"version": "0.1.122", "sha": SHA_122}]
    return result


def issue(number: int, *, state="open", labels=None, gate=False, order=2, depends_on=None) -> dict:
    body = "### Contrato\n"
    if gate:
        body += '<!-- condor-d043-gate {"version":1,"release":"0.1.122","sha":"' + SHA_122 + '"} -->\n'
    body += '<!-- factory-plan-task ' + json.dumps(
        {
            "version": 1,
            "epic": 459,
            "task_key": f"TASK_{number}",
            "order": order,
            "owner": "pl0n3r",
            "roles": ["qa"],
            "depends_on": depends_on or [],
            "paths": [f"tests/test_{number}.py"],
        },
        separators=(",", ":"),
    ) + " -->"
    return {
        "number": number,
        "state": state,
        "labels": [{"name": item} for item in (labels or ["estado: bloqueado"])],
        "body": body,
    }


class D043ReconcileIssueTests(unittest.TestCase):
    def test_selects_only_first_blocked_issue_with_exact_covered_gate_and_closed_dependencies(self) -> None:
        issues = [
            issue(460, state="closed", labels=["estado: completado"], order=1),
            issue(461, gate=True, order=2, depends_on=[460]),
            issue(462, gate=True, order=3, depends_on=[461]),
        ]
        action = module.select_action(manifest(), issues)
        self.assertIsNotNone(action)
        assert action is not None
        self.assertEqual(action["issue"], 461)
        self.assertEqual(action["order"], 2)
        self.assertEqual(action["gate"]["version"], "0.1.122")
        self.assertIn("condor-d043-reconciled", action["marker"])

    def test_reserved_in_review_completed_or_uncovered_issue_is_never_selected(self) -> None:
        protected = [
            issue(461, gate=True, labels=["estado: bloqueado", "estado: reservado"]),
            issue(462, gate=True, labels=["estado: bloqueado", "estado: en revisión"]),
            issue(463, gate=True, labels=["estado: bloqueado", "estado: completado"]),
        ]
        self.assertIsNone(module.select_action(manifest(), protected))
        self.assertIsNone(module.select_action(manifest(covered=False), [issue(461, gate=True)]))

    def test_newer_release_without_explicit_coverage_does_not_unlock_older_gate(self) -> None:
        self.assertIsNone(module.select_action(manifest(covered=False), [issue(461, gate=True)]))

    def test_invalid_gate_dependency_or_plan_marker_fails_closed(self) -> None:
        invalid_gate = issue(461, gate=True)
        invalid_gate["body"] = invalid_gate["body"].replace('"release":"0.1.122"', '"release":"v0.1.122"')
        duplicate_plan = issue(462, gate=True)
        duplicate_plan["body"] += "\n" + duplicate_plan["body"].splitlines()[-1]

        with self.assertRaises(module.ReconcileError):
            module.select_action(manifest(), [invalid_gate])
        self.assertIsNone(module.select_action(manifest(), [issue(461, gate=True, depends_on=[999])]))
        with self.assertRaises(module.ReconcileError):
            module.select_action(manifest(), [duplicate_plan])


if __name__ == "__main__":
    unittest.main()
