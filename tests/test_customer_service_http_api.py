#!/usr/bin/env python3
"""M16 synthetic customer service API: PHP behavior and HTTP authorization contract."""
from __future__ import annotations

import json
import subprocess
import unittest
from pathlib import Path

ROOT = Path(__file__).resolve().parents[1]
SERVICE = ROOT / 'src/Application/CustomerService/CustomerCaseApiService.php'
CONTROLLER = ROOT / 'src/Http/Controller/CustomerServiceController.php'


def execute(ops: list[dict]) -> list[object]:
    script = r'''
require $argv[1];
use App\Application\CustomerService\CustomerCaseApiService as C;
$ops = json_decode($argv[2], true, 32, JSON_THROW_ON_ERROR);
$store = [];
$out = [];
foreach ($ops as $op) {
    try {
        $scope = hash('sha256', $op['tenant'].'|'.$op['branch']);
        switch ($op['method']) {
            case 'POST':
                $r = C::createRecord($store, $scope, $op['payload']);
                $store = $r['store']; $out[] = $r['record']; break;
            case 'GET':
                $out[] = C::listRecords($store, $scope); break;
            case 'VIEW':
                $out[] = C::viewRecord($store, $scope, $op['ref']); break;
            case 'PATCH':
                $r = C::changeState($store, $scope, $op['ref'], $op['state']);
                $store = $r['store']; $out[] = $r['record']; break;
        }
    } catch (\DomainException $e) {
        $out[] = ['error' => 'denied'];
    }
}
echo json_encode($out, JSON_THROW_ON_ERROR);
'''
    output = subprocess.check_output(
        ['php', '-n', '-d', 'allow_url_fopen=0', '-r', script, str(SERVICE), json.dumps(ops)],
        cwd=ROOT, stderr=subprocess.PIPE, text=True, timeout=20,
    )
    return json.loads(output)


def op(method: str, tenant: str = 'tenant-a', branch: str = 'branch-a', **fields: object) -> dict:
    return {'method': method, 'tenant': tenant, 'branch': branch, **fields}


class ModuloTests(unittest.TestCase):
    def test_aislamiento_por_empresa(self) -> None:
        record = {'case_ref': 'fixture:case/pqrs-a', 'type': 'PQRS'}
        got = execute([
            op('POST', payload=record),
            op('GET', 'tenant-b'),
            op('GET', 'tenant-a', 'branch-b'),
            op('VIEW', 'tenant-b', ref=record['case_ref']),
            op('GET'),
        ])
        self.assertEqual([], got[1])
        self.assertEqual([], got[2])
        self.assertIsNone(got[3])
        self.assertEqual([got[0]], got[4])

    def test_permisos_y_auditoria(self) -> None:
        src = CONTROLLER.read_text(encoding='utf-8')
        for permission in ("'customers.view'", "'customers.create'", "'customers.update'"):
            self.assertIn(permission, src)
        for http in ("methods: ['GET']", "methods: ['POST']", "methods: ['PATCH']"):
            self.assertIn(http, src)
        self.assertEqual(4, src.count('$this->authorizedBranchScope('))
        self.assertEqual(2, src.count('$this->requireCsrf($request);'))
        self.assertEqual(4, src.count('$this->recordAudit('))
        self.assertIn('$this->audit($tenant, $user, $action', src)
        self.assertIn('$this->entityManager->flush()', src)
        self.assertIn('kernel.environment', src)
        self.assertIn("getenv('CONDOR_CUSTOMER_SERVICE_SYNTHETIC_API')", src)
        self.assertIn("getenv('CONDOR_PRODUCTION_STAGE')", src)
        self.assertIn("hash('sha256', $tenantId.'|'.$branchId)", src)
        writes = "$request->getSession()->set('condor_customer_service_synthetic'"
        self.assertLess(src.index("'customer_service.synthetic.created'"), src.index(writes))
        self.assertLess(src.index("'customer_service.synthetic.state_changed'"), src.rindex(writes))

    def test_sin_datos_reales(self) -> None:
        for payload in (
            {'case_ref': '3115551212', 'type': 'PQRS'},
            {'case_ref': 'someone@example.com', 'type': 'PQRS'},
            {'case_ref': 'https://example.com', 'type': 'PQRS'},
            {'case_ref': 'fixture:case/pqrs-a', 'type': 'PQRS', 'name': 'Actual person'},
            {'case_ref': 'fixture:case/pqrs-a', 'type': 'UNKNOWN'},
        ):
            self.assertEqual([{'error': 'denied'}], execute([op('POST', payload=payload)]))
        for forbidden in ('curl_', 'file_get_contents(', 'httpclient', 'document_number'):
            self.assertNotIn(forbidden, SERVICE.read_text(encoding='utf-8').lower())

    def test_estados_explicitos(self) -> None:
        record = {'case_ref': 'fixture:case/pqrs-a', 'type': 'PQRS'}
        out = execute([
            op('POST', payload=record),
            op('PATCH', ref=record['case_ref'], state='EN_REVISION'),
            op('PATCH', ref=record['case_ref'], state='CERRADO'),
            op('PATCH', ref=record['case_ref'], state='APROBADO'),
            op('VIEW', ref=record['case_ref']),
        ])
        self.assertEqual('PENDIENTE', out[0]['state'])
        self.assertEqual('EN_REVISION', out[1]['state'])
        self.assertEqual({'error': 'denied'}, out[2])
        self.assertEqual({'error': 'denied'}, out[3])
        self.assertEqual('EN_REVISION', out[4]['state'])
        self.assertNotIn('CERRADO', json.dumps(out))
        self.assertNotIn('APROBADO', json.dumps(out))


if __name__ == '__main__':
    unittest.main()
