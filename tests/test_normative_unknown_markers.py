"""Tests conductuales de marcadores normativos desconocidos, solo con fixtures sintéticos."""

import copy
import json
from pathlib import Path
import shutil
import subprocess
import unittest


ROOT = Path(__file__).resolve().parents[1]
PHP_EVALUATOR = r"""
require 'src/Application/Compliance/NormativeApplicability.php';
$input = json_decode(stream_get_contents(STDIN), true, 512, JSON_THROW_ON_ERROR);
$result = \\App\\Application\\Compliance\\NormativeApplicability::evaluate(
    $input['rule'], $input['context'], $input['reference_date']
);
echo json_encode($result, JSON_THROW_ON_ERROR);
"""


class NormativeUnknownMarkersTests(unittest.TestCase):
    def setUp(self) -> None:
        self.rule = {
            "approved": True,
            "conditions": {"municipio": "bogota"},
            "effective_from": "2020-01-01",
            "effective_until": "2030-01-01",
            "kind": "OBLIGATORIO",
            "reviewer_ref": "revisor_ficticio",
            "source": {
                "article": "articulo_sintetico",
                "issuer": "autoridad_ficticia",
                "kind": "official",
                "official_url": "https://example.invalid/norma/1",
                "version": "v1",
            },
            "tenant_ref": "tenant_sintetico_a",
            "verified_on": "2026-01-01",
        }
        self.context = {
            "ciiu": "6201",
            "municipio": "bogota",
            "regimen": "ordinario",
            "riesgo": "bajo",
            "sector": "servicios",
            "tamano_empresa": "pequena",
            "tenant_ref": "tenant_sintetico_a",
        }

    def evaluate(self, rule: dict | None = None, context: dict | None = None) -> dict:
        php = shutil.which("php")
        self.assertIsNotNone(php, "PHP CLI es obligatorio: no se puede simular la prueba normativa")
        payload = {
            "rule": self.rule if rule is None else rule,
            "context": self.context if context is None else context,
            "reference_date": "2026-11-07",
        }
        result = subprocess.run(
            [php, "-r", PHP_EVALUATOR],
            cwd=ROOT,
            input=json.dumps(payload),
            text=True,
            capture_output=True,
            timeout=20,
            check=False,
        )
        self.assertEqual(result.returncode, 0, result.stderr)
        return json.loads(result.stdout)

    def assert_needs_review(self, result: dict) -> None:
        self.assertEqual(result["kind"], "REQUIERE_REVISION")
        self.assertEqual(result["applicability"], "DESCONOCIDO")
        self.assertIsNone(result["source"])

    def test_unknown_version_rejected(self) -> None:
        for unknown in ("UNKNOWN", "unknown", "DesCoNoCiDo", "pendiente", "sin_dato", "no_definido"):
            with self.subTest(version=unknown):
                rule = copy.deepcopy(self.rule)
                rule["source"]["version"] = unknown
                self.assert_needs_review(self.evaluate(rule=rule))

    def test_unknown_reviewer_rejected(self) -> None:
        for unknown in ("UNKNOWN", "unknown", "DesCoNoCiDo", "pendiente", "sin_dato", "no_definido"):
            with self.subTest(reviewer_ref=unknown):
                rule = copy.deepcopy(self.rule)
                rule["reviewer_ref"] = unknown
                self.assert_needs_review(self.evaluate(rule=rule))

    def test_valid_rule_and_condition_mismatch(self) -> None:
        valid = self.evaluate()
        self.assertEqual(valid["kind"], "OBLIGATORIO")
        self.assertEqual(valid["applicability"], "APLICA")
        self.assertEqual(valid["source"]["version"], "v1")
        other_city = dict(self.context, municipio="medellin")
        mismatch = self.evaluate(context=other_city)
        self.assertEqual(mismatch["kind"], "NO_APLICA")
        self.assertEqual(mismatch["applicability"], "NO_APLICA")
        other_tenant = dict(self.context, tenant_ref="tenant_sintetico_b")
        self.assert_needs_review(self.evaluate(context=other_tenant))


if __name__ == "__main__":
    unittest.main()
