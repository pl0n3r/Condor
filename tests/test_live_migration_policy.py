#!/usr/bin/env python3
"""Aceptación ejecutable Condor #615: política de migraciones live."""

from __future__ import annotations

import json
import subprocess
import unittest
from pathlib import Path


ROOT = Path(__file__).resolve().parents[1]
POLICY = ROOT / "scripts" / "live-schema-policy.php"
RUNBOOK = ROOT / "docs" / "LIVE-MIGRATION-POLICY.md"
DECISIONS = ROOT / "decisiones.yml"


def evaluate(payload: object) -> dict[str, object]:
    result = subprocess.run(
        ["php", str(POLICY)],
        input=json.dumps(payload),
        cwd=ROOT,
        text=True,
        capture_output=True,
        check=False,
    )
    if result.returncode != 0:
        raise AssertionError(
            f"policy failed rc={result.returncode} stderr={result.stderr!r}"
        )
    if result.stderr:
        raise AssertionError(f"unexpected stderr: {result.stderr!r}")
    return json.loads(result.stdout)


def complete_additive() -> dict[str, object]:
    return {
        "classification": "additive",
        "dry_run_valid": True,
        "allowlist_complete": True,
        "backup_receipt_verified": True,
        "post_checks": {
            "service_readiness": True,
            "schema": True,
            "smoke": True,
        },
    }


class LiveMigrationPolicyTests(unittest.TestCase):
    def test_classification_is_closed_and_unknown_fails_closed(self) -> None:
        for value in ("additive", "destructive", "unknown"):
            with self.subTest(value=value):
                self.assertEqual(value, evaluate({**complete_additive(), "classification": value})["classification"])

        for invalid in ("rename", "safe", "", None, 17, ["additive"]):
            with self.subTest(invalid=invalid):
                result = evaluate({**complete_additive(), "classification": invalid})
                self.assertEqual("unknown", result["classification"])
                self.assertFalse(result["eligible_after_owner_gate"])
                self.assertFalse(result["auto_execute"])
                self.assertEqual("owner_gate_required", result["authority"])

        extra = evaluate({**complete_additive(), "unexpected": True})
        self.assertFalse(extra["eligible_after_owner_gate"])
        self.assertEqual("evidence_incomplete", extra["reason"])

        reordered = {
            "post_checks": {
                "smoke": True,
                "service_readiness": True,
                "schema": True,
            },
            "backup_receipt_verified": True,
            "allowlist_complete": True,
            "dry_run_valid": True,
            "classification": "additive",
        }
        self.assertTrue(evaluate(reordered)["eligible_after_owner_gate"])

    def test_additive_requires_preflight_backup_receipt_and_postchecks(self) -> None:
        valid = evaluate(complete_additive())
        self.assertEqual("additive", valid["classification"])
        self.assertTrue(valid["eligible_after_owner_gate"])
        self.assertFalse(valid["auto_execute"])
        self.assertEqual("owner_gate_required", valid["authority"])
        self.assertEqual("eligible_after_owner_gate", valid["reason"])

        cases = [
            ("dry_run_valid", None),
            ("allowlist_complete", None),
            ("backup_receipt_verified", None),
            ("post_checks", "service_readiness"),
            ("post_checks", "schema"),
            ("post_checks", "smoke"),
        ]
        for field, nested in cases:
            payload = complete_additive()
            if nested is None:
                payload[field] = False
            else:
                post_checks = dict(payload["post_checks"])
                post_checks[nested] = False
                payload["post_checks"] = post_checks
            with self.subTest(field=field, nested=nested):
                result = evaluate(payload)
                self.assertFalse(result["eligible_after_owner_gate"])
                self.assertEqual("evidence_incomplete", result["reason"])

    def test_destructive_and_unknown_never_become_eligible_or_auto_execute(self) -> None:
        for classification in ("destructive", "unknown"):
            payload = complete_additive()
            payload["classification"] = classification
            result = evaluate(payload)
            self.assertFalse(result["eligible_after_owner_gate"])
            self.assertFalse(result["auto_execute"])
            self.assertEqual("owner_gate_required", result["authority"])
            self.assertEqual(
                f"{classification}_blocked",
                result["reason"],
            )

    def test_backup_receipt_and_health_schema_smoke_are_mandatory(self) -> None:
        payload = complete_additive()
        self.assertTrue(evaluate(payload)["eligible_after_owner_gate"])

        payload.pop("backup_receipt_verified")
        result = evaluate(payload)
        self.assertFalse(result["eligible_after_owner_gate"])

        for key in ("service_readiness", "schema", "smoke"):
            payload = complete_additive()
            post_checks = dict(payload["post_checks"])
            post_checks.pop(key)
            payload["post_checks"] = post_checks
            with self.subTest(key=key):
                result = evaluate(payload)
                self.assertFalse(result["eligible_after_owner_gate"])

    def test_runbook_documents_abort_forward_fix_and_verified_restore_boundaries(self) -> None:
        text = RUNBOOK.read_text(encoding="utf-8").lower()
        for required in (
            "abort conditions",
            "forward-fix",
            "restore",
            "recovery ya verificado",
            "no implementa rollback",
            "puerta humana",
        ):
            self.assertIn(required, text)
        self.assertIn("auto_execute=false", text)

    def test_d054_remains_owner_gated_and_policy_has_no_execution_path(self) -> None:
        decisions = DECISIONS.read_text(encoding="utf-8")
        source = POLICY.read_text(encoding="utf-8")
        self.assertIn('"id":"D-054","status":"active"', decisions)
        self.assertIn("live", decisions)
        self.assertNotIn("CONDOR_PRODUCTION_STAGE", source)
        self.assertNotIn("CONDOR_AUTO_MIGRATE", source)
        self.assertNotIn("doctrine:migrations:migrate", source)
        for forbidden in (
            "PDO",
            "mysqli",
            "curl_",
            "shell_exec",
            "proc_open",
            "passthru(",
            "system(",
        ):
            with self.subTest(forbidden=forbidden):
                self.assertNotIn(forbidden, source)
        self.assertIn("'auto_execute' => false", source)
        self.assertIn("'authority' => 'owner_gate_required'", source)


if __name__ == "__main__":
    unittest.main()
