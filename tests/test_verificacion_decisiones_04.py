#!/usr/bin/env python3
"""Condor #1547: regresiones ejecutadas del lote D-041..D-044.

Estas pruebas corren contra código local real o loopback sintético, nunca
certifican datos, despliegues, métricas ni accesibilidad de producción.
La cobertura integral pendiente está delegada explícitamente a #1594.
"""
from __future__ import annotations

import io
import json
import sys
import tempfile
import unittest
from pathlib import Path

ROOT = Path(__file__).resolve().parents[1]
for directory in (ROOT, ROOT / "tests"):
    if str(directory) not in sys.path:
        sys.path.insert(0, str(directory))

WITNESSES = {
    "D-041-S07": "test_public_site_accessibility.AccesibilidadSitioPublicoTests.test_detecta_salto_de_encabezados_y_control_sin_label",
    "D-041-S08": "test_landing_empezar.LandingEmpezarTests.test_network_map_encodes_each_stop_state_in_its_shape_and_text",
    "D-041-S09": "test_landing_empezar.LandingEmpezarTests.test_primary_call_to_action_leads_to_the_plan_configurator",
    "D-043": "test_release_evidence.ReleaseEvidenceTests.test_unknown_observation_state_never_promotes_release",
    "D-043-S01": "test_release_evidence.ReleaseEvidenceTests.test_deploy_observed_state_is_not_promoted_by_complete_checks",
    "D-043-S02": "test_release_evidence.ReleaseEvidenceTests.test_manifest_uses_canonical_version_and_exact_sha",
    "D-043-S03": "test_observar_release.ReleaseObserverTests.test_js_con_mime_legacy_de_hostinger_es_aceptado",
    "D-043-S04": "test_release_evidence.ReleaseEvidenceTests.test_manifest_uses_canonical_version_and_exact_sha",
    "D-043-S05": "test_release_evidence.ReleaseEvidenceTests.test_migration_requires_migration_and_cache_checks",
    "D-043-S06": "test_observar_release.ReleaseObserverTests.test_sha_distinto_no_se_reintenta_ni_visita_paginas",
    "D-043-S07": "test_observar_release.ReleaseObserverTests.test_release_exacta_y_smoke_publico",
    "D-043-S08": "test_authenticated_production_smoke.AuthenticatedProductionSmokeTests.test_tenant_auth_dashboard_and_cross_scope_denial",
    "D-043-S09": "test_ci_throughput_report.ThroughputTests.test_elapsed_seconds",
    "D-044-S01": "test_ci_external_uptime_run_freshness.ExternalUptimeRunFreshnessTests.test_successful_run_does_not_imply_healthy_product",
    "D-044-S02": "test_ci_external_uptime_watch.ExternalUptimeWatchTests.test_open_alert_is_deduplicated_by_marker",
    "D-044-S03": "test_backup_persistent_files.PersistentFileBackupTest.test_round_trip_verifies_bytes_nested_paths_and_private_permissions",
    "D-044-S04": "test_ci_throughput_report.ThroughputTests.test_regression_requires_three_samples",
}
GAPS = {
    "D-042-S05": "C: política de avance por slices, no función autónoma",
    "D-043-S10": "B: primer deploy V0.1.0 histórico sin prueba offline válida",
    "D-044": "C: programa integral de operaciones sin entrypoint único",
}
EXPECTED = {
    "D-041-S07", "D-041-S08", "D-041-S09", "D-042-S05", "D-043",
    *(f"D-043-S{number:02d}" for number in range(1, 11)),
    "D-044", *(f"D-044-S{number:02d}" for number in range(1, 5)),
}
DOC = ROOT / "docs/verificacion/decisiones-04.md"


class VerificacionDecisiones04Tests(unittest.TestCase):
    def test_regresiones_reales_por_decision(self) -> None:
        """AC-01/02: ejecutar aserciones, no aceptar la mera existencia del test."""
        self.assertEqual(set(WITNESSES) | set(GAPS), EXPECTED)
        self.assertFalse(set(WITNESSES) & set(GAPS))
        for decision, qualified_method in WITNESSES.items():
            with self.subTest(decision=decision, test=qualified_method):
                suite = unittest.defaultTestLoader.loadTestsFromName(qualified_method)
                self.assertEqual(suite.countTestCases(), 1, qualified_method)
                output = io.StringIO()
                result = unittest.TextTestRunner(stream=output, verbosity=0).run(suite)
                self.assertEqual(result.testsRun, 1, output.getvalue())
                self.assertEqual(result.skipped, [], "UNKNOWN: prueba no ejecutada")
                self.assertTrue(result.wasSuccessful(), output.getvalue())

    def test_fase_e_identidad_fallan_cerrado(self) -> None:
        """AC-02: JSON ambiguo, fase ajena y SHA inválido nunca dan GREEN."""
        from scripts import d043_phase_resolution as phase
        from scripts import release_evidence as release

        with tempfile.TemporaryDirectory() as directory:
            source = Path(directory) / "datos.yml"
            canonical = {"version": 1, "project": "pl0n3r/Condor",
                         "phase": "construccion"}
            source.write_text(json.dumps(canonical), encoding="utf-8")
            self.assertEqual(phase.resolve_phase(source, "pl0n3r/Condor"),
                             "construccion")
            self.assertEqual(phase.resolve_phase(source, "otro/Repo"), "desconocida")
            source.write_text(
                '{"version":1,"version":1,"project":"pl0n3r/Condor",'
                '"phase":"construccion"}', encoding="utf-8")
            self.assertEqual(phase.resolve_phase(source, "pl0n3r/Condor"),
                             "desconocida")
            source.unlink()
            self.assertEqual(phase.resolve_phase(source, "pl0n3r/Condor"),
                             "desconocida")
        with self.assertRaises(release.EvidenceError):
            release.build_manifest_explicit(version="0.1.198", sha="SHA_INCOMPLETO",
                                            changed_paths=["README.md"])

    def test_inventario_y_brechas_no_simulan_produccion(self) -> None:
        """AC-01: cada fila tiene resultado explícito y referencia a hoja hija."""
        self.assertTrue(DOC.is_file(), "Falta documento trazable #1547")
        content = DOC.read_text(encoding="utf-8")
        rows = [line.split("|")[1].strip() for line in content.splitlines()
                if line.startswith("| D-")]
        self.assertEqual(len(rows), len(EXPECTED))
        self.assertEqual(set(rows), EXPECTED)
        self.assertEqual(len(set(rows)), len(rows))
        self.assertIn("Condor#1594", content)
        self.assertIn("UNKNOWN", content)
        self.assertIn("LOCAL_PARTIAL", content)
        self.assertNotIn("ACREDITADO EN PRODUCCIÓN", content)
        for decision in WITNESSES:
            with self.subTest(decision=decision):
                self.assertIn(WITNESSES[decision], content)
        for decision in GAPS:
            with self.subTest(decision=decision):
                self.assertIn(GAPS[decision], content)


if __name__ == "__main__":
    unittest.main()
