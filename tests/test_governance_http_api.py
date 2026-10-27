#!/usr/bin/env python3
"""M15 synthetic HTTP contract: real PHP service and secured route audit wiring."""
from __future__ import annotations

import json
import subprocess
import unittest
from pathlib import Path

ROOT = Path(__file__).resolve().parents[1]
SERVICE = ROOT / 'src/Application/Governance/GovernanceApiService.php'
CONTROLLER = ROOT / 'src/Http/Controller/GovernanceController.php'


def execute(ops: list[dict]) -> list[dict]:
    script = r'''
require $argv[1];
use App\Application\Governance\GovernanceApiService as G;
$ops = json_decode($argv[2], true, 32, JSON_THROW_ON_ERROR);
$store = [];
$out = [];
foreach ($ops as $op) {
    try {
        $scope = hash('sha256', $op['tenant'].'|'.$op['branch']);
        switch ($op['method']) {
            case 'POST':
                $result = G::createRecord($store, $scope, $op['payload']);
                $store = $result['store']; $out[] = $result['record'];
                break;
            case 'GET':
                $out[] = G::listRecords($store, $scope);
                break;
            case 'VIEW':
                $out[] = G::viewRecord($store, $scope, $op['ref']);
                break;
            case 'PATCH':
                $result = G::changeState($store, $scope, $op['ref'], $op['state']);
                $store = $result['store']; $out[] = $result['record'];
                break;
        }
    } catch (\DomainException $e) {
        $out[] = ['error' => 'denied'];
    }
}
echo json_encode($out, JSON_THROW_ON_ERROR);
'''
    p = subprocess.run(['php', '-n', '-d', 'allow_url_fopen=0', '-r', script,
                        str(SERVICE), json.dumps(ops)], cwd=ROOT, text=True,
                       capture_output=True, check=True, timeout=20)
    return json.loads(p.stdout)


def op(method: str, tenant: str = 'tenant-a', branch: str = 'branch-a', **kwargs: object) -> dict:
    return {'method': method, 'tenant': tenant, 'branch': branch, **kwargs}


class ModuloTests(unittest.TestCase):
    def test_aislamiento_por_empresa(self) -> None:
        record = {'ref': 'fixture:body/board-a', 'kind': 'body'}
        output = execute([
            op('POST', payload=record),
            op('GET', 'tenant-b'),
            op('GET', 'tenant-a', 'branch-b'),
            op('VIEW', 'tenant-b', ref=record['ref']),
            op('GET'),
        ])
        self.assertEqual([], output[1])
        self.assertEqual([], output[2])
        self.assertIsNone(output[3])
        self.assertEqual([output[0]], output[4])

    def test_permisos_y_auditoria(self) -> None:
        controller = CONTROLLER.read_text(encoding='utf-8')
        for method in ("'inventory.view'", "'inventory.create'", "'inventory.update'"):
            self.assertIn(method, controller)
        for verb in ("methods: ['GET']", "methods: ['POST']", "methods: ['PATCH']"):
            self.assertIn(verb, controller)
        self.assertIn('$this->authorizedBranchScope(', controller)
        self.assertEqual(2, controller.count('$this->requireCsrf($request);'))
        self.assertEqual(4, controller.count('$this->recordAudit('))
        self.assertIn('$this->audit($tenant, $user, $action', controller)
        self.assertIn('$this->entityManager->flush()', controller)
        self.assertIn('kernel.environment', controller)
        self.assertIn("$environment !== 'test'", controller)
        self.assertIn('hash(\'sha256\', $tenantId.\'|\'.$branchId)', controller)

    def test_sin_datos_reales(self) -> None:
        for payload in (
            {'ref': '3115551212', 'kind': 'body'},
            {'ref': 'someone@example.com', 'kind': 'body'},
            {'ref': '1002030405', 'kind': 'body'},
            {'ref': 'https://example.com', 'kind': 'body'},
            {'ref': 'fixture:body/board-a', 'kind': 'body', 'name': 'Real Name'},
            {'ref': 'fixture:body/board-a', 'kind': 'power'},
        ):
            self.assertEqual([{'error': 'denied'}], execute([op('POST', payload=payload)]))
        for forbidden in ('curl_', 'file_get_contents(', 'httpclient', 'email', 'document_number'):
            self.assertNotIn(forbidden, SERVICE.read_text(encoding='utf-8').lower())

    def test_estados_explicitos(self) -> None:
        record = {'ref': 'fixture:body/board-a', 'kind': 'body'}
        out = execute([
            op('POST', payload=record),
            op('PATCH', ref=record['ref'], state='EN_REVISION'),
            op('PATCH', ref=record['ref'], state='CERRADO'),
            op('PATCH', ref=record['ref'], state='APROBADO'),
            op('VIEW', ref=record['ref']),
        ])
        self.assertEqual('REQUIERE_REVISION', out[0]['state'])
        self.assertEqual('EN_REVISION', out[1]['state'])
        self.assertEqual({'error': 'denied'}, out[2])
        self.assertEqual({'error': 'denied'}, out[3])
        self.assertEqual('EN_REVISION', out[4]['state'])
        self.assertNotIn('CERRADO', json.dumps(out))
        self.assertNotIn('APROBADO', json.dumps(out))


if __name__ == '__main__':
    unittest.main()
