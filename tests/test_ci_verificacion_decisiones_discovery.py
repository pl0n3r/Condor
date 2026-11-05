#!/usr/bin/env python3
"""#1610: el CI debe ejecutar los lotes verificables, no solo citarlos."""
from __future__ import annotations

import unittest
from pathlib import Path

ROOT = Path(__file__).resolve().parents[1]
WORKFLOW = ROOT / ".github/workflows/ci.yml"
STEP = "      - name: Probar hojas de verificación de decisiones"
PATTERN = "tests/test_verificacion_decisiones_*.py"


class VerificacionDecisionesDiscoveryTests(unittest.TestCase):
    @classmethod
    def setUpClass(cls):
        cls.workflow = WORKFLOW.read_text(encoding="utf-8")
        cls.block = cls.workflow.split(STEP, 1)[1].split(
            "      - name:", 1
        )[0]

    def test_job_invoca_todos_los_lotes_verificables(self):
        self.assertEqual(self.workflow.count(STEP), 1)
        self.assertIn("if compgen -G '" + PATTERN + "' > /dev/null", self.block)
        self.assertIn(
            "python3 -m unittest discover -s tests -p '" + PATTERN + "'",
            self.block,
        )
        self.assertIn("set -euo pipefail", self.block)

    def test_sin_lotes_no_inventa_cobertura(self):
        self.assertIn("NO_VERIFICABLE:", self.block)
        self.assertIn("no se atribuye cobertura", self.block)

    def test_ejecucion_despues_de_composer_y_contraste(self):
        wf = self.workflow
        self.assertLess(
            wf.index("Preparar autoload Composer para cobertura Condor 28"),
            wf.index(STEP),
        )
        self.assertLess(wf.index("Probar contratos de contraste D"), wf.index(STEP))
        self.assertLess(wf.index(STEP), wf.index("Probar brechas Condor 05 si están presentes"))


if __name__ == "__main__":
    unittest.main()
