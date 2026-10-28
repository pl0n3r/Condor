"""CI contract for M01 #1094. Real AC-01..04 run under Composer-enabled PHP CI."""
from __future__ import annotations

import json
import unittest
from pathlib import Path

ROOT = Path(__file__).resolve().parents[1]
NAMES = (
    "test_aislamiento_por_empresa",
    "test_permisos_y_auditoria",
    "test_sin_datos_reales",
    "test_estados_explicitos",
)


class HumanResourcesCIContractTests(unittest.TestCase):
    def test_acceptance_targets_and_real_php_bridge(self) -> None:
        source = (ROOT / "tests/test_human_resources_ext_persistence.py").read_text(encoding="utf-8")
        php = (ROOT / "tests/php/Privacy/PrivacyAsCodeTest.php").read_text(encoding="utf-8")
        for name in NAMES:
            self.assertIn("def " + name + "(", source)
        self.assertIn("testHumanResourcesPersistenceRealPhp", php)
        self.assertIn("'test_human_resources_ext_persistence.py'", php)

    def test_synthetic_registration_remains_under_review(self) -> None:
        data = json.loads((ROOT / "datos.yml").read_text(encoding="utf-8"))
        rows = [row for row in data["treatments"] if row["id"] == "employee_synthetic_records"]
        self.assertEqual(1, len(rows))
        for key in ("purpose", "basis", "retention", "consent"):
            self.assertEqual("review_required", rows[0][key])
        self.assertEqual("construccion", data["phase"])
        self.assertEqual([], rows[0]["providers"])


if (ROOT / "vendor/autoload.php").exists():
    from test_human_resources_ext_persistence import ModuloTests
