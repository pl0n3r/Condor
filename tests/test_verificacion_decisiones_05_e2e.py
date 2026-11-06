#!/usr/bin/env python3
"""#1606: offline witnesses and fail-closed external evidence, not production E2E."""
from __future__ import annotations

import re
import runpy
import unittest
from datetime import datetime
from pathlib import Path

ROOT = Path(__file__).resolve().parents[1]
PARENT = ROOT / "tests/test_verificacion_decisiones_05.py"
REPORT = ROOT / "docs/verificacion/decisiones-05-evidencia-externa.md"
IDS = (
    "D-044-S05", "D-044-S06", "D-044-S07", "D-044-S08",
    "D-044-S09", "D-044-S10", "D-044-S11", "D-045",
    "D-045-S01", "D-045-S02", "D-045-S03", "D-046",
    "D-047", "D-048", "D-048-S02", "D-049",
    "D-049-S01", "D-049-S02", "D-049-S03", "D-049-S04",
)
NEGATIVES = (
    ("D-044-S05", "tests/test_seo_settings_http.py", "test_acceptance"),
    ("D-044-S08", "tests/test_post_deploy.py", "test_backup_failure_prevents_migration_and_cache"),
    ("D-044-S09", "tests/test_post_deploy.py", "test_live_fails_closed_without_migrating_or_touching_cache"),
    ("D-045-S01", "tests/test_indexnow_notifier.py", "test_acceptance"),
    ("D-045-S02", "tests/test_tag_manager_settings.py", "test_acceptance"),
    ("D-046", "tests/test_provision_platform_owner_command_unit.py", "test_acceptance"),
    ("D-047", "tests/test_shared_diagnostic_http.py", "test_acceptance"),
    ("D-048", "tests/test_platform_owner_api_permissions.py", "test_acceptance"),
    ("D-049-S04", "tests/test_cobertura_condor_11.py", "test_falla_cerrado"),
)
FIELDS = frozenset({"decision_id", "sha", "environment", "observed_at", "source_ref", "result"})


def external_receipt_status(receipt: object, exact_sha: str) -> str:
    """Even correctly shaped fixtures do not authenticate real-world evidence."""
    if type(receipt) is not dict or type(exact_sha) is not str:
        return "UNKNOWN"
    if re.fullmatch(r"[a-f0-9]{40}", exact_sha) is None:
        return "UNKNOWN"
    if set(receipt) != FIELDS or any(
        type(k) is not str or type(v) is not str for k, v in receipt.items()
    ):
        return "UNKNOWN"
    if receipt["decision_id"] not in IDS or receipt["sha"] != exact_sha:
        return "UNKNOWN"
    try:
        stamp = receipt["observed_at"]
        observed = datetime.fromisoformat(stamp.replace("Z", "+00:00"))
        if (not stamp.endswith("Z") or observed.utcoffset().total_seconds() != 0
                or observed.isoformat(timespec="seconds").replace("+00:00", "Z") != stamp):
            return "UNKNOWN"
    except (ValueError, TypeError, OverflowError, AttributeError):
        return "UNKNOWN"
    if (receipt["environment"] != "synthetic"
            or not receipt["source_ref"].startswith("fixture:")
            or receipt["result"] not in {"PASS", "FAIL", "UNKNOWN"}):
        return "UNKNOWN"
    # No owner/provider attestation verifier is available to this offline test.
    return "UNKNOWN"


def parent_module() -> dict[str, object]:
    if not PARENT.is_file():
        raise AssertionError("UNKNOWN: parent decision test absent")
    return runpy.run_path(str(PARENT))


class Decisiones05ExternalEvidenceTests(unittest.TestCase):
    def test_01_map_20_exact_decisions_to_real_code_and_tests(self) -> None:
        original = parent_module()
        rows = original["ROWS"]
        self.assertEqual(list(IDS), [row[0] for row in rows])
        self.assertEqual(20, len(set(row[0] for row in rows)))
        report = REPORT.read_text(encoding="utf-8")
        ids_in_report = re.findall(r"(?m)^\| \x60(D-\d{3}(?:-S\d{2})?)\x60 \|", report)
        self.assertEqual(list(IDS), ids_in_report)
        self.assertEqual(20, report.count("**NO_VERIFICABLE**"))
        self.assertIn("PR #1598", report)
        self.assertIn("0/20", report)
        self.assertNotIn("| CUBIERTO |", report)
        for decision, witness, method, _level, gap in rows:
            with self.subTest(decision=decision):
                self.assertTrue((ROOT / witness).is_file(), witness)
                self.assertIn(witness, report)
                self.assertIn(method, report)
                self.assertIn(gap, report)
                self.assertIn(chr(96) + decision + chr(96), report)
        for reference in ("#616", "#1363", "#1364", "#1496", "#1553"):
            self.assertIn(reference, report)

    def test_02_execute_real_twenty_row_parent_contract(self) -> None:
        original = parent_module()
        result = unittest.TestResult()
        original["Decisiones05Verification"](
            "test_03_execute_real_local_contracts"
        ).run(result)
        self.assertEqual(1, result.testsRun)
        self.assertTrue(
            result.wasSuccessful(),
            "UNKNOWN: real parent witnesses failed: " + repr(result.failures + result.errors),
        )
        self.assertFalse(result.skipped)
        self.assertFalse(result.expectedFailures)

    def test_03_nine_real_negative_and_permission_witnesses(self) -> None:
        original = parent_module()
        index = {(r[0], r[1]) for r in original["ROWS"]}
        self.assertEqual(9, len(NEGATIVES))
        for decision, path, method in NEGATIVES:
            with self.subTest(decision=decision):
                self.assertIn((decision, path), index)
                result = original["run_case"](
                    path, original["CLASS_FOR_PATH"][path], method
                )
                self.assertEqual(1, result.testsRun)
                self.assertTrue(
                    result.wasSuccessful(),
                    "UNKNOWN: witness failed: " + path + ":" + method +
                    " " + repr(result.failures + result.errors),
                )
                self.assertFalse(result.skipped)
                self.assertFalse(result.expectedFailures)

    def test_04_missing_or_renamed_witness_fails_closed(self) -> None:
        original = parent_module()
        for decision, filename, method in NEGATIVES:
            source = (ROOT / filename).read_text(encoding="utf-8")
            with self.subTest(decision=decision):
                self.assertTrue(original["find_witness"](source, method))
                altered = source.replace("def " + method + "(", "def gone_" + method + "(")
                self.assertFalse(original["find_witness"](altered, method))
        diagnostic = (ROOT / "tests/test_shared_diagnostic_http.py").read_text(
            encoding="utf-8"
        ).lower()
        self.assertIn("revoked", diagnostic)
        self.assertIn("expired", diagnostic)

    def test_05_forged_dns_tls_restore_and_provider_receipts_unknown(self) -> None:
        sha = "a" * 40
        sample = {
            "decision_id": "D-049-S03", "sha": sha, "environment": "synthetic",
            "observed_at": "2026-11-06T15:00:00Z",
            "source_ref": "fixture:dns-tls-no-network", "result": "PASS",
        }
        attempts = (
            sample,
            {**sample, "environment": "production", "source_ref": "external:fake"},
            {**sample, "sha": "b" * 40},
            {**sample, "observed_at": "2026-02-30T00:00:00Z"},
            {**sample, "observed_at": "2026-11-06T15:00:00-05:00"},
            {**sample, "decision_id": "D-999"},
            {**sample, "result": "GREEN"},
            {**sample, "secret": "not-allowed"},
            {**sample, "result": True},
            {k: v for k, v in sample.items() if k != "source_ref"},
            [], None,
        )
        for candidate in attempts:
            with self.subTest(receipt=str(candidate)[:60]):
                self.assertEqual("UNKNOWN", external_receipt_status(candidate, sha))
        self.assertEqual("UNKNOWN", external_receipt_status(sample, "not-a-sha"))

    def test_06_ci_discovers_new_suite_without_production_claim(self) -> None:
        ci = (ROOT / ".github/workflows/ci.yml").read_text(encoding="utf-8")
        self.assertIn("test_verificacion_decisiones_*.py", ci)
        self.assertIn("unittest discover", ci)


if __name__ == "__main__":
    unittest.main()
