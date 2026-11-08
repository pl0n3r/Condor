"""Condor #1499: a real lazy feature boundary, not an increased JS budget."""
from pathlib import Path
import re
import unittest

ROOT = Path(__file__).resolve().parents[1]
APP = ROOT / "frontend/admin/AdminApp.tsx"
JS = ROOT / "public/build/admin.js"
CSS = ROOT / "public/build/admin.css"
CHUNK = ROOT / "public/build/chunks/OperationsProcessesPanel-CjPm4IAC.js"


class OperationsProcessesLazyBudgetTests(unittest.TestCase):
    def test_admin_js_bajo_presupuesto(self) -> None:
        self.assertLessEqual(JS.stat().st_size, 393216)
        self.assertLessEqual(CSS.stat().st_size, 32768)
        self.assertGreater(CHUNK.stat().st_size, 1000)
        self.assertLess(CHUNK.stat().st_size, 32768)

    def test_modulo_diferido_con_flag_y_suspense(self) -> None:
        source = APP.read_text(encoding="utf-8")
        self.assertNotIn("import { OperationsProcessesPanel } from './OperationsProcessesPanel'", source)
        self.assertIn("lazy(async () => ({", source)
        self.assertIn("import('./OperationsProcessesPanel')", source)
        self.assertIn("const AsyncOperationsProcessesPanel", source)
        self.assertIn("<AsyncOperationsProcessesPanel", source)
        self.assertIn("<Suspense fallback={<p role=\"status\">Cargando procesos", source)
        self.assertIn("(processesRealDataEnabled || import.meta.env.DEV)", source)
        self.assertEqual(len(re.findall(r"<AsyncOperationsProcessesPanel", source)), 1)
        self.assertIn("enabled={processesRealDataEnabled}", source)

    def test_chunk_real_referenciado_sin_ocultar_presupuesto(self) -> None:
        generated = JS.read_text(encoding="utf-8")
        chunk = CHUNK.read_text(encoding="utf-8")
        self.assertIn(CHUNK.name, generated)
        # Real compiled panel logic must live in the chunk, not an empty stub.
        self.assertIn("operations-processes-real", chunk)
        self.assertIn("record_ref", chunk)
        self.assertNotIn("OPERATIONS_PROCESSES_REAL_DATA_ENABLED=true", generated)


if __name__ == "__main__":
    unittest.main()
