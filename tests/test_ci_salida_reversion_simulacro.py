"""#1492: aceptación ejecutable sin SSH, secretos ni datos productivos."""
from __future__ import annotations

import importlib.util
import json
import subprocess
import tempfile
import unittest
from pathlib import Path

ROOT = Path(__file__).resolve().parents[1]
SPEC = importlib.util.spec_from_file_location(
    "condor_1492_drill", ROOT / "scripts/simular_reversion_apagado.py"
)
assert SPEC is not None and SPEC.loader is not None
drill = importlib.util.module_from_spec(SPEC)
SPEC.loader.exec_module(drill)


class SimulacroReversionTests(unittest.TestCase):
    def test_rollback_local_real(self):
        with tempfile.TemporaryDirectory(prefix="condor-1492-test-") as tmp:
            root = Path(tmp) / "releases"
            drill.prepare_releases(root)
            self.assertEqual((root / "current").resolve(), root / "releases" / drill.BAD_SHA)
            drill.local_rollback(root)
            self.assertEqual((root / "current").resolve(), root / "releases" / drill.GOOD_SHA)
            self.assertTrue((root / "current").is_symlink())

    def test_apagado_y_recuperacion_sinteticos(self):
        receipt = drill.perform_drill()
        self.assertEqual(receipt["status"], "PASSED_SYNTHETIC_ONLY")
        self.assertTrue(all(receipt["checks"].values()))
        self.assertEqual(receipt["release"]["after"], drill.GOOD_SHA)
        self.assertEqual(receipt["checks"]["tenant_a_no_writes"], True)
        self.assertEqual(receipt["checks"]["tenant_b_remains_available"], True)
        self.assertEqual(receipt["checks"]["tenant_a_recovered"], True)

    def test_rechaza_previous_invalido(self):
        with tempfile.TemporaryDirectory(prefix="condor-1492-test-") as tmp:
            root = Path(tmp) / "releases"
            drill.prepare_releases(root)
            current = root / "current"
            (root / ".previous").write_text("/outside/not-authorized\n", encoding="utf-8")
            with self.assertRaises(subprocess.CalledProcessError):
                drill.local_rollback(root)
            self.assertEqual(current.resolve(), root / "releases" / drill.BAD_SHA)

            (root / ".previous").unlink()
            (root / ".previous").symlink_to("/outside/not-authorized")
            with self.assertRaises(drill.DrillError):
                drill.local_rollback(root)
            self.assertEqual(current.resolve(), root / "releases" / drill.BAD_SHA)
            (root / ".previous").unlink()
            (root / ".previous").write_text(
                str(root / "releases" / drill.GOOD_SHA) + "\n", encoding="utf-8"
            )
            (root / "releases" / drill.GOOD_SHA / ".release-sha").unlink()
            with self.assertRaises(subprocess.CalledProcessError):
                drill.local_rollback(root)
            self.assertEqual(current.resolve(), root / "releases" / drill.BAD_SHA)

    def test_recibo_y_comunicacion_minimizados(self):
        receipt = drill.perform_drill()
        for key in ("started_at_utc", "completed_at_utc"):
            self.assertRegex(receipt[key], r"^\d{4}-\d{2}-\d{2}T.*Z$")
        for key in ("containment", "rollback", "recovery"):
            self.assertGreaterEqual(receipt["elapsed_ms"][key], 0)
        self.assertFalse(receipt["communications"]["sent"])
        self.assertEqual(receipt["production_validation"], "NOT_VERIFIED")
        self.assertEqual(receipt["production_rollback"], "NOT_EXECUTED")
        self.assertEqual(receipt["production_shutdown"], "NOT_EXECUTED")
        self.assertEqual(receipt["go_live"], "NOT_AUTHORIZED")
        self.assertEqual(len(receipt["corrective_actions"]), 3)
        self.assertNotIn("HOSTINGER", json.dumps(receipt["environment"]))
        print("CONDOR_1492_SYNTHETIC_EVIDENCE=" + json.dumps(receipt, sort_keys=True), flush=True)

    def test_documentacion_y_puertas_de_live(self):
        runbook = (ROOT / "docs/salida-reversion-apagado.md").read_text(encoding="utf-8")
        annex = (ROOT / "docs/simulacro-reversion-apagado.md").read_text(encoding="utf-8")
        for text in (runbook, annex):
            self.assertIn("scripts/simular_reversion_apagado.py", text)
            self.assertIn("tests/test_ci_salida_reversion_simulacro.py", text)
            self.assertIn("no autoriza go-live", text.casefold())
        for word in ("loopback", "rollback", "503", "Retry-After", "NO_VERIFICADO",
                     "DEL_DUEÑO", "acciones correctivas", "evidencia"):
            self.assertIn(word.casefold(), annex.casefold())


if __name__ == "__main__":
    unittest.main()
