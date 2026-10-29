#!/usr/bin/env python3
"""Condor #1320: pruebas ejecutables del clasificador y gobierno de CI.

La prueba invoca el código real de Condor al ejecutarse en el checkout del repo.
No promueve las demás filas a CUBIERTO ni acredita producción. La
clasificación B/C de las filas no probables offline va en el cuerpo del PR.
"""
from __future__ import annotations

import subprocess
import sys
import tempfile
import unittest
from pathlib import Path

from scripts.ci_change_classifier import classify
from scripts.ci_self_audit import audit_workflow

ROOT = Path(__file__).resolve().parents[1]

# IDs de la matriz auditada en main@e57986e6477865a61dd1d3ceae1f79713523b288.
# A = contrato reproducible offline; B = requiere mundo real o integración
# integral; C = política de gobierno, sin función productiva autónoma.
PROBABLE_OFFLINE = frozenset({"AGENTES-05-02", "AGENTES-05-03"})
FUERA_DE_ALCANCE_OFFLINE = {
    "AGENTES-02-06": "B: requiere HEALTH productivo degradado y reacción operativa observada",
    "AGENTES-03-04": "B: requiere E2E de interfaces y salida en navegador real",
    "AGENTES-03-05": "B: requiere recorrer todos los flujos de negocio y UI para COP",
    "AGENTES-03-06": "B: requiere observar la versión realmente desplegada en hosting",
    "AGENTES-04-01": "B: requiere autorización HTTP/DB transversal de todos los módulos",
    "AGENTES-04-03": "B: requiere contratos de relaciones de negocio entre módulos persistidos",
    "AGENTES-04-06": "B: requiere observar despliegues parcialmente aplicados",
    "AGENTES-05-01": "C: política de decisiones/revisiones delegada a Factory, no una feature autónoma",
    "AGENTES-05-04": "B: requiere integración de cada módulo declarado contra sus servicios",
    "AGENTES-05-05": "B: requiere cobertura UI funcional completa con navegador real",
}


class CoberturaCondor01Tests(unittest.TestCase):
    """AC-01..04: comportamientos de código real con entradas sintéticas."""

    def test_contrato_principal(self) -> None:
        """AGENTES-05-02: matriz de categorías reales; 05-03: validador real."""
        # Cada caso llama al clasificador que realmente decide los gates de CI.
        cases = (
            ("README.md", "rapido", "categoria_documentacion", False),
            (".github/labels.json", "rapido", "categoria_gobierno", False),
            ("tests/test_ci_change_classifier.py", "completo", "categoria_gobierno", True),
            ("tests/php/Http/AdminControllerTest.php", "selectivo", "categoria_backend", False),
            ("tests/e2e/admin.spec.mjs", "selectivo", "categoria_frontend", False),
            ("frontend/admin/AdminApp.tsx", "completo", "categoria_frontend", True),
            ("src/Http/Controller/AdminController.php", "completo", "categoria_backend", True),
            ("migrations/Version20260921010000.php", "completo", "categoria_migraciones", True),
            ("composer.lock", "completo", "categoria_dependencias", True),
            ("config/packages/security.yaml", "completo", "categoria_seguridad", True),
            ("config/version.php", "completo", "categoria_release", True),
            (".github/workflows/ci.yml", "completo", "categoria_gobierno", True),
        )
        for path, expected_mode, category, full in cases:
            with self.subTest(row="AGENTES-05-02", path=path):
                decision = classify([path], "pull_request")
                self.assertEqual(expected_mode, decision.modo)
                self.assertTrue(getattr(decision, category))
                self.assertIs(full, decision.validacion_completa)
                self.assertIs(full or expected_mode == "selectivo", decision.pruebas_base)
                if full:
                    self.assertTrue(decision.frontend and decision.backend and decision.e2e)

        # AGENTES-05-03: en vez de buscar cadenas en archivos, se ejecuta el
        # auditor de workflow real sobre YAML válido y peligroso descartable.
        valid = """name: Sintetico\non: pull_request\npermissions:\n  contents: read\njobs:\n  verificar:\n    runs-on: ubuntu-latest\n    timeout-minutes: 5\n    steps:\n      - name: Saludo\n        run: echo ok\n"""
        with tempfile.TemporaryDirectory() as folder:
            workflow = Path(folder) / "ci.yml"
            workflow.write_text(valid, encoding="utf-8")
            self.assertEqual([], audit_workflow(workflow))
            workflow.write_text(valid.replace("    timeout-minutes: 5\n", ""), encoding="utf-8")
            self.assertTrue(any("timeout-minutes" in finding for finding in audit_workflow(workflow)))

        # Ejecutar el validador documental/normativo ya existente de Condor
        # contra las fuentes actuales; nunca inferir cobertura productiva.
        suite = subprocess.run(
            [sys.executable, "-m", "unittest", "discover", "-s", "tests",
             "-p", "test_matriz_cobertura.py", "-q"],
            cwd=ROOT, capture_output=True, text=True, check=False, timeout=90,
        )
        self.assertEqual(0, suite.returncode, suite.stdout + suite.stderr)

    def test_falla_cerrado(self) -> None:
        """AGENTES-05-02/03: ruta UNKNOWN, diff vacío y workflow inseguro."""
        for changed, event in (
            (["componente-inexistente/archivo.xyz"], "pull_request"),
            ([], "pull_request"),
            (["README.md"], "push"),
        ):
            with self.subTest(row="AGENTES-05-02", changed=changed, event=event):
                selection = classify(changed, event)
                self.assertEqual("completo", selection.modo)
                self.assertTrue(selection.validacion_completa)
                self.assertTrue(selection.pruebas_base)
                self.assertTrue(selection.frontend and selection.backend and selection.e2e)
        # Una ruta segura no debe permitir que otra peligrosa reduzca gates.
        mixed = classify(["README.md", "composer.lock"], "pull_request")
        self.assertTrue(mixed.validacion_completa)
        self.assertTrue(mixed.categoria_documentacion)
        self.assertTrue(mixed.categoria_dependencias)

        unsafe = """name: Sintetico\non: pull_request_target\npermissions: write-all\njobs:\n  verificar:\n    runs-on: ubuntu-latest\n    steps:\n      - uses: actions/checkout@main\n        with:\n          persist-credentials: true\n      - run: echo ok\n        continue-on-error: true\n"""
        with tempfile.TemporaryDirectory() as folder:
            workflow = Path(folder) / "inseguro.yml"
            workflow.write_text(unsafe, encoding="utf-8")
            findings = audit_workflow(workflow)
        for expected in ("pull_request_target", "write-all", "timeout-minutes",
                         "no fijada a SHA", "persist-credentials", "continue-on-error"):
            with self.subTest(row="AGENTES-05-03", finding=expected):
                self.assertTrue(any(expected in item for item in findings), findings)

    def test_sin_datos_reales(self) -> None:
        """AC-03: separación explícita de pruebas locales y casos B/C."""
        expected = {
            "AGENTES-02-06", "AGENTES-03-04", "AGENTES-03-05",
            "AGENTES-03-06", "AGENTES-04-01", "AGENTES-04-03",
            "AGENTES-04-06", "AGENTES-05-01", "AGENTES-05-02",
            "AGENTES-05-03", "AGENTES-05-04", "AGENTES-05-05",
        }
        self.assertEqual(expected, PROBABLE_OFFLINE | FUERA_DE_ALCANCE_OFFLINE.keys())
        self.assertTrue(all(reason.startswith(("B:", "C:")) for reason in FUERA_DE_ALCANCE_OFFLINE.values()))
        # El clasificador obtiene categorías de paths inventados, no del API.
        first = classify(["./README.md", "README.md"], "pull_request")
        second = classify(["README.md"], "pull_request")
        self.assertEqual(first, second)


    def test_validar_exact_head(self) -> None:
        """AC-04: CI requiere HEAD exacto; la autoridad final es GitHub CI."""
        workflow = (ROOT / ".github/workflows/ci.yml").read_text(encoding="utf-8")
        self.assertIn("    name: Validar", workflow)
        self.assertIn("github.event.pull_request.head.sha", workflow)
        self.assertIn("name: Validar coordinación", workflow)
        self.assertIn("name: Validar documentación", workflow)
        self.assertIn("CI Factory v1", workflow)
        # El test nunca reemplaza el check terminal remoto en el SHA propio.


if __name__ == "__main__":
    unittest.main()