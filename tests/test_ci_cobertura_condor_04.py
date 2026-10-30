#!/usr/bin/env python3
"""Bridge fail-closed entre discovery de CI y suite real de Condor #1323.

La suite real permanece en su archivo/claim original. La presencia de este
bridge no acredita cobertura si falta cualquiera de sus cuatro pruebas.
"""
from __future__ import annotations

import importlib.util
import io
import subprocess
import sys
import tempfile
import unittest
from pathlib import Path

ROOT = Path(__file__).resolve().parents[1]
SOURCE = ROOT / "tests" / "test_cobertura_condor_04.py"
WORKFLOW = ROOT / ".github" / "workflows" / "ci.yml"
EXPECTED = (
    "test_contrato_principal",
    "test_falla_cerrado",
    "test_sin_datos_reales",
    "test_ci_gate_contract",
)


def _run_upstream(path: Path) -> None:
    """Ejecutar los cuatro tests originales; no permitir skips ni xfails."""
    if path.is_symlink() or not path.is_file():
        raise AssertionError("UNKNOWN: suite de cobertura ausente o insegura")
    spec = importlib.util.spec_from_file_location("condor04_upstream_checked", path)
    if spec is None or spec.loader is None:
        raise AssertionError("UNKNOWN: import de suite original inválido")
    module = importlib.util.module_from_spec(spec)
    try:
        spec.loader.exec_module(module)
    except BaseException as exc:
        raise AssertionError("UNKNOWN: import de suite original falló") from exc
    cls = getattr(module, "CoberturaCondor04Tests", None)
    if not isinstance(cls, type) or not issubclass(cls, unittest.TestCase):
        raise AssertionError("UNKNOWN: clase CoberturaCondor04Tests ausente")
    found = tuple(name for name in dir(cls) if name.startswith("test_"))
    if set(found) != set(EXPECTED) or len(found) != len(EXPECTED):
        raise AssertionError(f"UNKNOWN: suite incompleta o con tests extra: {found}")
    suite = unittest.TestLoader().loadTestsFromTestCase(cls)
    if suite.countTestCases() != 4:
        raise AssertionError("UNKNOWN: cantidad de pruebas upstream incorrecta")
    output = io.StringIO()
    result = unittest.TextTestRunner(stream=output, verbosity=0).run(suite)
    if (result.testsRun != 4 or not result.wasSuccessful() or result.skipped
            or result.expectedFailures or result.unexpectedSuccesses):
        raise AssertionError("UNKNOWN: pruebas upstream no acreditadas: " + output.getvalue()[-2500:])


def _upstream_test(path: Path) -> unittest.FunctionTestCase:
    return unittest.FunctionTestCase(lambda: _run_upstream(path),
                                     description="CoberturaCondor04Tests: cuatro casos originales")


def _suite(loader: unittest.TestLoader, path: Path, *, contract: bool) -> unittest.TestSuite:
    suite = unittest.TestSuite()
    if contract:
        suite.addTests(loader.loadTestsFromTestCase(CoberturaCondor04BridgeTests))
    suite.addTest(_upstream_test(path))
    return suite


def load_tests(loader: unittest.TestLoader, standard_tests: unittest.TestSuite,
               pattern: str | None) -> unittest.TestSuite:
    """Punto de entrada consumido por unittest discover -p 'test_ci_*.py'."""
    return _suite(loader, SOURCE, contract=True)


class CoberturaCondor04BridgeTests(unittest.TestCase):
    """Contrato de descubrimiento, falsos positivos y gates de CI."""

    def test_bridge_discovery(self):
        with tempfile.TemporaryDirectory() as directory:
            folder = Path(directory)
            upstream = folder / "test_cobertura_condor_04.py"
            marker = folder / "executed.txt"
            methods = "\n".join(
                f"    def {name}(self):\n"
                f"        with open({str(marker)!r}, 'a', encoding='utf-8') as out:\n"
                f"            out.write({name!r} + '\\n')"
                for name in EXPECTED
            )
            upstream.write_text("import unittest\nclass CoberturaCondor04Tests(unittest.TestCase):\n"
                                + methods + "\n", encoding="utf-8")
            import_root = str(Path(__file__).resolve().parent)
            (folder / "test_ci_bridge_fixture.py").write_text(
                "import sys\nfrom pathlib import Path\n"
                f"sys.path.insert(0, {import_root!r})\n"
                "from test_ci_cobertura_condor_04 import _suite\n"
                "def load_tests(loader, standard_tests, pattern):\n"
                f"    return _suite(loader, Path({str(upstream)!r}), contract=False)\n",
                encoding="utf-8",
            )
            process = subprocess.run(
                [sys.executable, "-m", "unittest", "discover", "-s", str(folder),
                 "-p", "test_ci_*.py"], capture_output=True, text=True, timeout=30,
                check=False,
            )
            self.assertEqual(process.returncode, 0, process.stdout + process.stderr)
            self.assertIn("Ran 1 test", process.stderr)
            self.assertCountEqual(marker.read_text(encoding="utf-8").splitlines(), EXPECTED)
            self.assertEqual(_suite(unittest.TestLoader(), upstream, contract=True).countTestCases(), 5)

    def test_no_falsa_acreditacion(self):
        with tempfile.TemporaryDirectory() as directory:
            path = Path(directory) / "test_cobertura_condor_04.py"
            with self.assertRaisesRegex(AssertionError, "UNKNOWN"):
                _run_upstream(path)  # Ausencia real
            cases = [
                "import unittest\n",  # Clase ausente
                "class CoberturaCondor04Tests: pass\n",  # Clase incorrecta
                "class CoberturaCondor04Tests(unittest.TestCase):\n    def test_foo(self): pass\n",
                "def esto es sintaxis rota\n",  # Import inválido
            ]
            for source in cases:
                with self.subTest(source=source[:25]):
                    path.write_text(source, encoding="utf-8")
                    with self.assertRaises(AssertionError):
                        _run_upstream(path)
            for effect in ("self.fail('falla')", "raise RuntimeError('error')",
                           "self.skipTest('omitida')", "self.assertTrue(True)"):
                methods = []
                for i, name in enumerate(EXPECTED):
                    body = effect if i == 0 else "self.assertTrue(True)"
                    methods.append(f"    def {name}(self):\n        {body}\n")
                code = "import unittest\nclass CoberturaCondor04Tests(unittest.TestCase):\n" + "".join(methods)
                if effect == "self.assertTrue(True)":
                    code += "    def test_extra(self):\n        self.assertTrue(True)\n"
                path.write_text(code, encoding="utf-8")
                with self.subTest(effect=effect), self.assertRaises(AssertionError):
                    _run_upstream(path)
            path.write_text("", encoding="utf-8")
            path.unlink()
            try:
                path.symlink_to(Path(__file__))
            except (NotImplementedError, OSError):
                pass
            else:
                with self.assertRaisesRegex(AssertionError, "UNKNOWN"):
                    _run_upstream(path)

    def test_validar_contract(self):
        workflow = WORKFLOW.read_text(encoding="utf-8")
        self.assertIn("python3 -m unittest discover -s tests -p 'test_ci_*.py'", workflow)
        self.assertIn("python3 -m unittest discover -s tests -p 'test_contraste_d_l*.py'", workflow)

    def test_ci_gate_contract(self):
        workflow = WORKFLOW.read_text(encoding="utf-8")
        self.assertRegex(workflow, r"(?m)^\s*name:\s*CI Factory v1\s*$")
        self.assertRegex(workflow, r"(?m)^\s*name:\s*Validar\s*$")


if __name__ == "__main__":
    unittest.main()
