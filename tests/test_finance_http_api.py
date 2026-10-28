"""Acceptance #1042: real PHP service behavior and HTTP-boundary contracts."""

from __future__ import annotations

import json
import shutil
import subprocess
import unittest
from pathlib import Path

ROOT = Path(__file__).resolve().parents[1]
CONTROLLER = ROOT / "src/Http/Controller/FinanceController.php"
SERVICE = ROOT / "src/Application/Finance/FinanceApiService.php"

PHP_SCENARIOS = r"""
require $argv[1];
use App\Application\Finance\FinanceApiService;
use DomainException as FinanceDomainException;
$a = str_repeat('a', 64);
$b = str_repeat('b', 64);
$sample = [
    'entry_ref' => 'fixture:finance-entry/one',
    'concept_ref' => 'fixture:finance-concept/demo',
    'amount_ref' => 'fixture:finance-amount/nominal',
    'kind' => 'INGRESO',
];
$created = FinanceApiService::createRecord([], $a, $sample);
$store = $created['store'];
$case = $argv[2];
if ($case === 'scope') {
    echo json_encode([
        'own' => count(FinanceApiService::listRecords($store, $a)),
        'foreign' => count(FinanceApiService::listRecords($store, $b)),
        'foreign_view' => FinanceApiService::viewRecord($store, $b, $sample['entry_ref']),
    ], JSON_THROW_ON_ERROR);
} elseif ($case === 'invalid') {
    $invalid = [
        array_merge($sample, ['entry_ref' => 'persona@example.test']),
        array_merge($sample, ['amount_ref' => '12345.67']),
        array_merge($sample, ['amount_ref' => 99]),
        array_merge($sample, ['phone' => '3001234567']),
        array_merge($sample, ['kind' => 'TRANSFERENCIA']),
    ];
    $rejected = 0;
    foreach ($invalid as $payload) {
        try {
            FinanceApiService::createRecord([], $a, $payload);
        } catch (FinanceDomainException) {
            ++$rejected;
        }
    }
    try {
        FinanceApiService::createRecord($store, $a, $sample);
    } catch (FinanceDomainException) {
        ++$rejected;
    }
    echo json_encode(['rejected' => $rejected], JSON_THROW_ON_ERROR);
} elseif ($case === 'states') {
    $denied = 0;
    foreach (['APROBADO', 'CERRADO', 'PAGADO', ''] as $state) {
        try {
            FinanceApiService::changeState($store, $a, $sample['entry_ref'], $state);
        } catch (FinanceDomainException) {
            ++$denied;
        }
    }
    $changed = FinanceApiService::changeState($store, $a, $sample['entry_ref'], 'EN_REVISION');
    echo json_encode([
        'initial' => $created['record']['state'],
        'changed' => $changed['record']['state'],
        'denied' => $denied,
    ], JSON_THROW_ON_ERROR);
} else {
    throw new RuntimeException('Escenario desconocido');
}
"""


class ModuloTests(unittest.TestCase):
    @classmethod
    def setUpClass(cls):
        if shutil.which("php") is None:
            raise RuntimeError("PHP CLI is required for the Finance HTTP API contract")
        for path in (CONTROLLER, SERVICE):
            result = subprocess.run(
                ["php", "-l", str(path)], capture_output=True, text=True, check=False
            )
            if result.returncode:
                raise AssertionError(result.stdout + result.stderr)

    def scenario(self, name):
        result = subprocess.run(
            ["php", "-n", "-r", PHP_SCENARIOS, str(SERVICE), name],
            capture_output=True, text=True, check=False,
        )
        self.assertEqual(0, result.returncode, result.stderr)
        return json.loads(result.stdout)

    def test_aislamiento_por_empresa(self):
        outcome = self.scenario("scope")
        self.assertEqual(1, outcome["own"])
        self.assertEqual(0, outcome["foreign"])
        self.assertIsNone(outcome["foreign_view"])
        controller = CONTROLLER.read_text(encoding="utf-8")
        self.assertIn("hash('sha256', $tenant->id().'|'.$branch->id())", controller)
        self.assertIn("$this->authorizedBranchScope($branchId, $permission)", controller)

    def test_permisos_y_auditoria(self):
        controller = CONTROLLER.read_text(encoding="utf-8")
        for permission in ("settings.view", "settings.create", "settings.update"):
            self.assertIn("'" + permission + "'", controller)
        self.assertIn("$this->requireCsrf($request)", controller)
        self.assertIn("$this->audit($tenant, $actor", controller)
        self.assertIn("$this->entityManager->flush()", controller)
        self.assertIn("CONDOR_FINANCE_SYNTHETIC_API", controller)
        self.assertIn("CONDOR_PRODUCTION_STAGE", controller)
        self.assertIn("getParameter('kernel.environment')", controller)
        for route in ("methods: ['GET']", "methods: ['POST']", "methods: ['PATCH']"):
            self.assertIn(route, controller)

    def test_sin_datos_reales(self):
        self.assertEqual(6, self.scenario("invalid")["rejected"])
        controller = CONTROLLER.read_text(encoding="utf-8")
        self.assertIn("'synthetic_only' => true", controller)
        self.assertNotIn("->getRepository(", controller)
        self.assertNotIn("->persist(", controller)

    def test_estados_explicitos(self):
        result = self.scenario("states")
        self.assertEqual("PENDIENTE", result["initial"])
        self.assertEqual("EN_REVISION", result["changed"])
        self.assertEqual(4, result["denied"])
        controller = CONTROLLER.read_text(encoding="utf-8")
        self.assertIn("FinanceApiService::changeState(", controller)


if __name__ == "__main__":
    unittest.main()
