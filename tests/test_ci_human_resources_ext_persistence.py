"""CI de aceptación M01: contrato de cuatro pruebas y declaración D-063."""
from __future__ import annotations

import json
import unittest
from pathlib import Path

ROOT = Path(__file__).resolve().parents[1]
SPEC = ROOT / "tests/test_human_resources_ext_persistence.py"
DATA = ROOT / "datos.yml"
EXPECTED = {
    "test_aislamiento_por_empresa",
    "test_permisos_y_auditoria",
    "test_sin_datos_reales",
    "test_estados_explicitos",
}


class HumanResourcesCIContractTests(unittest.TestCase):
    def test_acceptance_targets_and_real_php_bridge(self) -> None:
        import ast
        tree = ast.parse(SPEC.read_text(encoding="utf-8"))
        cases = next(n for n in tree.body if isinstance(n, ast.ClassDef) and n.name == "ModuloTests")
        actual = {n.name for n in cases.body if isinstance(n, ast.FunctionDef)}
        self.assertTrue(EXPECTED <= actual)
        php = (ROOT / "tests/php/Privacy/PrivacyAsCodeTest.php").read_text(encoding="utf-8")
        self.assertIn("testHumanResourcesPersistenceRealPhp", php)
        self.assertIn("'test_human_resources_ext_persistence.py'", php)

    def test_synthetic_registration_remains_under_review(self) -> None:
        policy = json.loads(DATA.read_text(encoding="utf-8"))
        self.assertEqual("construccion", policy["phase"])
        selected = [t for t in policy["treatments"] if t["id"] == "human_resources_synthetic_records"]
        self.assertEqual(len(selected), 1)
        self.assertEqual(selected[0]["category"], "usage")
        for field in ("purpose", "basis", "retention", "consent"):
            self.assertEqual(selected[0][field], "review_required")
        self.assertEqual(selected[0]["providers"], [])


if (ROOT / "vendor/autoload.php").exists():
    from test_human_resources_ext_persistence import ModuloTests
