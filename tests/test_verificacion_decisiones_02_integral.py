#!/usr/bin/env python3
"""#1613: verificación 20/20 offline sin certificar aceptación integral."""
from __future__ import annotations

import json
import re
import runpy
import unittest
from pathlib import Path

from scripts.ci_change_classifier import classify

ROOT = Path(__file__).resolve().parents[1]
DOC = ROOT / "docs/verificacion/decisiones-02-integral.md"
REFERENCE = runpy.run_path(str(ROOT / "tests/test_verificacion_decisiones_02.py"))
ROWS = REFERENCE["ROWS"]
IDS = frozenset(row[0] for row in ROWS)
PR_SHA = {
    1383: "4a7fa6860c17966077dab9ba1518e07584c35875",
    1357: "18eddcb7252beaa19e17474a77a96a8a3736563c",
    1355: "d783c1d42cd0aaf81eecb4c5429da9efd9480bdd",
    1269: "d4b53a67196af8c69022eabdc899372a60fb2729",
}
INTEGRAL = "**UNKNOWN**"
EXPECTED_IDS = (
    "D-030", "D-030-S01", "D-030-S02", "D-030-S03", "D-030-S04",
    "D-031", "D-032", "D-033", "D-034", "D-035", "D-036",
    "D-037", "D-039", "D-040", "D-040-S01", "D-040-S02",
    "D-040-S03", "D-040-S04", "D-040-S05", "D-040-S06",
)


def parse_report(content: object) -> dict[str, tuple[str, ...]]:
    """Reject incomplete, duplicate, promoted and forged rows."""
    if not isinstance(content, str):
        raise ValueError("UNKNOWN: informe ilegible")
    indexed = {row[0]: row for row in ROWS}
    result: dict[str, tuple[str, ...]] = {}
    pattern = re.compile(r"^\|\s*(D-\d{3}(?:-S\d{2})?)\s*\|")
    for line in content.splitlines():
        match = pattern.match(line)
        if not match:
            continue
        key = match.group(1)
        cells = tuple(cell.strip() for cell in line.strip().strip("|").split("|"))
        if key not in indexed or key in result or len(cells) != 7:
            raise ValueError("UNKNOWN: ID duplicado, inesperado o fila inválida")
        _, _, _, source, markers, witness, pr, grade, gap = indexed[key]
        if (
            cells[0] != key
            or cells[1] != "`" + source + "`"
            or cells[2] != (
                "`" + witness + "::test_contrato_principal` / `"
                + witness + "::test_falla_cerrado`"
            )
            or cells[3] != (
                "[PR #" + str(pr) + "](https://github.com/pl0n3r/Condor/pull/"
                + str(pr) + ") `" + PR_SHA[pr] + "`"
            )
            or cells[4] != grade
            or cells[5] != INTEGRAL
            or cells[6] != gap
        ):
            raise ValueError("UNKNOWN: testigo, SHA, gap o estado falsificado: " + key)
        result[key] = cells
    if set(result) != IDS or len(result) != 20:
        raise ValueError("UNKNOWN: inventario integral incompleto")
    return result


def run_offline_test(path: str, classname: str, method: str) -> None:
    """Run a named existing fixture test; do not invoke live providers."""
    if (path, classname) not in (
        ("tests/test_brechas_condor_06.py", "BrechasCondor06Tests"),
    ) or method not in {"test_contrato_principal", "test_falla_cerrado"}:
        raise AssertionError("UNKNOWN: testigo no autorizado")
    namespace = runpy.run_path(str(ROOT / path))
    klass = namespace[classname]
    result = unittest.TestResult()
    klass(method).run(result)
    if result.testsRun != 1 or result.failures or result.errors or result.skipped:
        raise AssertionError("UNKNOWN: regresión offline falló " + path)


class VerificacionDecisiones02IntegralTests(unittest.TestCase):
    @classmethod
    def setUpClass(cls) -> None:
        cls.report = DOC.read_text(encoding="utf-8")

    def test_contrato_principal(self) -> None:
        self.assertEqual(20, len(ROWS))
        self.assertEqual(frozenset(EXPECTED_IDS), IDS)
        self.assertEqual(20, len(parse_report(self.report)))
        matrix = REFERENCE["matrix_rows"](
            (ROOT / "docs/matriz-cobertura.md").read_text(encoding="utf-8")
        )
        for key, _, heading, source, markers, _, _, _, _ in ROWS:
            with self.subTest(key=key):
                self.assertEqual(matrix[key][2], "`NO_VERIFICABLE`")
                REFERENCE["section"](
                    (ROOT / "ESPECIFICACIONES.md").read_text(encoding="utf-8"),
                    heading,
                )
                REFERENCE["require_source"](source, markers)
        self.assertIn("53b87994b711bb3aa84a16604f04f1b821552c5b", self.report)
        self.assertIn("**UNKNOWN**", self.report)

    def test_regresiones_php_positivas_y_negativas(self) -> None:
        # The previous verified suite runs PHP classes with only synthetic data.
        for path, classname in REFERENCE["SUITES"]:
            for method in ("test_contrato_principal", "test_falla_cerrado"):
                with self.subTest(path=path, method=method):
                    REFERENCE["run_previous"](path, classname, method)
        for method in ("test_contrato_principal", "test_falla_cerrado"):
            with self.subTest(path="brechas_condor_06", method=method):
                run_offline_test(
                    "tests/test_brechas_condor_06.py",
                    "BrechasCondor06Tests",
                    method,
                )

    def test_falla_cerrado(self) -> None:
        rows = [line for line in self.report.splitlines()
                if line.startswith("| D-040-S06 |")]
        self.assertEqual(1, len(rows))
        row = rows[0]
        variants = (
            self.report + "\n" + row,
            self.report.replace(row, "", 1),
            self.report.replace(row, row.replace(INTEGRAL, "**CUBIERTO**"), 1),
            self.report.replace(row, row.replace(
                PR_SHA[1355], "0" * 40), 1),
            self.report.replace(row, row.replace(
                "test_falla_cerrado", "test_falso"), 1),
        )
        for variant in variants:
            with self.subTest(variant=variant[-100:]):
                with self.assertRaises(ValueError):
                    parse_report(variant)
        with self.assertRaises(ValueError):
            parse_report(None)
        with self.assertRaises(AssertionError):
            run_offline_test("tests/not-allowed.py", "Unknown", "test_fake")

    def test_no_equivalencia_ci_y_produccion(self) -> None:
        self.assertIn("**UNKNOWN**", self.report)
        self.assertNotIn("**HEALTHY**", self.report)
        self.assertIn("no certifica un despliegue", self.report)
        self.assertIn("no son capacidades PHP", self.report)
        self.assertIn("sin prometer que cada decisión queda probada", self.report)
        phase = json.loads((ROOT / "datos.yml").read_text(encoding="utf-8"))
        self.assertEqual(phase["phase"], "construccion")
        self.assertIs(phase["d063_attestation"]["nothing_live"], True)
        self.assertIs(phase["d063_attestation"]["no_real_customer_data"], True)

    def test_descubrimiento_ci_exact_head(self) -> None:
        workflow = (ROOT / ".github/workflows/ci.yml").read_text(encoding="utf-8")
        self.assertIn("test_verificacion_decisiones_*.py", workflow)
        self.assertTrue(classify([
            "tests/test_verificacion_decisiones_02_integral.py",
            "docs/verificacion/decisiones-02-integral.md",
        ], "pull_request").pruebas_base)


if __name__ == "__main__":
    unittest.main()
