#!/usr/bin/env python3
"""Offline F1a acceptance invokes the real F0 and matrix PHP contracts."""
from __future__ import annotations

import json
import subprocess
import unittest
from pathlib import Path

ROOT = Path(__file__).resolve().parents[1]
F0 = ROOT / "src/Application/Compliance/NormativeApplicability.php"
MATRIX = ROOT / "src/Application/Compliance/NormativeMatrix.php"
DOC = ROOT / "docs/legal/COMPLIANCE-MATRIX-F1-OFFLINE.md"


def profile(tenant: str = "tenant-a", **overrides: str) -> dict[str, str]:
    value = {
        "tenant_ref": tenant, "sector": "comercio", "ciiu": "4711",
        "municipio": "pereira", "riesgo": "ii",
        "tamano_empresa": "micro", "regimen": "regimen-a",
    }
    value.update(overrides)
    return value


def rule(tenant: str = "tenant-a", **overrides: object) -> dict[str, object]:
    value: dict[str, object] = {
        "tenant_ref": tenant, "kind": "OBLIGATORIO", "approved": True,
        "reviewer_ref": "reviewer-synthetic",
        "source": {
            "kind": "official", "issuer": "authority-synthetic",
            "article": "article-synthetic", "version": "v1",
            "official_url": "https://normas.example.invalid/synthetic/1",
        },
        "effective_from": "2026-01-01", "effective_until": "2026-12-31",
        "verified_on": "2026-02-01",
        "conditions": {"sector": "comercio", "municipio": "pereira"},
    }
    value.update(overrides)
    return value


def matrix(items: list[object], context: dict[str, str], when: str = "2026-10-24") -> dict:
    script = r"""
require $argv[1];
require $argv[2];
use App\Application\Compliance\NormativeMatrix;
$data = json_decode($argv[3], true, 32, JSON_THROW_ON_ERROR);
echo json_encode(NormativeMatrix::evaluate($data['rules'], $data['context'], $data['date']), JSON_THROW_ON_ERROR);
"""
    completed = subprocess.run(
        ["php", "-r", script, str(F0), str(MATRIX),
         json.dumps({"rules": items, "context": context, "date": when})],
        cwd=ROOT, text=True, capture_output=True, check=True,
    )
    return json.loads(completed.stdout)


class NormativeMatrixTests(unittest.TestCase):
    def test_unverified_norms_and_unknown_context_fail_closed(self) -> None:
        base = rule()
        for invalid in (
            {**base, "approved": False},
            {**base, "reviewer_ref": ""},
            {**base, "source": {}},
            {**base, "source": {**base["source"], "kind": "unofficial"}},
            {**base, "verified_on": "2027-01-01"},
            {**base, "effective_until": "2026-01-01"},
            {**base, "kind": "CUMPLE"},
            {**base, "extra": "untrusted"},
            {key: val for key, val in base.items() if key != "tenant_ref"},
        ):
            with self.subTest(invalid=invalid):
                row = matrix([invalid], profile())["rows"][0]
                self.assertEqual("REQUIERE_REVISION", row["kind"])
                self.assertIsNone(row["source"])
                self.assertIsNone(row["reviewer_ref"])

        for context in (profile(municipio="unknown"), profile(regimen="sin_dato"),
                        {"tenant_ref": "tenant-a"}, profile(tenant="desconocido")):
            with self.subTest(context=context):
                self.assertEqual(
                    "REQUIERE_REVISION", matrix([base], context)["rows"][0]["kind"]
                )
        for date in ("2027-01-01", "2026-02-30"):
            self.assertEqual(
                "REQUIERE_REVISION", matrix([base], profile(), date)["rows"][0]["kind"]
            )

    def test_tenant_and_jurisdiction_are_isolated(self) -> None:
        first = rule()
        second = rule(
            "tenant-b", kind="PROHIBIDO",
            source={**rule()["source"], "issuer": "authority-other-tenant"},
            conditions={"sector": "tecnologia", "municipio": "bogota"},
        )
        a = matrix([first, second], profile())
        b = matrix([first, second], profile("tenant-b", sector="tecnologia", municipio="bogota"))
        self.assertEqual(("tenant-a", "pereira"), (a["tenant_ref"], a["municipio"]))
        self.assertEqual(1, len(a["rows"]))
        self.assertEqual("OBLIGATORIO", a["rows"][0]["kind"])
        self.assertNotIn("authority-other-tenant", json.dumps(a))
        self.assertNotIn("tenant-b", json.dumps(a))
        self.assertEqual(("tenant-b", "bogota"), (b["tenant_ref"], b["municipio"]))
        self.assertEqual(1, len(b["rows"]))
        self.assertEqual("PROHIBIDO", b["rows"][0]["kind"])
        self.assertNotIn("authority-synthetic", json.dumps(b))
        self.assertEqual("NO_APLICA", matrix([first], profile(municipio="bogota"))["rows"][0]["kind"])
        self.assertEqual(
            "REQUIERE_REVISION",
            matrix([first], profile(municipio="pendiente"))["rows"][0]["kind"],
        )

    def test_evidence_provenance_and_revision_are_preserved(self) -> None:
        item = rule(kind="CONDICIONAL")
        result = matrix([item], profile())
        row = result["rows"][0]
        self.assertEqual(("CONDICIONAL", "APLICA"), (row["kind"], row["applicability"]))
        for field in ("source", "reviewer_ref", "verified_on", "effective_from", "effective_until"):
            self.assertEqual(item[field], row[field], field)
        self.assertIn("vigencia", row["explanation"])
        other = matrix([item], profile(sector="tecnologia"))["rows"][0]
        self.assertEqual("NO_APLICA", other["kind"])
        self.assertEqual(item["source"], other["source"])
        self.assertEqual(item["reviewer_ref"], other["reviewer_ref"])
        self.assertEqual(result, matrix([item], profile()))

    def test_offline_matrix_does_not_certify_or_activate_runtime(self) -> None:
        body = DOC.read_text(encoding="utf-8").lower()
        source = MATRIX.read_text(encoding="utf-8").lower()
        for text in (
            "sin datos personales", "sin activación operativa",
            "no certifica cumplimiento", "revisión profesional",
            "sin bd, api ni ui", "no deploy",
        ):
            self.assertIn(text, body)
        for forbidden in (
            "pdo(", "doctrine", "curl_", "file_get_contents(",
            "shell_exec(", "exec(", "fetch(", "httpclient", "entitymanager",
        ):
            self.assertNotIn(forbidden, source)
        self.assertNotIn("'cumple'", source)
        self.assertNotIn('"cumple"', source)
        self.assertEqual([], matrix([], profile())["rows"])


if __name__ == "__main__":
    unittest.main()
