#!/usr/bin/env python3
"""Condor #1329: comportamiento real offline, sin atestar integridad live.

Las filas A representan solo la porcion realmente ejecutada por fixtures
sinteticos. B/C no son falsos pases ni alteran la matriz de cobertura.
"""
from __future__ import annotations

import importlib.util
import re
import socket
import sys
import unittest
from pathlib import Path
from unittest.mock import patch

from scripts import d043_pending_releases as releases

ROOT = Path(__file__).resolve().parents[1]
MATRIX = ROOT / "docs/matriz-cobertura.md"
IDS = (
    "D-044-S07", "D-044-S08", "D-044-S09", "D-044-S10",
    "D-044-S11", "D-045", "D-045-S01", "D-045-S02",
    "D-045-S03", "D-046", "D-047", "D-048",
)
A = {
    "D-044-S08": "scripts/d043_pending_releases.py",
    "D-044-S09": "scripts/post-deploy.sh",
    "D-045": "src/Application/Seo/IndexNowNotifier.php",
    "D-045-S01": "src/Application/Seo/IndexNowNotifier.php",
    "D-045-S02": "src/Application/Seo/TagManagerSettings.php",
    "D-045-S03": "src/Application/Seo/TagManagerSettings.php",
    "D-047": "src/Http/Controller/SharedDiagnosticController.php",
}
REST = {
    "D-044-S07": ("C", "Priorizar deuda y revisar arquitectura son decisiones humanas"),
    "D-044-S10": ("C", "Revision trimestral de amenazas y findings: evidencia periodica externa"),
    "D-044-S11": ("C", "Portabilidad integral Hostinger/AWS es una decision arquitectonica"),
    "D-046": ("B", "Unicidad del propietario ante carrera TOCTOU exige transaccion y BD real"),
    "D-048": ("B", "Contexto tenant/sede, barra visible y permisos requieren HTTP y navegador"),
}


def matrix_rows(source: object) -> dict[str, tuple[str, ...]]:
    if not isinstance(source, str):
        raise ValueError("UNKNOWN: matriz ausente")
    result: dict[str, tuple[str, ...]] = {}
    pattern = re.compile(r"^\|\s*" + chr(96)
                         + r"(D-\d{3}(?:-S\d{2})?)" + chr(96)
                         + r"\s*·\s*" + chr(96)
                         + r"ESPECIFICACIONES\.md" + chr(96) + r"\s*\|")
    for line in source.splitlines():
        match = pattern.match(line)
        if match is None or match.group(1) not in IDS:
            continue
        key = match.group(1)
        fields = tuple(x.strip() for x in line.strip().strip("|").split("|"))
        if (key in result or len(fields) != 5 or not all(fields)
                or fields[0] != f"{chr(96)}{key}{chr(96)} · {chr(96)}ESPECIFICACIONES.md{chr(96)}"
                or fields[4] != "#1"):
            raise ValueError("UNKNOWN: fila invalida o duplicada " + key)
        status = fields[2].strip(chr(96))
        if status not in {"NO_VERIFICABLE", "EN_CURSO", "CUBIERTO", "DESCARTADO"}:
            raise ValueError("UNKNOWN: estado de matriz invalido " + key)
        evidence = fields[3]
        if status == "NO_VERIFICABLE" and "Falta evidencia integral" not in evidence:
            raise ValueError("UNKNOWN: falta la brecha explicita " + key)
        if status == "CUBIERTO":
            # Comprobación estructural offline; la reconciliación autentica
            # independientemente que el PR citado esté efectivamente merged.
            triad = (
                r"(?:src|scripts|frontend|templates|config|ops|public)/[\w./-]+",
                r"tests/[\w./-]+",
                r"\bPR\s+#\d+\s+MERGED\b",
            )
            if not all(re.search(pattern, evidence) for pattern in triad):
                raise ValueError("UNKNOWN: CUBIERTO sin triada " + key)
        result[key] = fields
    if set(result) != set(IDS):
        raise ValueError("UNKNOWN: filas del grupo faltantes")
    return result


def run_real_test(path: str, klass: str, method: str) -> int:
    """Carga y ejecuta prueba que invoca el PHP/shell de produccion real."""
    source = ROOT / path
    if not source.is_file() or source.is_symlink():
        raise AssertionError("UNKNOWN: test ejecutable ausente " + path)
    name = "condor_1329_" + source.stem
    spec = importlib.util.spec_from_file_location(name, source)
    if spec is None or spec.loader is None:
        raise AssertionError("UNKNOWN: import imposible " + path)
    mod = importlib.util.module_from_spec(spec)
    sys.modules[name] = mod
    try:
        spec.loader.exec_module(mod)
    except BaseException:
        sys.modules.pop(name, None)
        raise
    case = getattr(mod, klass)(method)
    result = unittest.TestResult()
    case.run(result)
    if result.testsRun != 1 or result.skipped or not result.wasSuccessful():
        raise AssertionError(
            f"UNKNOWN: {path}::{method}, ran={result.testsRun}, "
            f"skipped={result.skipped}, failures={(result.failures+result.errors)[:1]}"
        )
    return result.testsRun


def event(state: str, sha: str = "a" * 40) -> dict:
    version = "0.1.195"
    if state == "pending":
        body = f"🚧 DEPLOY_OBSERVED automático: producción sirve V {version} ({sha}), sintético"
    else:
        body = f"✅ VALIDATED_IN_PRODUCTION automático: producción sirve V {version} ({sha}) sintético"
    return {"user": {"login": releases.BOT_LOGIN}, "body": body}


class CoberturaCondor10Tests(unittest.TestCase):
    @classmethod
    def setUpClass(cls) -> None:
        cls.rows = matrix_rows(MATRIX.read_text(encoding="utf-8"))

    def test_contrato_principal(self) -> None:
        """AC-01: 7 comportamientos A offline; 5 B/C sin atestacion falsa."""
        self.assertEqual(set(IDS), set(A) | set(REST))
        self.assertFalse(set(A) & set(REST))
        self.assertEqual(set(IDS), set(self.rows))
        for key, path in A.items():
            self.assertTrue((ROOT / path).is_file(), key)
        for key, (category, reason) in REST.items():
            self.assertIn(category, ("B", "C", "D"), key)
            self.assertGreater(len(reason), 25)
            self.assertIn(
                self.rows[key][2],
                tuple(chr(96) + x + chr(96) for x in
                      ("NO_VERIFICABLE", "EN_CURSO", "CUBIERTO", "DESCARTADO")),
            )

        # D-044-S08: reducer real, idempotencia por SHA y autor bot.
        self.assertEqual(releases.reduce_pending_releases([event("pending")]),
                         [{"version": "0.1.195", "sha": "a" * 40}])
        self.assertEqual(releases.reduce_pending_releases(
            [event("pending"), event("validated")]), [])

        # D-044-S09: script real post-deploy, fake backup/DB/hosting.
        self.assertEqual(1, run_real_test(
            "tests/test_post_deploy.py", "PostDeployStageTest",
            "test_construction_reconciles_pending_schema_before_cache"))

        # D-045/S01: notificador real, dos tenants, cola fake y dedupe.
        self.assertEqual(1, run_real_test(
            "tests/test_indexnow_notifier.py", "IndexNowAdaptadorTests",
            "test_acceptance"))
        # D-045/S02: GTM real, consentimiento, aislamiento y validacion.
        self.assertEqual(1, run_real_test(
            "tests/test_tag_manager_settings.py", "GtmConfigTenantTests",
            "test_acceptance"))
        # D-045-S03: ejecutar de forma explicita ambas rutas negativas
        # sobre codigo PHP REAL: tenants cruzados, URLs privadas y falta de
        # consentimiento nunca producen una operacion valida.
        self.assertEqual(1, run_real_test(
            "tests/test_indexnow_notifier.py", "IndexNowAdaptadorTests",
            "test_acceptance"))
        self.assertEqual(1, run_real_test(
            "tests/test_tag_manager_settings.py", "GtmConfigTenantTests",
            "test_acceptance"))

        # D-047: controlador, share service y sanitizer reales, ORM fake.
        self.assertEqual(1, run_real_test(
            "tests/test_shared_diagnostic_http.py", "DiagnosticoCompartidoHttpTests",
            "test_acceptance"))

    def test_falla_cerrado(self) -> None:
        """AC-02: negar evidencia truncada, forjada, permisos o SQL dudosos."""
        original = MATRIX.read_text(encoding="utf-8")
        for key in IDS:
            row = next(s for s in original.splitlines()
                       if s.startswith("| " + chr(96) + key + chr(96)
                                       + " · " + chr(96) + "ESPECIFICACIONES.md"))
            for bad in (original.replace(row + "\n", "", 1),
                        original + "\n" + row,
                        original.replace(row, row.replace("NO_VERIFICABLE", "CUBIERTO"), 1)):
                with self.subTest(id=key):
                    with self.assertRaises(ValueError):
                        matrix_rows(bad)

        # Acreditacion sintactica futurible; la suite no fabrica un PR
        # realmente fusionado: el proceso de reconciliacion valida esa prueba.
        example = next(s for s in original.splitlines()
                       if s.startswith("| " + chr(96) + IDS[0] + chr(96)
                                       + " · " + chr(96) + "ESPECIFICACIONES.md"))
        triad = "src/Example.php + tests/test_synthetic.py + PR #123 MERGED (simulado)"
        promoted = original.replace(
            example, example.replace(chr(96) + "NO_VERIFICABLE" + chr(96),
                                     chr(96) + "CUBIERTO" + chr(96), 1).replace(
                                         example.split("|")[4].strip(), triad, 1),
            1,
        )
        self.assertEqual(matrix_rows(promoted)[IDS[0]][2],
                         chr(96) + "CUBIERTO" + chr(96))

        forged = {**event("validated"), "user": {"login": "actor-sintetico"}}
        self.assertEqual(releases.reduce_pending_releases(
            [event("pending"), forged]), [{"version": "0.1.195", "sha": "a" * 40}])
        with self.assertRaises(releases.PendingReleaseError):
            releases.reduce_pending_releases([event("pending"), event("pending", "b" * 40)])

        for method in (
            "test_auto_migrate_opt_out_preserves_fail_closed_behavior",
            "test_live_fails_closed_without_migrating_or_touching_cache",
            "test_backup_failure_prevents_migration_and_cache",
            "test_construction_blocks_destructive_versioned_migration",
        ):
            with self.subTest(method=method):
                self.assertEqual(1, run_real_test(
                    "tests/test_post_deploy.py", "PostDeployStageTest", method))
        # Ejerce de nuevo codigo PHP real con entradas maliciosas/tenants falsos.
        self.assertEqual(1, run_real_test(
            "tests/test_indexnow_notifier.py", "IndexNowAdaptadorTests",
            "test_acceptance"))
        self.assertEqual(1, run_real_test(
            "tests/test_tag_manager_settings.py", "GtmConfigTenantTests",
            "test_acceptance"))
        self.assertEqual(1, run_real_test(
            "tests/test_shared_diagnostic_http.py", "DiagnosticoCompartidoHttpTests",
            "test_acceptance"))

    def test_sin_datos_reales(self) -> None:
        """AC-03: solo ejemplos, no proveedores, secretos ni datos reales."""
        with patch.object(socket, "create_connection",
                          side_effect=AssertionError("red prohibida")):
            self.assertEqual(releases.reduce_pending_releases(
                [event("pending"), event("validated")]), [])
        for path in A.values():
            file = ROOT / path
            self.assertTrue(file.is_file() and not file.is_symlink())
            self.assertTrue(file.resolve().is_relative_to(ROOT))
        self.assertEqual(set(A) | set(REST), set(IDS))
        source = Path(__file__).read_text(encoding="utf-8")
        for forbidden in ("-----BEGIN " + "PRIVATE KEY-----",
                          "GH_" + "TOKEN=", "GITHUB_" + "TOKEN=",
                          "https://api." + "github.com"):
            self.assertNotIn(forbidden, source)

    def test_validar_exigido(self) -> None:
        """AC-04: verifica cableado; green solo lo acredita GitHub."""
        from scripts.ci_change_classifier import classify

        selection = classify(["tests/test_cobertura_condor_10.py"], "pull_request")
        self.assertTrue(selection.pruebas_base)
        self.assertIn(selection.modo, ("selectivo", "completo"))
        workflow = (ROOT / ".github/workflows/ci.yml").read_text(encoding="utf-8")
        self.assertIn("name: Validar", workflow)
        self.assertIn("pull_request", workflow)
        self.assertIn(
            "python3 -m unittest discover -s tests -p 'test_ci_*.py'",
            workflow,
        )
        # #1374 is a separately reserved prerequisite: without its bridge,
        # CI may pass while never running the four acceptance methods.
        bridge = ROOT / "tests/test_ci_cobertura_condor_10.py"
        self.assertTrue(bridge.is_file() and not bridge.is_symlink(),
                        "UNKNOWN: CI bridge #1374 not merged")
        bridge_source = bridge.read_text(encoding="utf-8")
        self.assertIn("def load_tests(", bridge_source)
        self.assertIn("CoberturaCondor10RuntimeTests", bridge_source)
        self.assertIn("_execute_target(TARGET)", bridge_source)


if __name__ == "__main__":
    unittest.main()
