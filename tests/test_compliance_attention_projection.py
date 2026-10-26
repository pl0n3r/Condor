#!/usr/bin/env python3
"""F2a synthetic-only acceptance: real PHP runtime, not source-text mocks."""
from __future__ import annotations

import copy
import json
import subprocess
import unittest
from pathlib import Path

ROOT = Path(__file__).resolve().parents[1]
CLASS = ROOT / "src/Application/Compliance/ComplianceAttentionProjection.php"
DOC = ROOT / "docs/legal/COMPLIANCE-ATTENTION-F2A-OFFLINE.md"


def projection(state: str = "EN_REVISION") -> dict:
    return {
        "tenant_ref": "tenant-a", "municipio": "pereira",
        "rows": [{
            "state": state,
            "source": {
                "kind": "official", "issuer": "authority-synthetic",
                "article": "article-synthetic", "version": "v1",
                "official_url": "https://normas.example.invalid/synthetic/1",
            },
            "normative_reviewer_ref": "reviewer-synthetic",
            "verified_on": "2026-02-01",
            "effective_from": "2026-01-01",
            "effective_until": "2026-12-31",
            "evidence_ref": "fixture:evidence/document-1" if state == "EN_REVISION" else None,
            "reviewer_ref": "reviewer-synthetic" if state == "EN_REVISION" else None,
            "reviewed_on": "2026-03-10" if state == "EN_REVISION" else None,
        }],
    }


def deadline(**overrides: object) -> dict:
    item = {
        "tenant_ref": "tenant-a", "municipio": "pereira", "row_index": 0,
        "deadline_ref": "fixture:deadline/notice-1", "due_on": "2026-10-21",
        "source_version": "v1", "verified_on": "2026-02-01",
    }
    item.update(overrides)
    return item


def project(
    upstream: dict | None = None,
    deadlines: list | None = None,
    reference_date: str = "2026-10-24",
) -> dict:
    payload = {
        "upstream": projection() if upstream is None else upstream,
        "deadlines": [deadline()] if deadlines is None else deadlines,
        "reference_date": reference_date,
    }
    script = r"""
require $argv[1];
use App\Application\Compliance\ComplianceAttentionProjection;
$data = json_decode($argv[2], true, 32, JSON_THROW_ON_ERROR);
echo json_encode(ComplianceAttentionProjection::project(
    $data['upstream'], $data['deadlines'], $data['reference_date']
), JSON_THROW_ON_ERROR);
"""
    result = subprocess.run(
        ["php", "-n", "-d", "allow_url_fopen=0", "-r", script,
         str(CLASS), json.dumps(payload)],
        cwd=ROOT, text=True, capture_output=True, check=True, timeout=20,
    )
    return json.loads(result.stdout)


class ComplianceAttentionProjectionTests(unittest.TestCase):
    def test_missing_deadline_provenance_never_fabricates_alert(self) -> None:
        good = project()["rows"][0]
        self.assertEqual("EN_REVISION", good["state"])
        self.assertTrue(good["attention_candidate"])
        self.assertEqual("fixture:deadline/notice-1", good["deadline_ref"])
        self.assertEqual("2026-10-21", good["due_on"])

        for missing in ([], [deadline(source_version="unknown")],
                        [deadline(verified_on="2026-02-02")],
                        [deadline(deadline_ref="https://customer.example/document")],
                        [deadline(due_on="invalid")]):
            with self.subTest(missing=missing):
                row = project(deadlines=missing)["rows"][0]
                self.assertEqual("REQUIERE_REVISION", row["state"])
                self.assertFalse(row["attention_candidate"])
                self.assertIsNone(row["deadline_ref"])
                self.assertIsNone(row["due_on"])

        # A F1b PENDIENTE can hide malformed review records; it is never trusted.
        for unsafe in ("PENDIENTE", "DESCONOCIDO", "CUMPLE", "REQUIERE_REVISION"):
            with self.subTest(unsafe=unsafe):
                row = project(upstream=projection(unsafe))["rows"][0]
                self.assertEqual("REQUIERE_REVISION", row["state"])
                self.assertFalse(row["attention_candidate"])
        self.assertNotIn("CUMPLE", json.dumps(project()))

    def test_cross_tenant_municipality_entries_are_excluded(self) -> None:
        foreign_tenant = deadline(tenant_ref="tenant-b", deadline_ref="fixture:deadline/private-b")
        foreign_municipio = deadline(municipio="bogota", deadline_ref="fixture:deadline/private-c")
        for other in (foreign_tenant, foreign_municipio):
            with self.subTest(foreign=other):
                result = project(deadlines=[other])
                self.assertEqual("tenant-a", result["tenant_ref"])
                self.assertEqual("pereira", result["municipio"])
                self.assertEqual("REQUIERE_REVISION", result["rows"][0]["state"])
                self.assertNotIn("private", json.dumps(result))
                self.assertNotIn("tenant-b", json.dumps(result))
                self.assertNotIn("bogota", json.dumps(result))

        result = project(deadlines=[foreign_tenant, foreign_municipio, deadline()])
        self.assertEqual("EN_REVISION", result["rows"][0]["state"])
        self.assertNotIn("private", json.dumps(result))

        # Unknown scope is NOT a known foreign scope, even with a valid deadline.
        for invalid in (
            {"row_index": 0, "due_on": "2026-10-21"},
            deadline(tenant_ref="unknown"),
            deadline(municipio="unknown"),
            None,
        ):
            with self.subTest(invalid_scope=invalid):
                row = project(deadlines=[deadline(), invalid])["rows"][0]
                self.assertEqual("REQUIERE_REVISION", row["state"])
                self.assertFalse(row["attention_candidate"])
                self.assertIsNone(row["deadline_ref"])
                self.assertIsNone(row["due_on"])
        self.assertEqual(
            "REQUIERE_REVISION",
            project(deadlines=[deadline(row_index=10)])["rows"][0]["state"],
        )
        self.assertEqual(
            "REQUIERE_REVISION",
            project(deadlines=[deadline(), deadline()])["rows"][0]["state"],
        )

    def test_deterministic_review_dates_and_stale_sources_fail_closed(self) -> None:
        first = project()
        self.assertEqual(first, project())
        future = project(reference_date="2026-10-20")["rows"][0]
        self.assertEqual("EN_REVISION", future["state"])
        self.assertFalse(future["attention_candidate"])

        upstream = projection()
        for patch in (
            {"verified_on": "2027-01-01"},
            {"effective_until": "2026-03-01"},
            {"reviewed_on": "2027-01-01"},
            {"reviewer_ref": None},
            {"source": {"version": "unknown"}},
            {"source": {"version": "v1"}},  # partial source used to fabricate attention
            {"source": {**upstream["rows"][0]["source"], "kind": "unofficial"}},
            {"source": {**upstream["rows"][0]["source"], "issuer": "unknown"}},
            {"source": {**upstream["rows"][0]["source"], "official_url": "https://external.example.com/norm"}},
        ):
            changed = copy.deepcopy(upstream)
            changed["rows"][0].update(patch)
            with self.subTest(patch=patch):
                self.assertEqual(
                    "REQUIERE_REVISION", project(upstream=changed)["rows"][0]["state"],
                )
        for date in ("2026-02-30", "2027-01-01", "bad-date"):
            with self.subTest(date=date):
                self.assertEqual(
                    "REQUIERE_REVISION", project(deadlines=[deadline(due_on=date)])["rows"][0]["state"],
                )
        self.assertEqual("REQUIERE_REVISION", project(reference_date="invalid")["rows"][0]["state"])

    def test_offline_pure_no_pii_or_provider_effects(self) -> None:
        code = CLASS.read_text(encoding="utf-8").lower()
        doc = DOC.read_text(encoding="utf-8").lower()
        for forbidden in (
            "pdo(", "doctrine", "curl_", "file_get_contents(",
            "shell_exec(", "exec(", "fetch(", "httpclient", "entitymanager",
            "'cumple'", '"cumple"',
        ):
            self.assertNotIn(forbidden, code)
        for phrase in (
            "sin datos personales", "sin bd, api ni ui", "no deploy",
            "revisión profesional", "no certifica cumplimiento",
            "sin activación operativa", "sin proveedores",
        ):
            self.assertIn(phrase, doc)
        # Independent, deterministic real-PHP executions without network access.
        self.assertEqual([], project(upstream={"tenant_ref": "tenant-a", "municipio": "pereira", "rows": []}, deadlines=[])["rows"])
        first = project()
        second = project()
        self.assertEqual(first, second)
        self.assertEqual("tenant-a", first["tenant_ref"])
        self.assertEqual("pereira", first["municipio"])
        self.assertEqual("EN_REVISION", first["rows"][0]["state"])
        self.assertTrue(first["rows"][0]["attention_candidate"])
        self.assertNotIn("tenant-b", json.dumps(project(deadlines=[deadline(tenant_ref="tenant-b")])))


if __name__ == "__main__":
    unittest.main()
