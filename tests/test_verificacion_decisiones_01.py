#!/usr/bin/env python3
"""Condor #1544: pruebas reales ejecutadas, no promoción de cobertura live.

Cada testigo invoca aserciones de código auténtico; no es prueba de que
existan DNS, deploy, configuración integral ni datos de producción.
"""
from __future__ import annotations

import io
import json
import re
import sys
import unittest
from pathlib import Path

ROOT = Path(__file__).resolve().parents[1]
for directory in (ROOT, ROOT / "tests"):
    if str(directory) not in sys.path:
        sys.path.insert(0, str(directory))

WITNESSES = {
    "D-003": "test_contraste_d_l1.ContrasteDL1Tests.test_contrato_principal",
    "D-005": "test_release_identity_guard.ReleaseIdentityGuardTests.test_existing_tag_on_other_commit_fails_closed_without_mutation",
    "D-006": "test_brechas_condor_05.BrechasCondor05Tests.test_contrato_principal",
    "D-009": "test_release_identity_guard.ReleaseIdentityGuardTests.test_pr_title_version_must_match_canonical_version",
    "D-012": "test_contraste_d_l3.ContrasteDL3Tests.test_contrato_principal",
    "D-013": "test_brechas_condor_06.BrechasCondor06Tests.test_contrato_principal",
    "D-014": "test_ci_throughput_report.ThroughputTests.test_regression_requires_three_samples",
    "D-015": "test_contraste_d_l4.ContrasteDL4Tests.test_contrato_principal",
    "D-016": "test_contraste_d_l4.ContrasteDL4Tests.test_contrato_principal",
    "D-018": "test_contraste_d_l1.ContrasteDL1Tests.test_contrato_principal",
    "D-020": "test_contraste_d_l1.ContrasteDL1Tests.test_contrato_principal",
    "D-021": "test_contraste_d_l2.ContrasteDL2Tests.test_contrato_principal",
    "D-022": "test_contraste_d_l2.ContrasteDL2Tests.test_contrato_principal",
    "D-024": "test_contraste_d_l4.ContrasteDL4Tests.test_contrato_principal",
    "D-026": "test_contraste_d_l4.ContrasteDL4Tests.test_contrato_principal",
    "D-027": "test_contraste_d_l1.ContrasteDL1Tests.test_contrato_principal",
    "D-028": "test_contraste_d_l2.ContrasteDL2Tests.test_contrato_principal"
}
GAPS = {
    "D-011": "Marca oficial y nombre corto: falta test HTTP/navegador transversal que compruebe Condor App, Condor y SEO en todas las superficies",
    "D-023": "Portabilidad Hostinger→AWS: falta contrato reproducible de arranque/entorno y sustitución del storage sin inferir un deploy",
    "D-025": "Tenant por ruta y dominio personalizado: falta test HTTP real de host/slug, tenant ajeno, host desconocido y canonicalización"
}
EXPECTED = [
    "D-003",
    "D-005",
    "D-006",
    "D-009",
    "D-011",
    "D-012",
    "D-013",
    "D-014",
    "D-015",
    "D-016",
    "D-018",
    "D-020",
    "D-021",
    "D-022",
    "D-023",
    "D-024",
    "D-025",
    "D-026",
    "D-027",
    "D-028"
]
DOC = ROOT / "docs/verificacion/decisiones-01.md"
CHILD = "1608"


def require_single_green(suite: unittest.TestSuite) -> None:
    """No aceptar testigos ausentes, skip, errores o fallos como evidencia."""
    if suite.countTestCases() != 1:
        raise AssertionError("UNKNOWN: se requiere un test real exacto")
    result = unittest.TestResult()
    suite.run(result)
    if (result.testsRun != 1 or not result.wasSuccessful()
            or result.skipped or result.expectedFailures
            or result.unexpectedSuccesses):
        raise AssertionError(
            "UNKNOWN: testigo falló/no ejecutó "
            + repr((result.testsRun, result.failures[:1],
                    result.errors[:1], result.skipped[:1]))
        )


class VerificacionDecisiones01Tests(unittest.TestCase):
    def test_regresiones_ejecutadas_por_decision(self) -> None:
        self.assertEqual(len(EXPECTED), 20)
        self.assertEqual(set(WITNESSES) | set(GAPS), set(EXPECTED))
        self.assertFalse(set(WITNESSES) & set(GAPS))
        # Reutilizar el resultado de un mismo testigo evita ejecutar PHP repetido.
        exercised = set()
        for decision in EXPECTED:
            if decision in GAPS:
                continue
            qualified = WITNESSES[decision]
            with self.subTest(decision=decision, method=qualified):
                self.assertTrue(qualified and "." in qualified)
                if qualified in exercised:
                    continue
                suite = unittest.defaultTestLoader.loadTestsFromName(qualified)
                require_single_green(suite)
                exercised.add(qualified)
        self.assertGreaterEqual(len(exercised), 8)

    def test_testigos_falsos_skips_y_fallos_rechazados(self) -> None:
        """Mutaciones de evidencia: ni nombre inexistente ni skipped pasan."""
        with self.assertRaises(AssertionError):
            require_single_green(unittest.defaultTestLoader.loadTestsFromName(
                "modulo_inexistente_decisiones_01.TestMissing.test_missing"
            ))

        class Incompleto(unittest.TestCase):
            def test_salto(self) -> None:
                self.skipTest("simulación de test no ejecutado")

            def test_error(self) -> None:
                self.fail("simulación de regresión")

        for method in ("test_salto", "test_error"):
            with self.subTest(method=method):
                with self.assertRaises(AssertionError):
                    require_single_green(unittest.TestSuite([Incompleto(method)]))
        with self.assertRaises(AssertionError):
            require_single_green(unittest.TestSuite())

    def test_documentacion_exacta_sin_falsa_promocion(self) -> None:
        content = DOC.read_text(encoding="utf-8")
        found = re.findall(r"^\|\s*`(D-\d{3})`\s*\|", content, re.MULTILINE)
        self.assertEqual(len(found), len(EXPECTED))
        self.assertEqual(set(found), set(EXPECTED))
        self.assertEqual(len(set(found)), len(found))
        self.assertIn("NO_VERIFICABLE", content)
        self.assertIn("LOCAL_PARTIAL", content)
        self.assertIn("UNKNOWN", content)
        self.assertIn("Hija #"+CHILD, content)
        for decision, qualified in WITNESSES.items():
            with self.subTest(decision=decision):
                self.assertIn(f"| `{decision}` | LOCAL_PARTIAL | `{qualified}` |", content)
        for decision in GAPS:
            with self.subTest(decision=decision):
                self.assertIn(f"| `{decision}` | UNKNOWN |", content)
        self.assertNotIn("VALIDATED_IN_PRODUCTION", content)
        self.assertNotIn("ACREDITADO EN PRODUCCIÓN", content)

    def test_fase_segura_y_claims(self) -> None:
        # Ninguna prueba solicita red, datos reales ni cambios del runtime.
        phase = json.loads((ROOT / "datos.yml").read_text(encoding="utf-8"))
        self.assertEqual(phase["project"], "pl0n3r/Condor")
        self.assertEqual(phase["phase"], "construccion")
        self.assertTrue((ROOT / "AGENTES.md").is_file())
        self.assertEqual(len(WITNESSES), 17)
        self.assertEqual(len(GAPS), 3)
        self.assertTrue(all(reason for reason in GAPS.values()))


if __name__ == "__main__":
    unittest.main()
