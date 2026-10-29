#!/usr/bin/env python3
"""#1404: execute Condor #1347 PHP contracts through the real Validar discovery.

A successful bridge requires the actual three runtime tests to pass; synthetic
fixtures verify discovery and fail-closed behavior independently of PHP.
"""
from __future__ import annotations

import importlib.util
from pathlib import Path
import os
import shutil
import tempfile
import unittest

ROOT = Path(__file__).resolve().parents[1]
TARGET = ROOT / "tests/test_cobertura_condor_28.py"
METHODS = frozenset((
    "test_contrato_principal",
    "test_falla_cerrado",
    "test_sin_datos_reales",
))


def _suite(path: Path, loader: unittest.TestLoader) -> unittest.TestSuite:
    """Load exactly three authentic TestCase methods or reject the evidence."""
    if path.is_symlink() or not path.is_file():
        raise AssertionError("UNKNOWN: coverage suite absent or nonregular")
    spec = importlib.util.spec_from_file_location("condor28_verified_suite", path)
    if spec is None or spec.loader is None:
        raise AssertionError("UNKNOWN: coverage suite cannot be imported")
    module = importlib.util.module_from_spec(spec)
    try:
        spec.loader.exec_module(module)
    except Exception as error:
        raise AssertionError("UNKNOWN: coverage suite import failed") from error
    case = getattr(module, "CoberturaCondor28Tests", None)
    if not isinstance(case, type) or not issubclass(case, unittest.TestCase):
        raise AssertionError("UNKNOWN: coverage TestCase class missing")
    actual = frozenset(name for name in dir(case) if name.startswith("test_"))
    if actual != METHODS:
        raise AssertionError("UNKNOWN: coverage methods missing or ambiguous")
    loaded = loader.loadTestsFromTestCase(case)
    if loaded.countTestCases() != 3:
        raise AssertionError("UNKNOWN: expected three real tests")
    return loaded


def _execute(path: Path) -> int:
    """Execute real methods; failures, skips and unexpected outcomes are fatal."""
    if path == TARGET:
        if not shutil.which("php") or not (ROOT / "vendor/autoload.php").is_file():
            raise AssertionError("UNKNOWN: PHP 8.5 and Composer autoload required")
    suite = _suite(path, unittest.defaultTestLoader)
    outcome = unittest.TestResult()
    suite.run(outcome)
    if (outcome.testsRun != 3 or not outcome.wasSuccessful()
            or outcome.skipped or outcome.expectedFailures
            or outcome.unexpectedSuccesses):
        raise AssertionError(
            "UNKNOWN: coverage tests not green: "
            f"ran={outcome.testsRun}, failures={len(outcome.failures)}, "
            f"errors={len(outcome.errors)}, skips={len(outcome.skipped)}; "
            f"first_issue={(outcome.failures + outcome.errors)[:1]!r}"
        )
    if path == TARGET:
        print(
            "CI_EVIDENCE CoberturaCondor28Tests: "
            "test_contrato_principal,test_falla_cerrado,test_sin_datos_reales; "
            "executed=3 failures=0 errors=0 skips=0; real PHP classes",
            flush=True,
        )
    return outcome.testsRun


def _fixture(path: Path, *, problem: str = "") -> None:
    """Disposable synthetic TestCase with chosen failure, skip or omission."""
    parts = ["import unittest", "class CoberturaCondor28Tests(unittest.TestCase):"]
    for name in sorted(METHODS):
        if problem == "missing" and name == "test_contrato_principal":
            continue
        if problem == "fail" and name == "test_contrato_principal":
            action = "self.fail('synthetic failure')"
        elif problem == "skip" and name == "test_contrato_principal":
            action = "self.skipTest('synthetic skip')"
        elif problem == "error" and name == "test_contrato_principal":
            action = "raise RuntimeError('synthetic error')"
        else:
            action = "self.assertTrue(True)"
        parts.append(f"    def {name}(self):")
        parts.append(f"        {action}")
    path.write_text("\n".join(parts) + "\n", encoding="utf-8")


class CoberturaCondor28BridgeTests(unittest.TestCase):
    def test_bridge_discovery(self) -> None:
        self.assertEqual(len(METHODS), 3)
        with tempfile.TemporaryDirectory(prefix="condor28-ci-positive-") as temp:
            fixture = Path(temp) / "coverage.py"
            _fixture(fixture)
            self.assertEqual(_suite(fixture, unittest.defaultTestLoader).countTestCases(), 3)
            self.assertEqual(_execute(fixture), 3)

    def test_falla_cerrado(self) -> None:
        with tempfile.TemporaryDirectory(prefix="condor28-ci-negative-") as temp:
            fixture = Path(temp) / "coverage.py"
            with self.assertRaisesRegex(AssertionError, "absent"):
                _execute(fixture)
            for problem in ("missing", "fail", "skip", "error"):
                with self.subTest(problem=problem):
                    _fixture(fixture, problem=problem)
                    with self.assertRaisesRegex(AssertionError, "UNKNOWN"):
                        _execute(fixture)
            fixture.write_text("raise ImportError('synthetic import failure')\n", encoding="utf-8")
            with self.assertRaisesRegex(AssertionError, "import failed"):
                _execute(fixture)
            fixture.write_text("import unittest\nclass WrongName(unittest.TestCase):\n    pass\n",
                               encoding="utf-8")
            with self.assertRaisesRegex(AssertionError, "class missing"):
                _execute(fixture)
            fixture.unlink()
            fixture.symlink_to(Path(temp) / "missing_target.py")
            with self.assertRaisesRegex(AssertionError, "nonregular"):
                _execute(fixture)

    def test_validar_contract(self) -> None:
        source = (ROOT / ".github/workflows/ci.yml").read_text(encoding="utf-8")
        discovery = "python3 -m unittest discover -s tests -p 'test_ci_*.py'"
        install = "composer install --no-interaction --prefer-dist --no-progress"
        self.assertIn("name: Validar", source)
        self.assertIn("php-version: '8.5'", source)
        self.assertIn(discovery, source)
        self.assertIn(install, source)
        dedicated = (
            "CONDOR28_REQUIRE_REAL=1 python3 -m unittest discover -s tests "
            "-p 'test_ci_cobertura_condor_28.py' -v"
        )
        self.assertIn(dedicated, source)
        self.assertLess(source.index(discovery), source.index(install))
        self.assertLess(source.index(install), source.index(dedicated))
        self.assertEqual(Path(__file__).name, "test_ci_cobertura_condor_28.py")
        self.assertTrue(callable(load_tests))


class Condor28RuntimeProof(unittest.TestCase):
    def run_real_coverage_contracts(self) -> None:
        _execute(TARGET)


def load_tests(loader: unittest.TestLoader, tests: unittest.TestSuite,
               pattern: str | None) -> unittest.TestSuite:
    # The target is already merged. Absence must *fail*, never silently skip.
    if os.environ.get("CONDOR28_REQUIRE_REAL") == "1":
        tests.addTest(Condor28RuntimeProof("run_real_coverage_contracts"))
    return tests


if __name__ == "__main__":
    unittest.main()
