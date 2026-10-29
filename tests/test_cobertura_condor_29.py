#!/usr/bin/env python3
"""#1348: executable offline evidence for Condor README sections 7–10.

The executable subset uses genuine application/reporting and release-policy
functions with synthetic records. It does not read coverage-state documents,
make HTTP/DB calls or claim production/backup validation. Institutional and
external obligations are intentionally not promoted by this test.
"""
from __future__ import annotations

import copy
import json
import os
from pathlib import Path
import shutil
import subprocess
import sys
import unittest

ROOT = Path(__file__).resolve().parents[1]
SCRIPTS = ROOT / "scripts"
if str(SCRIPTS) not in sys.path:
    sys.path.insert(0, str(SCRIPTS))
import release_evidence

IDS = frozenset((
    "README-07-02", "README-07-03", "README-07-04",
    "README-08-01", "README-08-02", "README-08-03", "README-08-04",
    "README-09-01", "README-09-02",
    "README-10-01", "README-10-02", "README-10-03",
))
LOCAL = frozenset(("README-07-02", "README-07-03", "README-07-04"))
DECLARATIVE = {
    "README-08-01": "C: roadmap and issue priority are GitHub governance, not a runtime function",
    "README-08-02": "C: specifications and module guide are normative product references",
    "README-08-03": "C: local authority of agent/decision/data files is an operational rule",
    "README-08-04": "C: provenance of workflows/releases/Factory@v1 is external governance",
    "README-09-01": "C: dependency installation instructions, not a product operation",
    "README-09-02": "C: mandate to run PHPStan/Rector, enforced by repository CI",
    "README-10-01": "C: Factory role is an architecture/organizational description",
    "README-10-02": "C: ControlBot private control-plane role is organizational",
    "README-10-03": "C: FactoryRunner execution-plane role is organizational",
}
# Limit of local evidence: the integrated obligation requires authenticated
# HTTP/database/release/production evidence from outside this isolated test.
EXTERNAL_COMPLETION = {
    "README-07-02": "B: authorized API routes and cross-tenant HTTP require authenticated integration",
    "README-07-03": "B: actual backup, deploy, rollback and schema compatibility require infrastructure",
    "README-07-04": "B: real deploy identity and production observation require authoritative observer",
}

REPORT_SOURCE = ROOT / "src/Application/Reporting/TenantCsvReportService.php"
MIGRATION_POLICY = ROOT / "scripts/live-schema-policy.php"

REPORT_PROBE = r'''
declare(strict_types=1);
require $argv[1];
use App\Application\Reporting\TenantCsvReportService;
$reports = new TenantCsvReportService();
$rows = [
    ['tenant_id'=>'tenant-alpha', 'occurred_at'=>'2026-10-20',
     'record_id'=>'OWN-001', 'source_id'=>'fixture-site',
     'variant_id'=>'fixture-sku', 'quantity'=>'3', 'reserved_quantity'=>'0'],
    ['tenant_id'=>'tenant-beta', 'occurred_at'=>'2026-10-20',
     'record_id'=>'FOREIGN-002', 'source_id'=>'foreign-site',
     'variant_id'=>'private-sku', 'quantity'=>'9', 'reserved_quantity'=>'0'],
    ['tenant_id'=>'tenant-alpha', 'occurred_at'=>'2026-11-01',
     'record_id'=>'FUTURE-003', 'source_id'=>'fixture-site',
     'variant_id'=>'fixture-sku', 'quantity'=>'1', 'reserved_quantity'=>'0'],
];
$good = $reports->build(
    'inventory', 'tenant-alpha', '2026-10-20', '2026-10-21', $rows
);
$formulaRows = [$rows[0]];
$formulaRows[0]['record_id'] = '=1+1';
$formula = $reports->build(
    'inventory', 'tenant-alpha', '2026-10-20', '2026-10-21', $formulaRows
);
$denial = static function (callable $action): bool {
    try { $action(); return false; }
    catch (DomainException) { return true; }
};
$denied = [
    $denial(fn() => $reports->build('inventory', '', '2026-10-20', '2026-10-21', $rows)),
    $denial(fn() => $reports->build('internal', 'tenant-alpha', '2026-10-20', '2026-10-21', $rows)),
    $denial(fn() => $reports->build('inventory', 'tenant-alpha', '2026-02-31', '2026-10-21', $rows)),
    $denial(fn() => $reports->build('inventory', 'tenant-alpha', '2026-10-21', '2026-10-20', $rows)),
    $denial(fn() => $reports->build('inventory', 'tenant-alpha',
        '2026-10-20', '2026-10-21', [['tenant_id'=>'tenant-alpha',
                                      'occurred_at'=>'2026-10-20']])),
    $denial(fn() => $reports->build('inventory', 'tenant-alpha',
        '2026-10-20', '2026-10-21', [['tenant_id'=>'tenant-beta',
                                      'occurred_at'=>'not-a-date']])),
];
echo json_encode([
    'content_type'=>$good['content_type'],
    'body'=>$good['body'],
    'formula_body'=>$formula['body'],
    'denied'=>$denied,
], JSON_THROW_ON_ERROR);
'''


def run_offline(args: list[str], payload: object | None = None) -> dict:
    executable = shutil.which(args[0])
    if executable is None:
        raise AssertionError("UNKNOWN: required PHP CLI unavailable")
    if not REPORT_SOURCE.is_file() or not MIGRATION_POLICY.is_file():
        raise AssertionError("UNKNOWN: required real application sources missing")
    env = {key: os.environ[key] for key in ("PATH", "LANG", "LC_ALL")
           if key in os.environ}
    result = subprocess.run(
        [executable, *args[1:]], cwd=ROOT, env=env,
        input=json.dumps(payload) if payload is not None else None,
        text=True, capture_output=True, check=False, timeout=40,
    )
    if result.returncode or result.stderr.strip():
        raise AssertionError("UNKNOWN: real PHP contract failed: "
                             + (result.stderr + result.stdout)[-1100:])
    try:
        response = json.loads(result.stdout)
    except ValueError as exc:
        raise AssertionError("UNKNOWN: malformed PHP evidence") from exc
    if not isinstance(response, dict):
        raise AssertionError("UNKNOWN: missing structured PHP result")
    return response


def tenant_report() -> dict:
    return run_offline(["php", "-d", "allow_url_fopen=0", "-r",
                        REPORT_PROBE, str(REPORT_SOURCE)])


def migration_payload() -> dict:
    return {
        "classification": "additive",
        "dry_run_valid": True, "allowlist_complete": True,
        "backup_receipt_verified": True,  # synthetic fixture flag, not a receipt
        "post_checks": {
            "service_readiness": True, "schema": True, "smoke": True,
        },
    }


def migration_decision(data: object) -> dict:
    return run_offline(
        ["php", "-d", "allow_url_fopen=0", str(MIGRATION_POLICY)], data
    )


def observed_state(*, same_identity: bool = True,
                   state: str = "VALIDATED_IN_PRODUCTION",
                   healthy: bool = True,
                   pending: tuple[str, ...] = ()) -> str:
    return release_evidence.release_state(
        same_identity=same_identity,
        observation_state=state,
        public={"health": {"ok": healthy}, "home": {"ok": True}},
        required_pending=list(pending),
    )


class CoberturaCondor29Tests(unittest.TestCase):
    def test_contrato_principal(self) -> None:
        self.assertEqual(len(IDS), 12)
        self.assertEqual(IDS, LOCAL | DECLARATIVE.keys())
        self.assertEqual(LOCAL, EXTERNAL_COMPLETION.keys())

        # README-07-02: real projection protects tenant/date from untrusted rows.
        report = tenant_report()
        self.assertEqual(report["content_type"], "text/csv; charset=utf-8")
        self.assertIn("OWN-001", report["body"])
        self.assertNotIn("FOREIGN-002", report["body"])
        self.assertNotIn("private-sku", report["body"])
        self.assertNotIn("FUTURE-003", report["body"])
        self.assertIn("'=1+1", report["formula_body"])

        # README-07-03: additive schema change has explicit human authority.
        decision = migration_decision(migration_payload())
        self.assertEqual(decision["classification"], "additive")
        self.assertTrue(decision["eligible_after_owner_gate"])
        self.assertFalse(decision["auto_execute"])
        self.assertEqual(decision["authority"], "owner_gate_required")

        # README-07-04: observed deploy and validated production remain distinct.
        self.assertEqual(observed_state(state="DEPLOY_OBSERVED"),
                         "DEPLOY_OBSERVED")
        self.assertEqual(observed_state(), "VALIDATED_IN_PRODUCTION")
        self.assertTrue(all(DECLARATIVE.values()))
        self.assertTrue(all(EXTERNAL_COMPLETION.values()))

    def test_falla_cerrado(self) -> None:
        self.assertEqual(tenant_report()["denied"], [True] * 6)

        for field in ("dry_run_valid", "allowlist_complete",
                      "backup_receipt_verified"):
            with self.subTest(field=field):
                payload = migration_payload()
                payload[field] = False
                decision = migration_decision(payload)
                self.assertFalse(decision["eligible_after_owner_gate"])
                self.assertFalse(decision["auto_execute"])

        for classification in ("destructive", "unknown", "INVALID"):
            with self.subTest(classification=classification):
                payload = migration_payload()
                payload["classification"] = classification
                decision = migration_decision(payload)
                self.assertFalse(decision["eligible_after_owner_gate"])
                self.assertFalse(decision["auto_execute"])

        payload = migration_payload()
        del payload["post_checks"]["smoke"]
        self.assertEqual(migration_decision(payload)["reason"],
                         "evidence_incomplete")

        self.assertEqual(observed_state(same_identity=False), "NO_OBSERVADO")
        self.assertEqual(observed_state(healthy=False), "NO_OBSERVADO")
        self.assertEqual(observed_state(state="NO_OBSERVADO"), "NO_OBSERVADO")
        self.assertEqual(observed_state(pending=("migraciones",)),
                         "DEPLOY_OBSERVED")
        with self.assertRaises(release_evidence.EvidenceError):
            observed_state(state="CI_GREEN")

    def test_sin_datos_reales(self) -> None:
        self.assertEqual(IDS, LOCAL | DECLARATIVE.keys())
        self.assertIn("tenant-alpha", REPORT_PROBE)
        self.assertIn("tenant-beta", REPORT_PROBE)
        self.assertIn("fixture-site", REPORT_PROBE)
        for forbidden in ("curl_", "fsockopen(", "mysqli_", "PDO(", "Bearer ",
                          "http://", "https://", "password=", "api_key="):
            self.assertNotIn(forbidden, REPORT_PROBE)
        self.assertEqual(migration_decision(migration_payload())["auto_execute"],
                         False)
        self.assertEqual(observed_state(state="DEPLOY_OBSERVED"),
                         "DEPLOY_OBSERVED")

    def test_ci_gate_contract(self) -> None:
        # The real D-043 classifier cannot promote an arbitrary CI badge.
        self.assertEqual(observed_state(state="NO_OBSERVADO"), "NO_OBSERVADO")
        self.assertEqual(observed_state(same_identity=False), "NO_OBSERVADO")
        self.assertEqual(observed_state(pending=("backup",)),
                         "DEPLOY_OBSERVED")
        self.assertEqual(migration_decision(migration_payload())["authority"],
                         "owner_gate_required")


if __name__ == "__main__":
    unittest.main()
