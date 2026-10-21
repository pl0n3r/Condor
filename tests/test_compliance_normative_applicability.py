#!/usr/bin/env python3
"""Executable F0 offline acceptance: synthetic rules, no network/DB/PII."""
from __future__ import annotations

import json
import subprocess
import unittest
from pathlib import Path

ROOT = Path(__file__).resolve().parents[1]
PHP = ROOT / "src/Application/Compliance/NormativeApplicability.php"
DOC = ROOT / "docs/legal/COMPLIANCE-NORMATIVE-F0.md"


def profile(tenant: str = "tenant-a", **overrides: str) -> dict[str, str]:
    result = {"tenant_ref": tenant, "sector": "comercio", "ciiu": "4711",
              "municipio": "pereira", "riesgo": "ii"}
    result.update(overrides)
    return result


def rule(tenant: str = "tenant-a", **overrides: object) -> dict[str, object]:
    base: dict[str, object] = {
        "tenant_ref": tenant,
        "kind": "OBLIGATORIO",
        "approved": True,
        "reviewer_ref": "reviewer-synthetic",
        "source": {
            "kind": "official", "issuer": "authority-synthetic",
            "article": "article-synthetic", "version": "v1",
            "official_url": "https://normas.example.invalid/synthetic/1",
        },
        "effective_from": "2026-01-01", "effective_until": "2026-12-31",
        "verified_on": "2026-02-01",
        "conditions": {"sector": "comercio", "ciiu": "4711", "municipio": "pereira", "riesgo": "ii"},
    }
    base.update(overrides)
    return base


def evaluate(item: dict[str, object], context: dict[str, str], date: str = "2026-10-21") -> dict[str, object]:
    script = r"""
require $argv[1];
use App\Application\Compliance\NormativeApplicability;
$data = json_decode($argv[2], true, 32, JSON_THROW_ON_ERROR);
echo json_encode(NormativeApplicability::evaluate($data['rule'], $data['context'], $data['date']), JSON_THROW_ON_ERROR);
"""
    completed = subprocess.run(
        ["php", "-r", script, str(PHP), json.dumps({"rule": item, "context": context, "date": date})],
        cwd=ROOT, text=True, capture_output=True, check=True,
    )
    return json.loads(completed.stdout)


class NormativeApplicabilityTests(unittest.TestCase):
    def test_unknown_and_unapproved_rule_require_review(self) -> None:
        base = rule()
        for item in [
            {**base, "approved": False},
            {**base, "reviewer_ref": ""},
            {**base, "source": {}},
            {**base, "source": {**base["source"], "kind": "uncorroborated"}},
            {**base, "verified_on": ""},
            {**base, "kind": "CUMPLE"},
            {**base, "conditions": {}},
            {**base, "extra": "untrusted"},
        ]:
            with self.subTest(item=item):
                self.assertEqual("REQUIERE_REVISION", evaluate(item, profile())["kind"])
        self.assertEqual("REQUIERE_REVISION", evaluate(base, {"tenant_ref": "tenant-a"})["kind"])

    def test_scoped_verified_rule_preserves_kind_and_source(self) -> None:
        for kind in ("OBLIGATORIO", "CONDICIONAL", "PROHIBIDO", "RECOMENDADO"):
            with self.subTest(kind=kind):
                item = rule(kind=kind)
                outcome = evaluate(item, profile())
                self.assertEqual(kind, outcome["kind"])
                self.assertEqual("APLICA", outcome["applicability"])
                self.assertEqual(item["source"], outcome["source"])
                self.assertIn("vigencia", outcome["explanation"])
        outcome = evaluate(rule(), profile(ciiu="6201"))
        self.assertEqual("NO_APLICA", outcome["kind"])
        self.assertEqual("NO_APLICA", outcome["applicability"])
        self.assertIsNotNone(outcome["source"])

    def test_tenant_context_and_dates_fail_closed(self) -> None:
        a = profile()
        b = profile(tenant="tenant-b", sector="tecnologia", ciiu="6201", municipio="bogota", riesgo="iv")
        self.assertEqual("REQUIERE_REVISION", evaluate(rule(), b)["kind"])
        different = rule(
            tenant="tenant-b",
            conditions={"sector": "tecnologia", "ciiu": "6201", "municipio": "bogota", "riesgo": "iv"},
        )
        self.assertEqual("OBLIGATORIO", evaluate(different, b)["kind"])
        for dimension, other in (("sector", "comercio"), ("ciiu", "4711"),
                                 ("municipio", "pereira"), ("riesgo", "ii")):
            self.assertEqual("NO_APLICA", evaluate(different, {**b, dimension: other})["kind"])
        self.assertEqual("REQUIERE_REVISION", evaluate(different, a)["kind"])
        for date in ("2025-12-31", "2027-01-01", "2026-02-30", "tomorrow"):
            self.assertEqual("REQUIERE_REVISION", evaluate(rule(), a, date)["kind"])
        self.assertEqual("REQUIERE_REVISION", evaluate(rule(verified_on="2026-11-01"), a)["kind"])
        self.assertEqual("REQUIERE_REVISION", evaluate(rule(conditions={"biometrics": "yes"}), a)["kind"])
        self.assertEqual("REQUIERE_REVISION", evaluate(rule(), {**a, "email": "sensitive"})["kind"])

    def test_documentation_declares_no_operational_activation(self) -> None:
        body = DOC.read_text(encoding="utf-8")
        for clause in ("sin activación operativa", "revisión profesional", "no certifica cumplimiento", "sin datos personales"):
            self.assertIn(clause, body.lower())
        self.assertNotIn('"CUMPLE"', PHP.read_text(encoding="utf-8"))


if __name__ == "__main__":
    unittest.main()
