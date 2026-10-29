#!/usr/bin/env python3
"""Condor #1328: contratos REALES parciales de observabilidad, SEO y analítica.

Usa solo fixtures sintéticos y PHP real de src/. No consulta la matriz de
cobertura ni acredita el estado real de un despliegue, backup o smoke live.
"""
from __future__ import annotations

import json
import re
import shutil
import socket
import subprocess
import unittest
from datetime import datetime, timezone
from pathlib import Path
from unittest.mock import patch

from scripts import ci_change_classifier, ci_throughput_report, external_uptime_run_freshness
from scripts import observar_release

ROOT = Path(__file__).resolve().parents[1]
IDS = (
    "D-043-S06", "D-043-S07", "D-043-S08", "D-043-S09", "D-043-S10",
    "D-044", "D-044-S01", "D-044-S02", "D-044-S03", "D-044-S04",
    "D-044-S05", "D-044-S06",
)
TESTABLE = frozenset(("D-043-S06", "D-044-S01", "D-044-S04", "D-044-S05", "D-044-S06"))
NON_OFFLINE = {
    "D-043-S07": ("B", "Smoke público exige origen real y SHA desplegado"),
    "D-043-S08": ("B", "Smoke autenticado exige sesión real autorizada"),
    "D-043-S09": ("B", "Medición instruction-to-production necesita dos relojes reales"),
    "D-043-S10": ("B", "Primer despliegue exige hosting verificable"),
    "D-044": ("C", "Programa integral de operación sin función única"),
    "D-044-S02": ("B", "Alertas exigen un receptor externo real"),
    "D-044-S03": ("B", "Backup/recovery requiere restauración real"),
}
SHA = "a" * 40
VERSION = "0.1.195"
NOW = datetime(2026, 10, 29, 12, 0, tzinfo=timezone.utc)
PHP_FILES = (
    "src/Application/Seo/TagManagerSettings.php",
    "src/Domain/Observability/Entity/FunctionalSignal.php",
    "src/Application/Observability/FunctionalSignalReport.php",
)


def synthetic_monitor_run() -> dict:
    """Ejemplo en memoria, NUNCA un run observado de producción."""
    mod = external_uptime_run_freshness
    return {
        "id": 777, "path": mod.WORKFLOW_PATH,
        "event": "schedule", "head_branch": "main",
        "workflow_id": mod.WORKFLOW_ID,
        "repository": {"id": mod.WORKFLOW_REPOSITORY_ID},
        "head_repository": {"id": mod.WORKFLOW_REPOSITORY_ID},
        "run_started_at": "2026-10-29T11:55:00Z", "head_sha": SHA,
        "status": "completed", "conclusion": "success",
    }


PHP_PROBE = r'''
namespace Doctrine\ORM {
    interface EntityManagerInterface { public function getConnection(); }
}
namespace {
    foreach (json_decode($argv[2], true, 16, JSON_THROW_ON_ERROR) as $path) {
        $full = $argv[1].'/'.$path;
        if (!is_file($full)) { throw new RuntimeException('Falta clase real: '.$path); }
        require_once $full;
    }
    class FixtureConnection {
        public function fetchAllAssociative(string $sql, array $params): array {
            if (!str_contains($sql, 'condor_functional_signal') ||
                !isset($params['since'])) { throw new RuntimeException('SQL inesperado'); }
            return [
                ['day' => gmdate('Y-m-d'), 'type' => 'login_success', 'aggregate_count' => '3'],
                ['day' => gmdate('Y-m-d'), 'type' => 'unknown_type', 'aggregate_count' => '999'],
            ];
        }
    }
    class FixtureEntityManager implements \Doctrine\ORM\EntityManagerInterface {
        public function getConnection() { return new FixtureConnection(); }
    }
    $settings = [];
    $gtm = new \App\Application\Seo\TagManagerSettings(
        static fn(string $tenant, string $channel) => $GLOBALS['settings'][$tenant][$channel] ?? null,
        static function(string $tenant, string $channel, array $record): void {
            $GLOBALS['settings'][$tenant][$channel] = $record;
        },
    );
    $configured = $gtm->configure('tenant-a', 'public', 'gtm-SYNTH123');
    $before = $gtm->renderPublicTag('tenant-a', 'public', true, true, true);
    $gtm->setEnabled('tenant-a', 'public', true);
    $noConsent = $gtm->renderPublicTag('tenant-a', 'public', true, false, true);
    $noCsp = $gtm->renderPublicTag('tenant-a', 'public', true, true, false);
    $allowed = $gtm->renderPublicTag('tenant-a', 'public', true, true, true);
    $foreign = $gtm->renderPublicTag('tenant-b', 'public', true, true, true);
    $invalid = false;
    try { $gtm->configure('tenant-b', 'public', 'UA-INVALID'); }
    catch (\DomainException) { $invalid = true; }
    $report = new \App\Application\Observability\FunctionalSignalReport(new FixtureEntityManager());
    $series = $report->dailySeries(1);
    $today = gmdate('Y-m-d');
    $counts = $series[$today] ?? [];
    echo json_encode([
        'configured' => $configured,
        'before' => $before, 'without_consent' => $noConsent,
        'without_csp' => $noCsp, 'enabled' => $allowed,
        'foreign' => $foreign, 'bad_container_denied' => $invalid,
        'signal_count' => $counts['login_success'] ?? null,
        'unknown_signal_visible' => array_key_exists('unknown_type', $counts),
        'days' => count($series),
        'csv_has_login' => str_contains($report->toCsv(1), 'login_success'),
    ], JSON_THROW_ON_ERROR);
}
'''


def run_php() -> dict:
    exe = shutil.which("php")
    if exe is None:
        raise AssertionError("UNKNOWN: PHP CLI obligatorio")
    missing = [path for path in PHP_FILES if not (ROOT / path).is_file()]
    if missing:
        raise AssertionError("UNKNOWN: faltan fuentes PHP reales: " + ", ".join(missing))
    result = subprocess.run(
        [exe, "-d", "allow_url_fopen=0", "-d", "display_errors=stderr",
         "-r", PHP_PROBE, str(ROOT), json.dumps(PHP_FILES)],
        cwd=ROOT, capture_output=True, text=True, timeout=25, check=False,
    )
    if result.returncode != 0 or result.stderr.strip():
        raise AssertionError("PHP real no satisfizo la aserción: " + result.stderr[-800:])
    return json.loads(result.stdout)


class CoberturaCondor09Tests(unittest.TestCase):
    def test_contrato_principal(self) -> None:
        """AC-01: PHP real y cuatro proyecciones puras, alcance parcial."""
        self.assertEqual(12, len(IDS))
        self.assertEqual(set(IDS), TESTABLE | NON_OFFLINE.keys())
        self.assertEqual(5, len(TESTABLE))
        self.assertTrue(all(kind in {"B", "C", "D"} and reason
                            for kind, reason in NON_OFFLINE.values()))
        health = {"status": "ok", "version": VERSION, "release_sha": SHA,
                  "schema_up_to_date": True}
        self.assertTrue(observar_release.validar_health(
            "application/json", json.dumps(health).encode(), VERSION, SHA,
        ))
        observation = external_uptime_run_freshness.project_run_freshness(
            [synthetic_monitor_run()], now=NOW, snapshot_complete=True,
            snapshot_total_count=1,
        )
        self.assertEqual("FRESH", observation["monitor_freshness"])
        self.assertEqual("UNKNOWN", observation["product_health"])
        self.assertEqual(SHA, observation["head_sha"])
        baseline = [{"duration_seconds": 20} for _ in range(3)]
        self.assertEqual("regression", ci_throughput_report.regression_result(
            36, baseline, "success")["status"])
        self.assertEqual("normal", ci_throughput_report.regression_result(
            30, baseline, "success")["status"])
        actual = run_php()
        self.assertEqual("GTM-SYNTH123", actual["configured"]["container_id"])
        self.assertFalse(actual["configured"]["enabled"])
        self.assertIn('data-gtm-container="GTM-SYNTH123"', actual["enabled"])
        self.assertEqual(3, actual["signal_count"])
        self.assertFalse(actual["unknown_signal_visible"])
        self.assertEqual(1, actual["days"])
        self.assertTrue(actual["csv_has_login"])

    def test_falla_cerrado(self) -> None:
        """AC-02: identidad, consentimiento, permisos y evidencia incompleta."""
        health = {"status": "ok", "version": VERSION, "release_sha": "b" * 40,
                  "schema_up_to_date": True}
        with self.assertRaises(observar_release.ObservacionDeployPendiente):
            observar_release.validar_health(
                "application/json", json.dumps(health).encode(), VERSION, SHA,
            )
        with self.assertRaises(observar_release.ObservacionError):
            observar_release.validar_health("text/plain", b"{}", VERSION, SHA)
        monitor = external_uptime_run_freshness
        self.assertEqual("UNKNOWN", monitor.project_run_freshness(
            [synthetic_monitor_run()], now=NOW)["monitor_freshness"])
        bad = synthetic_monitor_run()
        bad["workflow_id"] = 1
        self.assertEqual("UNKNOWN", monitor.project_run_freshness(
            [bad], now=NOW, snapshot_complete=True,
            snapshot_total_count=1)["monitor_freshness"])
        self.assertEqual("UNKNOWN", monitor.project_run_freshness(
            [], now=NOW, snapshot_complete=True,
            snapshot_total_count=0)["monitor_freshness"])
        self.assertEqual("insufficient_baseline", ci_throughput_report.regression_result(
            60, [{"duration_seconds": 20}], "success")["status"])
        self.assertEqual("not_evaluated", ci_throughput_report.regression_result(
            60, [{"duration_seconds": 20}] * 3, "failure")["status"])
        rendered = run_php()
        for key in ("before", "without_consent", "without_csp", "foreign"):
            self.assertEqual("", rendered[key], key)
        self.assertTrue(rendered["bad_container_denied"])

    def test_sin_datos_reales(self) -> None:
        """AC-03: fixtures ficticios sin I/O de red ni credenciales."""
        with patch.object(socket, "create_connection", side_effect=AssertionError(
            "No se admite conexión a red")):
            self.assertEqual("UNKNOWN", external_uptime_run_freshness.project_run_freshness(
                [synthetic_monitor_run()], now=NOW)["product_health"])
            self.assertIsNone(ci_throughput_report.elapsed_seconds("bad", "timestamp"))
        self.assertFalse(re.search(r"(?i)(?:access_token|api_key|password)\s*[:=]\s*['\"][^'\"]+", PHP_PROBE))
        for forbidden in ("https://", "curl_exec", "file_get_contents"):
            self.assertNotIn(forbidden, PHP_PROBE)
        self.assertEqual(set(PHP_FILES), {
            "src/Application/Seo/TagManagerSettings.php",
            "src/Domain/Observability/Entity/FunctionalSignal.php",
            "src/Application/Observability/FunctionalSignalReport.php",
        })

    def test_ci_gate_contract(self) -> None:
        """AC-04: el clasificador real exige pruebas; Validar remoto es externo."""
        selection = ci_change_classifier.classify(
            ["tests/test_cobertura_condor_09.py"], "pull_request"
        )
        self.assertTrue(selection.pruebas_base)
        self.assertIn(selection.modo, {"selectivo", "completo"})
        self.assertEqual(12, len(IDS))


if __name__ == "__main__":
    unittest.main()
