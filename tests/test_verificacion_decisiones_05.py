#!/usr/bin/env python3
"""Condor #1548: 20 decisiones D-044-S05..D-049-S04; testigos parciales.

Reejecuta pruebas existentes sobre codigo de produccion con fixtures sintéticos.
No usa red externa, secretos, produccion, DNS ni certificados reales.
No certifica go-live, ni declara cumplidas integralmente estas decisiones.
"""
from __future__ import annotations

import importlib.util
import re
import sys
import unittest
from pathlib import Path

ROOT = Path(__file__).resolve().parents[1]
sys.path.insert(0, str(ROOT))

# (ID, archivo de prueba existente, método exacto, clase, brecha).
# A: subcontrato local; B: evidencia externa pendiente; C: gobierno.
ROWS = [
  [
    "D-044-S05",
    "tests/test_seo_settings_http.py",
    "test_acceptance",
    "A",
    "SSR/sitemap/robots por cada dominio requieren E2E con canonical y host reales"
  ],
  [
    "D-044-S06",
    "tests/test_cobertura_condor_09.py",
    "test_contrato_principal",
    "A",
    "instrumentación integral de onboarding e inventario requiere eventos reales acotados"
  ],
  [
    "D-044-S07",
    "tests/test_security_workflow_contract.py",
    "test_owner_exact_decision_commands_use_versioned_factory_security",
    "C",
    "faltan calendario mensual de dependencias y revisión arquitectónica trimestral acreditados"
  ],
  [
    "D-044-S08",
    "tests/test_post_deploy.py",
    "test_construction_reconciles_pending_schema_before_cache",
    "A",
    "smoke/backup/rehearsal y telemetría operativos requieren recibos verificables"
  ],
  [
    "D-044-S09",
    "tests/test_post_deploy.py",
    "test_live_fails_closed_without_migrating_or_touching_cache",
    "A",
    "migración y esquema desplegado no se infieren de un sandbox"
  ],
  [
    "D-044-S10",
    "tests/test_security_workflow_contract.py",
    "test_consumer_code_is_never_checked_out_or_executed",
    "C",
    "falta revisión trimestral de amenazas autorizada y hallazgos accionables"
  ],
  [
    "D-044-S11",
    "tests/test_post_deploy.py",
    "test_backup_failure_prevents_migration_and_cache",
    "C",
    "portabilidad Hostinger a otro proveedor no ensayada ni medida"
  ],
  [
    "D-045",
    "tests/test_indexnow_notifier.py",
    "test_acceptance",
    "A",
    "falta integración integral con HTTP de publicación, DNS real y CSP por tenant"
  ],
  [
    "D-045-S01",
    "tests/test_indexnow_notifier.py",
    "test_acceptance",
    "A",
    "envío IndexNow a proveedor real no autorizado en este lote"
  ],
  [
    "D-045-S02",
    "tests/test_tag_manager_settings.py",
    "test_acceptance",
    "A",
    "snippets GTM en navegadores/hosts reales y consentimiento requieren E2E"
  ],
  [
    "D-045-S03",
    "tests/test_tag_manager_settings.py",
    "test_php_lint_and_no_external_transport",
    "A",
    "CSP HTTP multitenant y ausencia de secretos requieren integración completa"
  ],
  [
    "D-046",
    "tests/test_provision_platform_owner_command_unit.py",
    "test_acceptance",
    "B",
    "invariante singleton/roles debe acreditarse con carrera y base real de pruebas"
  ],
  [
    "D-047",
    "tests/test_shared_diagnostic_http.py",
    "test_acceptance",
    "A",
    "retención, revocación, auditoría y 5xx productivos requieren evidencia real"
  ],
  [
    "D-048",
    "tests/test_platform_owner_api_permissions.py",
    "test_acceptance",
    "B",
    "shell compartido y barra de contexto requieren sesión de navegador autenticada"
  ],
  [
    "D-048-S02",
    "tests/test_brechas_condor_18.py",
    "test_estados_unknown_explicitos",
    "B",
    "progreso visible en runtime real y navegación read-only no observados"
  ],
  [
    "D-049",
    "tests/test_cobertura_condor_11.py",
    "test_contrato_principal",
    "B",
    "servidor de dominio personalizado, propiedad DNS y TLS aún no acreditados"
  ],
  [
    "D-049-S01",
    "tests/test_cobertura_condor_11.py",
    "test_contrato_principal",
    "A",
    "navegación real a slug y host personalizado requiere E2E"
  ],
  [
    "D-049-S02",
    "tests/test_cobertura_condor_11.py",
    "test_falla_cerrado",
    "A",
    "unicidad real y estado activo requieren persistencia de pruebas y DNS"
  ],
  [
    "D-049-S03",
    "tests/test_cobertura_condor_11.py",
    "test_sin_datos_reales",
    "B",
    "DNS/TLS y prueba de propiedad del host exigen recibos externos"
  ],
  [
    "D-049-S04",
    "tests/test_cobertura_condor_11.py",
    "test_falla_cerrado",
    "A",
    "Host header HTTP/HTTPS en reverse proxy real requiere prueba externa"
  ]
]
CLASS_FOR_PATH = {
  "tests/test_seo_settings_http.py": "SeoSettingsHttpTests",
  "tests/test_cobertura_condor_09.py": "CoberturaCondor09Tests",
  "tests/test_security_workflow_contract.py": "SecurityWorkflowContractTests",
  "tests/test_post_deploy.py": "PostDeployStageTest",
  "tests/test_indexnow_notifier.py": "IndexNowAdaptadorTests",
  "tests/test_tag_manager_settings.py": "GtmConfigTenantTests",
  "tests/test_provision_platform_owner_command_unit.py": "ProvisionPlatformOwnerCommandUnitTests",
  "tests/test_shared_diagnostic_http.py": "DiagnosticoCompartidoHttpTests",
  "tests/test_platform_owner_api_permissions.py": "PlataformaOwnerApiPermisosTests",
  "tests/test_brechas_condor_18.py": "BrechasCondor18Tests",
  "tests/test_cobertura_condor_11.py": "CoberturaCondor11Tests"
}


def find_witness(source: str, method: str) -> bool:
    """Renombrar/retirar el testigo no puede preservar un verde nominal."""
    return bool(re.search(r"(?m)^\s+def " + re.escape(method) +
                          r"\(self(?:\s*[,)]|\))", source))


def run_case(filename: str, classname: str, method: str) -> unittest.TestResult:
    if not filename.startswith("tests/test_") or not filename.endswith(".py"):
        raise AssertionError("UNKNOWN: ruta de testigo no autorizada")
    path = ROOT / filename
    if not path.is_file():
        raise AssertionError("UNKNOWN: testigo ausente: " + filename)
    name = "_condor1548_" + re.sub(r"\W", "_", filename)
    spec = importlib.util.spec_from_file_location(name, path)
    if spec is None or spec.loader is None:
        raise AssertionError("UNKNOWN: testigo no importable")
    module = importlib.util.module_from_spec(spec)
    spec.loader.exec_module(module)
    cls = getattr(module, classname)
    result = unittest.TestResult()
    cls(method).run(result)
    return result


class Decisiones05Verification(unittest.TestCase):
    def test_01_twenty_exact_decisions_and_witnesses(self) -> None:
        """Valida veinte identidades únicas y testigos reales en archivos autorizados."""
        names = [row[0] for row in ROWS]
        self.assertEqual(20, len(names))
        self.assertEqual(20, len(set(names)))
        self.assertEqual("D-044-S05", names[0])
        self.assertEqual("D-049-S04", names[-1])
        self.assertEqual(set(CLASS_FOR_PATH), {row[1] for row in ROWS})
        for identifier, filename, method, level, pending in ROWS:
            with self.subTest(decision=identifier):
                self.assertIn(level, {"A", "B", "C"})
                self.assertTrue(pending)
                source = ROOT / filename
                self.assertTrue(source.is_file(), filename)
                self.assertTrue(find_witness(source.read_text(encoding="utf-8"),
                                             method), identifier)

    def test_02_mutated_or_forged_witness_fails_closed(self) -> None:
        """Demuestra que renombrar testigos impide verde nominal."""
        for identifier, filename, method, _, _ in ROWS:
            source = (ROOT / filename).read_text(encoding="utf-8")
            with self.subTest(decision=identifier):
                changed = source.replace("def " + method, "def removed_" + method)
                self.assertFalse(find_witness(changed, method))
                self.assertFalse(find_witness(source, "test_forged_witness_1548"))
        self.assertEqual({"A", "B", "C"}, {row[3] for row in ROWS})

    def test_03_execute_real_local_contracts(self) -> None:
        """Ejecutar todos los testigos unicos sobre clases reales, sin green ficticio."""
        seen = set()
        for identifier, filename, method, _, _ in ROWS:
            key = (filename, method)
            if key in seen:
                continue
            seen.add(key)
            with self.subTest(decision=identifier, witness=filename + ":" + method):
                result = run_case(filename, CLASS_FOR_PATH[filename], method)
                self.assertEqual(1, result.testsRun, "UNKNOWN: sin ejecución")
                self.assertFalse(result.skipped, "UNKNOWN: testigo omitido")
                self.assertFalse(result.expectedFailures, "UNKNOWN: testigo expectedFailure")
                self.assertTrue(result.wasSuccessful(),
                                "UNKNOWN: prueba real fallida: " + filename +
                                ":" + method)

    def test_04_report_all_rows_as_partial(self) -> None:
        """Vincula cada fila documental con su testigo y brecha exactos."""
        report = (ROOT / "docs/verificacion/decisiones-05.md").read_text(
            encoding="utf-8")
        ids = re.findall(r"(?m)^\| `(D-\d{3}(?:-S\d{2})?)` \|", report)
        self.assertEqual([row[0] for row in ROWS], ids)
        self.assertNotIn("| CUBIERTO |", report)
        row_by_id = {
            match.group(1): match.group(0)
            for match in re.finditer(r"(?m)^\| `(D-\d{3}(?:-S\d{2})?)` \|.*$", report)
        }
        for identifier, filename, method, level, pending in ROWS:
            with self.subTest(decision=identifier):
                row = row_by_id[identifier]
                self.assertIn("`" + filename + "` · `" + method + "`", row)
                self.assertIn("| " + level + " parcial |", row)
                self.assertIn(pending, row)

    def test_05_classifier_and_authority(self) -> None:
        """Comprueba selección de CI y respeto del alcance autorizado."""
        from scripts.ci_change_classifier import classify
        selection = classify(
            ["docs/verificacion/decisiones-05.md",
             "tests/test_verificacion_decisiones_05.py"],
            "pull_request",
        )
        self.assertTrue(selection.pruebas_base)
        self.assertIn(selection.modo, {"selectivo", "completo"})
        self.assertNotIn("docs/matriz-cobertura.md", [row[1] for row in ROWS])


if __name__ == "__main__":
    unittest.main()
