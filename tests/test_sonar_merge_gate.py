#!/usr/bin/env python3
"""Contrato del merge gate nativo Validar + SonarQube Cloud."""

import copy
import unittest
from pathlib import Path

from scripts.verify_sonar_merge_gate import (
    RULESET_ID,
    SonarMergeGateError,
    validate_ruleset,
)


ROOT = Path(__file__).resolve().parents[1]
CI = ROOT / ".github/workflows/ci.yml"


def protected_ruleset(*, include_bypass=True):
    payload = {
        "id": RULESET_ID,
        "name": "Protección main (V 0.1.0)",
        "target": "branch",
        "enforcement": "active",
        "conditions": {
            "ref_name": {
                "include": ["~DEFAULT_BRANCH"],
                "exclude": [],
            }
        },
        "rules": [
            {"type": "deletion"},
            {"type": "non_fast_forward"},
            {
                "type": "required_status_checks",
                "parameters": {
                    "strict_required_status_checks_policy": False,
                    "do_not_enforce_on_create": False,
                    "required_status_checks": [
                        {"context": "Validar", "integration_id": 15368},
                        {
                            "context": "SonarCloud Code Analysis",
                            "integration_id": 12526,
                        },
                    ],
                },
            },
        ],
    }
    if include_bypass:
        payload["bypass_actors"] = []
    return payload


class SonarMergeGateTests(unittest.TestCase):
    def test_ruleset_requires_validar_and_sonar_with_exact_integrations(self):
        result = validate_ruleset(protected_ruleset())
        self.assertEqual(result["status"], "protected")
        self.assertEqual(result["ruleset_id"], RULESET_ID)
        self.assertEqual(result["required_checks"], 2)
        self.assertEqual(result["bypass_visibility"], "known-empty")

        for context in ("Validar", "SonarCloud Code Analysis"):
            missing = protected_ruleset()
            checks = missing["rules"][-1]["parameters"]["required_status_checks"]
            missing["rules"][-1]["parameters"]["required_status_checks"] = [
                row for row in checks if row["context"] != context
            ]
            with self.subTest(missing=context):
                with self.assertRaises(SonarMergeGateError):
                    validate_ruleset(missing)

        wrong = protected_ruleset()
        wrong["rules"][-1]["parameters"]["required_status_checks"][1][
            "integration_id"
        ] = 15368
        with self.assertRaises(SonarMergeGateError):
            validate_ruleset(wrong)

    def test_non_success_sonar_is_not_mergeable_contract(self):
        required = ("Validar", "SonarCloud Code Analysis")
        for sonar_state in (
            "pending",
            "failure",
            "error",
            "cancelled",
            "neutral",
            "skipped",
            None,
        ):
            states = {"Validar": "success", "SonarCloud Code Analysis": sonar_state}
            with self.subTest(sonar_state=sonar_state):
                self.assertFalse(
                    all(states.get(context) == "success" for context in required)
                )
        states = {"Validar": "success", "SonarCloud Code Analysis": "success"}
        self.assertTrue(all(states[context] == "success" for context in required))

    def test_gate_has_no_duplicate_analysis_or_poll_loop(self):
        workflow = CI.read_text(encoding="utf-8")
        governance = workflow.split("\n  gobierno:\n", 1)[1].split(
            "\n  coordinacion:\n", 1
        )[0]
        self.assertEqual(governance.count("rulesets/23709472"), 1)
        self.assertEqual(
            governance.count("python3 scripts/verify_sonar_merge_gate.py"), 1
        )
        self.assertNotIn("sonarcloud.io/api", governance)
        self.assertNotIn("sonar-scanner", governance)
        self.assertNotIn("sleep ", governance)
        self.assertNotIn("while ", governance)

    def test_ruleset_drift_and_bypass_fail_closed(self):
        cases = {}

        wrong_target = protected_ruleset()
        wrong_target["target"] = "tag"
        cases["target"] = wrong_target

        disabled = protected_ruleset()
        disabled["enforcement"] = "evaluate"
        cases["enforcement"] = disabled

        missing_default = protected_ruleset()
        missing_default["conditions"]["ref_name"]["include"] = ["refs/heads/main"]
        cases["default-ref"] = missing_default

        excluded = protected_ruleset()
        excluded["conditions"]["ref_name"]["exclude"] = ["~DEFAULT_BRANCH"]
        cases["excluded-ref"] = excluded

        bypass = protected_ruleset()
        bypass["bypass_actors"] = [{"actor_id": 1, "actor_type": "Team"}]
        cases["bypass"] = bypass

        duplicate = protected_ruleset()
        duplicate["rules"][-1]["parameters"]["required_status_checks"].append(
            {"context": "Validar", "integration_id": 15368}
        )
        cases["duplicate"] = duplicate

        malformed_id = protected_ruleset()
        malformed_id["id"] = True
        cases["bool-id"] = malformed_id

        for name, payload in cases.items():
            with self.subTest(name=name):
                with self.assertRaises(SonarMergeGateError):
                    validate_ruleset(payload)

        hidden = validate_ruleset(protected_ruleset(include_bypass=False))
        self.assertEqual(hidden["bypass_visibility"], "unknown")

    def test_ruleset_preserves_non_strict_policy_and_creation_enforcement(self):
        for field in (
            "strict_required_status_checks_policy",
            "do_not_enforce_on_create",
        ):
            payload = protected_ruleset()
            payload["rules"][-1]["parameters"][field] = True
            with self.subTest(field=field):
                with self.assertRaises(SonarMergeGateError):
                    validate_ruleset(payload)


if __name__ == "__main__":
    unittest.main()
