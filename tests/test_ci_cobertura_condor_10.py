#!/usr/bin/env python3
"""CI bridge for #1329: execute the four real coverage tests when present.

The upstream suite belongs to a different V3 claim and is not yet on main
when this bridge is merged. Until then this file tests only discovery and
failure propagation, never claims that the absent suite passed.
"""
from __future__ import annotations

import importlib.util
import tempfile
import unittest
from pathlib import Path

ROOT = Path(__file__).resolve().parents[1]
TARGET = ROOT / "tests/test_cobertura_condor_10.py"
METHODS = (
    "test_contrato_principal",
    "test_falla_cerrado",
    "test_sin_datos_reales",
    "test_validar_exigido",
)


def _load_target(path: Path, loader: unittest.TestLoader) -> unittest.TestSuite | None:
    """Construct exactly four real tests; absence is explicit, invalidity fatal."""
    if not path.exists() and not path.is_symlink():
        return None
    if path.is_symlink() or not path.is_file():
        raise RuntimeError("UNKNOWN: coverage test is not a regular file")
    spec = importlib.util.spec_from_file_location("_condor10_coverage_target", path)
    if spec is None or spec.loader is None:
        raise RuntimeError("UNKNOWN: coverage module cannot be imported")
    module = importlib.util.module_from_spec(spec)
    try:
        spec.loader.exec_module(module)
    except BaseException as error:
        raise RuntimeError("UNKNOWN: coverage module failed to import") from error
    case = getattr(module, "CoberturaCondor10Tests", None)
    if not isinstance(case, type) or not issubclass(case, unittest.TestCase):
        raise RuntimeError("UNKNOWN: coverage test class is absent")
    actual = {name for name in dir(case) if name.startswith("test_")}
    if actual != set(METHODS):
        raise RuntimeError("UNKNOWN: coverage methods incomplete or ambiguous")
    suite = loader.loadTestsFromTestCase(case)
    if suite.countTestCases() != len(METHODS):
        raise RuntimeError("UNKNOWN: coverage methods were not discovered")
    return suite


def _execute_target(path: Path) -> None:
    suite = _load_target(path, unittest.defaultTestLoader)
    if suite is None:
        raise AssertionError("UNKNOWN: coverage suite is not present")
    result = unittest.TestResult()
    suite.run(result)
    if (result.testsRun != len(METHODS) or not result.wasSuccessful()
            or result.skipped or result.expectedFailures or result.unexpectedSuccesses):
        issues = (result.failures + result.errors)[:1]
        raise AssertionError(
            f"UNKNOWN: coverage suite not green: ran={result.testsRun}, "
            f"skips={result.skipped}, errors={issues}"
        )


def _fixture(path: Path, *, failure: str = "", omission: str = "") -> None:
    methods = "".join(
        f"    def {name}(self):\n"
        f"        {('self.fail(' + repr('synthetic failure') + ')') if failure == name else ('self.skipTest(' + repr('synthetic skip') + ')') if failure == 'skip:' + name else 'self.assertTrue(True)'}\n"
        for name in METHODS if name != omission
    )
    path.write_text("import unittest\nclass CoberturaCondor10Tests(unittest.TestCase):\n"
                    + methods, encoding="utf-8")


class CoberturaCondor10BridgeTests(unittest.TestCase):
    def test_bridge_discovery(self) -> None:
        self.assertEqual(Path(__file__).name, "test_ci_cobertura_condor_10.py")
        self.assertEqual(len(METHODS), 4)
        with tempfile.TemporaryDirectory(prefix="condor10-bridge-") as temp:
            fixture = Path(temp) / "test_cobertura_condor_10.py"
            _fixture(fixture)
            suite = _load_target(fixture, unittest.defaultTestLoader)
            self.assertIsNotNone(suite)
            self.assertEqual(suite.countTestCases(), 4)
            _execute_target(fixture)

    def test_no_falsa_acreditacion(self) -> None:
        with tempfile.TemporaryDirectory(prefix="condor10-negative-") as temp:
            fixture = Path(temp) / "test_cobertura_condor_10.py"
            self.assertIsNone(_load_target(fixture, unittest.defaultTestLoader))
            with self.assertRaisesRegex(AssertionError, "not present"):
                _execute_target(fixture)
            _fixture(fixture, omission=METHODS[0])
            with self.assertRaisesRegex(RuntimeError, "incomplete"):
                _load_target(fixture, unittest.defaultTestLoader)
            for failure in (METHODS[0], "skip:" + METHODS[1]):
                with self.subTest(negative=failure):
                    _fixture(fixture, failure=failure)
                    with self.assertRaisesRegex(AssertionError, "not green"):
                        _execute_target(fixture)

    def test_validar_contract(self) -> None:
        workflow = (ROOT / ".github/workflows/ci.yml").read_text(encoding="utf-8")
        self.assertIn("name: Validar", workflow)
        self.assertIn("pull_request", workflow)
        self.assertIn(
            "python3 -m unittest discover -s tests -p 'test_ci_*.py'",
            workflow,
        )
        # CI discovers this very file and the load_tests hook below; its
        # presence does not certify coverage while TARGET is still absent.
        self.assertTrue(Path(__file__).name.startswith("test_ci_"))


class CoberturaCondor10RuntimeTests(unittest.TestCase):
    def ejecutar_cobertura_real(self) -> None:
        """Named explicitly by load_tests, not discovered prematurely."""
        _execute_target(TARGET)


def load_tests(loader: unittest.TestLoader, tests: unittest.TestSuite,
               pattern: str | None) -> unittest.TestSuite:
    if TARGET.exists() or TARGET.is_symlink():
        # Run all four tests through a fail-closed wrapper; skips are errors.
        tests.addTest(CoberturaCondor10RuntimeTests("ejecutar_cobertura_real"))
    return tests


if __name__ == "__main__":
    unittest.main()
