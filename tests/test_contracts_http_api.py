#!/usr/bin/env python3
"""M02 contract API: execute actual PHP service with fictitious records only."""
from __future__ import annotations

import json
import subprocess
import unittest
from pathlib import Path

ROOT = Path(__file__).resolve().parents[1]
SERVICE = ROOT / 'src/Application/Contracts/ContractApiService.php'
CONTROLLER = ROOT / 'src/Http/Controller/ContractsController.php'

RUNTIME = r'''
require $argv[1];
use App\Application\Contracts\ContractApiService as Api;
$ops = json_decode($argv[2], true, 32, JSON_THROW_ON_ERROR);
$store = [];
$results = [];
foreach ($ops as $operation) {
    $tenant = $operation['tenant'] ?? 'tenant-a';
    $company = $operation['company'] ?? 'company-a';
    $branch = $operation['branch'] ?? 'branch-a';
    $scope = hash('sha256', implode('|', [$tenant, $company, $branch]));
    try {
        $result = match ($operation['method']) {
            'GET' => Api::listRecords($store, $scope),
            'VIEW' => Api::viewRecord($store, $scope, $operation['ref']),
            'POST' => Api::createRecord($store, $scope, $operation['payload']),
            'PATCH' => Api::changeState($store, $scope, $operation['ref'], $operation['state']),
        };
        if (in_array($operation['method'], ['POST', 'PATCH'], true)) {
            $store = $result['store'];
            $result = $result['record'];
        }
        $results[] = $result;
    } catch (\DomainException $e) {
        $results[] = ['error' => 'denied'];
    }
}
echo json_encode($results, JSON_THROW_ON_ERROR);
'''


def run(operations: list[dict]) -> list:
    process = subprocess.run(
        ['php', '-n', '-d', 'allow_url_fopen=0', '-r', RUNTIME,
         str(SERVICE), json.dumps(operations)],
        cwd=ROOT, capture_output=True, text=True, timeout=20, check=False,
    )
    if process.returncode:
        raise AssertionError(process.stdout + process.stderr)
    return json.loads(process.stdout)


def op(method: str, **kwargs: object) -> dict:
    return {'method': method, **kwargs}


class ModuloTests(unittest.TestCase):
    def test_aislamiento_por_empresa(self) -> None:
        row = {'kind': 'contract', 'ref': 'fixture:contract/sample'}
        output = run([
            op('POST', payload=row),
            op('GET', company='company-b'),
            op('VIEW', company='company-b', ref=row['ref']),
            op('GET', tenant='tenant-b'),
            op('GET', branch='branch-b'),
            op('GET'),
        ])
        self.assertEqual([], output[1])
        self.assertIsNone(output[2])
        self.assertEqual([], output[3])
        self.assertEqual([], output[4])
        self.assertEqual([output[0]], output[5])
        controller = CONTROLLER.read_text(encoding='utf-8')
        self.assertIn('$branch->legalEntity()', controller)
        self.assertIn('$company->tenant()->id()', controller)
        self.assertIn("[$tenant->id(), $company->id(), $branch->id()]", controller)

    def test_permisos_y_auditoria(self) -> None:
        controller = CONTROLLER.read_text(encoding='utf-8')
        self.assertIn('use BranchApiSupport;', controller)
        self.assertIn('$this->authorizedBranchScope($branchId, $permission)', controller)
        for permission in ('inventory.view', 'inventory.create', 'inventory.update'):
            self.assertIn(permission, controller)
        for method in ("methods: ['GET']", "methods: ['POST']", "methods: ['PATCH']"):
            self.assertIn(method, controller)
        for event in ('listed', 'viewed', 'created', 'state_changed'):
            self.assertIn('contracts.synthetic.' + event, controller)
        self.assertIn('$this->requireCsrf($request)', controller)
        self.assertIn("$this->audit($tenant, $actor, $event", controller)
        self.assertIn('$this->entityManager->flush()', controller)
        self.assertIn("$environment !== 'test'", controller)
        self.assertIn("CONDOR_CONTRACTS_SYNTHETIC_API", controller)
        self.assertIn("$session->set(self::SESSION_KEY, $result['store'])", controller)
        self.assertEqual('REQUIERE_REVISION', run([
            op('POST', payload={'kind': 'template', 'ref': 'fixture:template/model'}),
        ])[0]['state'])

    def test_sin_datos_reales(self) -> None:
        forbidden = (
            {'kind': 'contract', 'ref': 'someone@example.com'},
            {'kind': 'contract', 'ref': '3115551212'},
            {'kind': 'contract', 'ref': 'fixture:contract/person3001234567'},
            {'kind': 'contract', 'ref': 'https://example.com'},
            {'kind': 'template', 'ref': 'fixture:contract/another'},
            {'kind': 'contract', 'ref': 'fixture:contract/a', 'employee': 'Real person'},
            {'kind': 'contract', 'ref': 'fixture:contract/a', 'state': 'APROBADO'},
        )
        self.assertEqual([{'error': 'denied'}] * len(forbidden), run([
            op('POST', payload=p) for p in forbidden
        ]))
        code = SERVICE.read_text(encoding='utf-8').lower()
        for violation in ('curl_init(', 'file_get_contents(', 'httpclient', 'document_number'):
            self.assertNotIn(violation, code)

    def test_estados_explicitos(self) -> None:
        ref = 'fixture:contract/draft-a'
        result = run([
            op('POST', payload={'kind': 'contract', 'ref': ref}),
            op('PATCH', ref=ref, state='EN_REVISION'),
            op('PATCH', ref=ref, state='CERRADO'),
            op('PATCH', ref=ref, state='APROBADO'),
            op('VIEW', ref=ref),
            op('GET'),
        ])
        self.assertEqual('REQUIERE_REVISION', result[0]['state'])
        self.assertEqual('EN_REVISION', result[1]['state'])
        self.assertEqual({'error': 'denied'}, result[2])
        self.assertEqual({'error': 'denied'}, result[3])
        self.assertEqual('EN_REVISION', result[4]['state'])
        self.assertEqual([result[4]], result[5])
        self.assertTrue(result[4]['synthetic_only'])


if __name__ == '__main__':
    unittest.main()
