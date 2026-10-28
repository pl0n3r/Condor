#!/usr/bin/env python3
"""M06 synthetic compliance API acceptance: behavior and HTTP RBAC guards."""
from __future__ import annotations

import json
import subprocess
import unittest
from pathlib import Path

ROOT = Path(__file__).resolve().parents[1]
SERVICE = ROOT / "src/Application/Compliance/ComplianceApiService.php"
CONTROLLER = ROOT / "src/Http/Controller/ComplianceController.php"


def execute(ops: list[dict]) -> list[object]:
    script = r'''
require $argv[1];
use App\Application\Compliance\ComplianceApiService as C;
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


def payload(ref: str = 'fixture:evidence/demo') -> dict[str, str]:
    return {'evidence_ref': ref, 'obligation_ref': 'fixture:obligation/required', 'type': 'EVIDENCIA'}


class ModuloTests(unittest.TestCase):
    def test_aislamiento_por_empresa(self) -> None:
        got = execute([
            op('POST', payload=payload()),
            op('GET', 'tenant-b'),
            op('GET', 'tenant-a', 'branch-b'),
            op('VIEW', 'tenant-b', ref='fixture:evidence/demo'),
            op('GET'),
        ])
        self.assertEqual([], got[1])
        self.assertEqual([], got[2])
        self.assertIsNone(got[3])
        self.assertEqual([got[0]], got[4])
        self.assertIn("hash('sha256', $tenantId.'|'.$branchId)", CONTROLLER.read_text())

    def test_permisos_y_auditoria(self) -> None:
        src = CONTROLLER.read_text(encoding='utf-8')
        for permission in ("'settings.view'", "'settings.create'", "'settings.update'"):
            self.assertIn(permission, src)
        for http in ("methods: ['GET']", "methods: ['POST']", "methods: ['PATCH']"):
            self.assertIn(http, src)
        self.assertEqual(4, src.count('$this->authorizedBranchScope('))
        self.assertEqual(2, src.count('$this->requireCsrf($request);'))
        self.assertEqual(4, src.count('$this->recordAudit('))
        self.assertIn('$this->audit($tenant, $user, $action', src)
        self.assertIn('$this->entityManager->flush()', src)
        self.assertIn('kernel.environment', src)
        self.assertIn("getenv('CONDOR_COMPLIANCE_SYNTHETIC_API')", src)
        self.assertIn("getenv('CONDOR_PRODUCTION_STAGE')", src)
        writes = "$request->getSession()->set('condor_compliance_synthetic'"
        self.assertLess(src.index("'compliance.synthetic.created'"), src.index(writes))
        self.assertLess(src.index("'compliance.synthetic.state_changed'"), src.rindex(writes))

    def test_sin_datos_reales(self) -> None:
        for bad in (
            {**payload(), 'evidence_ref': '3115551212'},
            {**payload(), 'evidence_ref': 'someone@example.com'},
            {**payload(), 'evidence_ref': 'https://example.com'},
            {**payload(), 'obligation_ref': '1234567890'},
            {**payload(), 'name': 'Actual person'},
            {**payload(), 'type': 'unknown'},
        ):
            with self.subTest(payload=bad):
                self.assertEqual([{'error': 'denied'}], execute([op('POST', payload=bad)]))
        for forbidden in ('curl_', 'file_get_contents(', 'httpclient', 'document_number', 'pdo('):
            self.assertNotIn(forbidden, SERVICE.read_text(encoding='utf-8').lower())
        self.assertIn("$env !== 'dev'", CONTROLLER.read_text(encoding='utf-8'))

    def test_estados_explicitos(self) -> None:
        ref = 'fixture:evidence/demo'
        got = execute([
            op('POST', payload=payload(ref)),
            op('PATCH', ref=ref, state='EN_REVISION'),
            op('PATCH', ref=ref, state='APROBADA'),
            op('PATCH', ref=ref, state='CERRADO'),
            op('VIEW', ref=ref),
            op('POST', payload=payload(ref)),
        ])
        self.assertEqual(['PENDIENTE', 'EN_REVISION', 'denied', 'denied', 'EN_REVISION', 'denied'],
                         [r.get('state', 'denied') for r in got])
        self.assertNotIn('CERRADO', json.dumps(got))
        self.assertNotIn('APROBADA', json.dumps(got))


if __name__ == '__main__':
    unittest.main()
