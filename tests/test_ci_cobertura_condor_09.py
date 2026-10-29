#!/usr/bin/env python3
"""CI #1395: ejecutar exactamente los cuatro contratos reales de Condor #1328.

La ausencia de la hoja upstream antes de su merge no acredita cobertura.
Cuando está presente, fallos, importaciones rotas, skips y omisiones vetan CI.
"""
from __future__ import annotations

import importlib.util
from pathlib import Path
from tempfile import TemporaryDirectory
from unittest import TestCase, TestLoader, TestResult, TestSuite
import unittest

ROOT = Path(__file__).resolve().parents[1]
TARGET = ROOT / "tests" / "test_cobertura_condor_09.py"
REQUIRED = frozenset((
    "test_contrato_principal",
    "test_falla_cerrado",
    "test_sin_datos_reales",
    "test_ci_gate_contract",
))


def execute_exact(path: Path) -> None:
    """Ejecuta código de la hoja, no checks de texto ni asserts inventados."""
    if path.is_symlink() or not path.is_file():
        raise AssertionError("UNKNOWN: hoja de cobertura ausente o insegura")
    spec = importlib.util.spec_from_file_location("condor09_runtime", path)
    if spec is None or spec.loader is None:
        raise AssertionError("UNKNOWN: no se puede importar hoja")
    module = importlib.util.module_from_spec(spec)
    try:
        spec.loader.exec_module(module)
    except Exception as exc:
        raise AssertionError("UNKNOWN: importación de hoja falló") from exc
    case = getattr(module, "CoberturaCondor09Tests", None)
    if not isinstance(case, type) or not issubclass(case, TestCase):
        raise AssertionError("UNKNOWN: clase de cobertura ausente")
    names = frozenset(name for name in dir(case) if name.startswith("test_"))
    if names != REQUIRED:
        raise AssertionError("UNKNOWN: los cuatro métodos son obligatorios")
    tests = TestLoader().loadTestsFromTestCase(case)
    if tests.countTestCases() != 4:
        raise AssertionError("UNKNOWN: discovery incompleta")
    outcome = TestResult()
    tests.run(outcome)
    if (outcome.testsRun != 4 or not outcome.wasSuccessful()
            or outcome.skipped or outcome.expectedFailures
            or outcome.unexpectedSuccesses):
        raise AssertionError(
            f"UNKNOWN: cobertura real falló, ejecutados={outcome.testsRun}, "
            f"errores={len(outcome.errors)}, fallos={len(outcome.failures)}, "
            f"saltos={len(outcome.skipped)}"
        )


def synthetic_suite(path: Path, *, outcome: str = "pass") -> None:
    """Una única fixture sintética permite probar cada rechazo."""
    methods = []
    for index, name in enumerate(sorted(REQUIRED)):
        action = "self.assertTrue(True)"
        if index == 0 and outcome == "fail":
            action = "self.fail('synthetic failure')"
        elif index == 0 and outcome == "skip":
            action = "self.skipTest('synthetic skip')"
        methods.append(f"    def {name}(self):\n        {action}\n")
    if outcome == "missing":
        methods.pop()
    path.write_text(
        "import unittest\nclass CoberturaCondor09Tests(unittest.TestCase):\n"
        + "".join(methods),
        encoding="utf-8",
    )


class CoberturaCondor09BridgeTests(TestCase):
    def test_bridge_discovery(self) -> None:
        self.assertEqual(len(REQUIRED), 4)
        with TemporaryDirectory(prefix="condor09-positive-") as tmp:
            sample = Path(tmp) / "coverage.py"
            synthetic_suite(sample)
            execute_exact(sample)

    def test_no_falsa_acreditacion(self) -> None:
        with TemporaryDirectory(prefix="condor09-negative-") as tmp:
            path = Path(tmp) / "coverage.py"
            with self.assertRaisesRegex(AssertionError, "ausente"):
                execute_exact(path)
            for result in ("fail", "skip", "missing"):
                with self.subTest(result=result):
                    synthetic_suite(path, outcome=result)
                    with self.assertRaisesRegex(AssertionError, "UNKNOWN"):
                        execute_exact(path)
            path.write_text("raise ImportError('fixture')\n", encoding="utf-8")
            with self.assertRaisesRegex(AssertionError, "importación"):
                execute_exact(path)

    def test_validar_contract(self) -> None:
        source = (ROOT / ".github/workflows/ci.yml").read_text(encoding="utf-8")
        self.assertIn("name: Validar", source)
        self.assertIn(
            "python3 -m unittest discover -s tests -p 'test_ci_*.py'", source
        )
        self.assertTrue(Path(__file__).name.startswith("test_ci_"))

    def test_ci_gate_contract(self) -> None:
        self.assertTrue(callable(load_tests))
        self.assertEqual(TARGET.name, "test_cobertura_condor_09.py")
        self.assertEqual(len(REQUIRED), 4)


class RuntimeCondor09Proof(TestCase):
    def execute_real_suite(self) -> None:
        execute_exact(TARGET)


def load_tests(loader: TestLoader, tests: TestSuite,
               pattern: str | None) -> TestSuite:
    if TARGET.exists() or TARGET.is_symlink():
        tests.addTest(RuntimeCondor09Proof("execute_real_suite"))
    return tests


if __name__ == "__main__":
    unittest.main()
