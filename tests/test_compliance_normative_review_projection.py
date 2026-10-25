#!/usr/bin/env python3
"""F1b: executable offline acceptance against F0/F1a/F1b PHP, synthetic only."""
from __future__ import annotations

import json
import runpy
import subprocess
import unittest
from pathlib import Path

ROOT = Path(__file__).resolve().parents[1]
F0 = ROOT / "src/Application/Compliance/NormativeApplicability.php"
F1A = ROOT / "src/Application/Compliance/NormativeMatrix.php"
F1B = ROOT / "src/Application/Compliance/NormativeReviewProjection.php"
DOC = ROOT / "docs/legal/COMPLIANCE-REVIEW-F1B-OFFLINE.md"
F1A_TEST = runpy.run_path(str(ROOT / "tests/test_compliance_normative_matrix.py"))
profile = F1A_TEST["profile"]
rule = F1A_TEST["rule"]


def review(**overrides: object) -> dict[str, object]:
    item: dict[str, object] = {
        "tenant_ref": "tenant-a",
        "municipio": "pereira",
        "row_index": 0,
        "state": "EN_REVISION",
        "evidence_ref": "fixture:evidence/document-1",
        "reviewer_ref": "reviewer-synthetic",
        "reviewed_on": "2026-03-10",
    }
    item.update(overrides)
    return item


def project(
    rules: list[object] | None = None,
    context: dict[str, str] | None = None,
    reviews: list[object] | None = None,
    as_of: str = "2026-10-24",
) -> dict:
    script = r"""
require $argv[1];
require $argv[2];
require $argv[3];
use App\Application\Compliance\NormativeReviewProjection;
$data = json_decode($argv[4], true, 32, JSON_THROW_ON_ERROR);
echo json_encode(NormativeReviewProjection::evaluate(
    $data['rules'], $data['context'], $data['date'], $data['reviews']
), JSON_THROW_ON_ERROR);
"""
    payload = {
        "rules": [rule()] if rules is None else rules,
        "context": profile() if context is None else context,
        "date": as_of,
        "reviews": [] if reviews is None else reviews,
    }
    completed = subprocess.run(
        ["php", "-r", script, str(F0), str(F1A), str(F1B), json.dumps(payload)],
        cwd=ROOT, text=True, capture_output=True, check=True, timeout=30,
    )
    return json.loads(completed.stdout)


class NormativeReviewProjectionTests(unittest.TestCase):
    def test_unknown_evidence_never_certifies_compliance(self) -> None:
        pending = project()["rows"][0]
        self.assertEqual("PENDIENTE", pending["state"])
        self.assertIsNone(pending["evidence_ref"])
        self.assertEqual("authority-synthetic", pending["source"]["issuer"])

        active = project(reviews=[review()])["rows"][0]
        self.assertEqual("EN_REVISION", active["state"])
        self.assertNotIn("CUMPLE", json.dumps(active))

        invalid_sources = [
            {**rule(), "approved": False},
            {**rule(), "reviewer_ref": ""},
            {**rule(), "source": {}},
            {**rule(), "verified_on": "2027-01-01"},
            {**rule(), "kind": "CUMPLE"},
        ]
        for source in invalid_sources:
            with self.subTest(source=source):
                result = project(rules=[source], reviews=[review()])["rows"][0]
                self.assertEqual("REQUIERE_REVISION", result["state"])
                self.assertIsNone(result["source"])
                self.assertIsNone(result["evidence_ref"])

        for bad_review in [
            review(state="CUMPLE"),
            review(evidence_ref="<document-raw-content>"),
            review(evidence_ref="https://tenant/private"),
            review(reviewer_ref=""),
            review(extra="untrusted"),
            review(reviewed_on="2026-02-30"),
        ]:
            with self.subTest(review=bad_review):
                row = project(reviews=[bad_review])["rows"][0]
                self.assertEqual("REQUIERE_REVISION", row["state"])
                self.assertIsNone(row["evidence_ref"])

        self.assertEqual(
            "REQUIERE_REVISION",
            project(reviews=[review(), review()])["rows"][0]["state"],
        )
        self.assertEqual(
            "REQUIERE_REVISION",
            project(context=profile(municipio="sin_dato"))["rows"][0]["state"],
        )

    def test_tenant_and_municipality_boundary_fails_closed(self) -> None:
        other = rule(
            "tenant-b", kind="PROHIBIDO",
            conditions={"sector": "tecnologia", "municipio": "bogota"},
            source={**rule()["source"], "issuer": "foreign-secret-source"},
        )
        ours = project(
            rules=[rule(), other],
            reviews=[review(tenant_ref="tenant-b", evidence_ref="fixture:evidence/foreign")],
        )
        self.assertEqual(("tenant-a", "pereira"), (ours["tenant_ref"], ours["municipio"]))
        self.assertEqual(1, len(ours["rows"]))
        self.assertEqual("PENDIENTE", ours["rows"][0]["state"])
        self.assertNotIn("foreign", json.dumps(ours))
        self.assertNotIn("tenant-b", json.dumps(ours))

        other_municipio = project(reviews=[review(municipio="bogota")])
        self.assertEqual("PENDIENTE", other_municipio["rows"][0]["state"])
        validated = project(reviews=[review()])
        self.assertEqual("EN_REVISION", validated["rows"][0]["state"])

        no_aplica = project(context=profile(municipio="bogota"))
        self.assertEqual("NO_APLICA", no_aplica["rows"][0]["state"])
        self.assertIsNone(no_aplica["rows"][0]["evidence_ref"])

        foreign = project(
            rules=[rule(), other],
            context=profile("tenant-b", sector="tecnologia", municipio="bogota"),
            reviews=[review(tenant_ref="tenant-b", municipio="bogota")],
        )
        self.assertEqual(1, len(foreign["rows"]))
        self.assertEqual("EN_REVISION", foreign["rows"][0]["state"])
        self.assertNotIn("authority-synthetic", json.dumps(foreign))

    def test_revision_provenance_and_dates_are_preserved(self) -> None:
        item = rule(kind="CONDICIONAL")
        snapshot = project(rules=[item], reviews=[review()])
        row = snapshot["rows"][0]
        self.assertEqual("EN_REVISION", row["state"])
        self.assertEqual(item["source"], row["source"])
        self.assertEqual(item["reviewer_ref"], row["normative_reviewer_ref"])
        self.assertEqual(item["verified_on"], row["verified_on"])
        self.assertEqual(item["effective_from"], row["effective_from"])
        self.assertEqual(item["effective_until"], row["effective_until"])
        self.assertEqual("2026-03-10", row["reviewed_on"])
        self.assertEqual("fixture:evidence/document-1", row["evidence_ref"])
        self.assertEqual(snapshot, project(rules=[item], reviews=[review()]))

        for invalid in (
            "2026-01-01",  # before F1a human verification
            "2027-01-01",  # after as-of and normative expiration
            "not-a-date", "2026-02-30",
        ):
            with self.subTest(invalid=invalid):
                row = project(reviews=[review(reviewed_on=invalid)])["rows"][0]
                self.assertEqual("REQUIERE_REVISION", row["state"])
                self.assertIsNone(row["source"])
                self.assertIsNone(row["reviewed_on"])
        self.assertEqual(
            "REQUIERE_REVISION",
            project(as_of="bad-date")["rows"][0]["state"],
        )

    def test_missing_scope_review_fails_closed(self) -> None:
        missing_tenant = review()
        missing_tenant.pop("tenant_ref")
        missing_municipio = review()
        missing_municipio.pop("municipio")
        for candidate in (
            "invalid", None, {},
            missing_tenant, missing_municipio,
            review(tenant_ref="unknown"),
            review(municipio="sin_dato"),
        ):
            with self.subTest(candidate=candidate):
                row = project(reviews=[candidate])["rows"][0]
                self.assertEqual("REQUIERE_REVISION", row["state"])
                self.assertIsNone(row["source"])
                self.assertIsNone(row["evidence_ref"])

    def test_invalid_same_scope_index_fails_closed(self) -> None:
        missing_index = review()
        missing_index.pop("row_index")
        for candidate in (
            missing_index, review(row_index=None),
            review(row_index=-1), review(row_index=999),
            review(row_index="0"), review(row_index=True),
        ):
            with self.subTest(candidate=candidate):
                row = project(reviews=[candidate])["rows"][0]
                self.assertEqual("REQUIERE_REVISION", row["state"])
                self.assertIsNone(row["evidence_ref"])

        # A valid review cannot erase uncertainty from another same-scope input.
        row = project(reviews=[review(), review(row_index=-1)])["rows"][0]
        self.assertEqual("REQUIERE_REVISION", row["state"])
        self.assertIsNone(row["evidence_ref"])

        # Invalid own-scope indices may refer to any of several applicable rules.
        multi = project(rules=[rule(), rule()], reviews=[review(row_index=999)])
        self.assertEqual(2, len(multi["rows"]))
        self.assertEqual(["REQUIERE_REVISION"] * 2,
                         [item["state"] for item in multi["rows"]])

    def test_explicit_foreign_scope_stays_isolated(self) -> None:
        foreign_tenant = review(tenant_ref="tenant-b", evidence_ref="fixture:evidence/foreign")
        foreign_municipio = review(municipio="bogota", evidence_ref="fixture:evidence/foreign")
        for candidate in (foreign_tenant, foreign_municipio):
            with self.subTest(candidate=candidate):
                pending = project(reviews=[candidate])["rows"][0]
                self.assertEqual("PENDIENTE", pending["state"])
                self.assertNotIn("foreign", json.dumps(pending))

                local = project(reviews=[candidate, review()])["rows"][0]
                self.assertEqual("EN_REVISION", local["state"])
                self.assertEqual("fixture:evidence/document-1", local["evidence_ref"])
                self.assertNotIn("foreign", json.dumps(local))

    def test_offline_no_data_or_provider_side_effects(self) -> None:
        php = F1B.read_text(encoding="utf-8").lower()
        docs = DOC.read_text(encoding="utf-8").lower()
        for forbidden in (
            "pdo(", "doctrine", "curl_", "file_get_contents(",
            "shell_exec(", "exec(", "fetch(", "httpclient",
            "entitymanager", "'cumple'", '"cumple"',
        ):
            self.assertNotIn(forbidden, php)
        for required in (
            "sin datos personales", "sin bd, api ni ui", "no deploy",
            "revisión profesional", "no certifica cumplimiento",
            "sin activación operativa", "sin proveedores",
        ):
            self.assertIn(required, docs)
        self.assertEqual([], project(rules=[])["rows"])
        self.assertNotIn("CUMPLE", json.dumps(project(reviews=[review()])))
        self.assertEqual("REQUIERE_REVISION", project(reviews=["invalid"])["rows"][0]["state"])


if __name__ == "__main__":
    unittest.main()
