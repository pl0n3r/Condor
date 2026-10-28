#!/usr/bin/env python3
"""M01 synthetic HR HTTP contract: real PHP facade + audited fake persistence."""
from __future__ import annotations

import json
import subprocess
import unittest
from pathlib import Path

ROOT = Path(__file__).resolve().parents[1]
SERVICE = ROOT / 'src/Application/HumanResources/EmployeeApiService.php'
CONTROLLER = ROOT / 'src/Http/Controller/HumanResourcesController.php'

FAKE_RUNTIME = r'''
namespace App\Domain\Identity\Entity {
    final class User {
        public function __construct(private string $id, private bool $active = true) {}
        public function id(): string { return $this->id; }
        public function isActive(): bool { return $this->active; }
    }
}
namespace App\Domain\Organization\Entity {
    final class Tenant {
        public function __construct(private string $id) {}
        public function id(): string { return $this->id; }
    }
    final class LegalEntity {
        public function __construct(private string $id, private Tenant $tenant) {}
        public function id(): string { return $this->id; }
        public function tenant(): Tenant { return $this->tenant; }
    }
    final class Branch {
        public function __construct(private Tenant $tenant, private ?LegalEntity $company) {}
        public function tenant(): Tenant { return $this->tenant; }
        public function legalEntity(): ?LegalEntity { return $this->company; }
    }
}
namespace {
require $argv[1];
use App\Application\HumanResources\EmployeeApiService;
use App\Application\HumanResources\EmployeeApiPort;
use App\Domain\Identity\Entity\User;
use App\Domain\Organization\Entity\Tenant;
use App\Domain\Organization\Entity\LegalEntity;
use App\Domain\Organization\Entity\Branch;
final class MemoryStore implements EmployeeApiPort {
    public array $rows = [];
    public array $audits = [];
    public function list(string $scope, string $actor): array {
        return array_values(array_filter($this->rows,
            static fn(array $r): bool => $r['tenant_ref'] === $scope));
    }
    public function view(string $scope, string $ref, string $actor): ?array {
        return $this->rows[$scope.'|'.$ref] ?? null;
    }
    public function createWithAudit(array $row, string $actor): array {
        $key = $row['tenant_ref'].'|'.$row['employee_ref'];
        if (isset($this->rows[$key])) { throw new \DomainException('duplicate'); }
        $this->rows[$key] = $row;
        $this->audits[] = 'created';
        return $row;
    }
    public function transitionWithAudit(
        string $scope, string $ref, string $state, int $expected, string $actor
    ): array {
        $key = $scope.'|'.$ref;
        if (!isset($this->rows[$key]) || $this->rows[$key]['version'] !== $expected) {
            throw new \DomainException('missing or concurrency conflict');
        }
        $this->rows[$key]['state'] = $state;
        $this->rows[$key]['version']++;
        $this->audits[] = 'state_changed';
        return $this->rows[$key];
    }
    public function auditRead(string $scope, string $ref, string $actor, string $action): void {
        $this->audits[] = $action;
    }
}
$store = new MemoryStore();
$api = new EmployeeApiService($store);
$results = [];
foreach (json_decode($argv[2], true, 32, JSON_THROW_ON_ERROR) as $op) {
    $tenant = new Tenant($op['tenant'] ?? 'tenant-a');
    $owner = new Tenant($op['foreign_owner'] ?? ($op['tenant'] ?? 'tenant-a'));
    $company = isset($op['missing_company']) ? null : new LegalEntity($op['company'] ?? 'company-a', $owner);
    $branch = new Branch($tenant, $company);
    $actor = new User('actor-a', !isset($op['inactive']));
    $authorized = !isset($op['forbidden']);
    try {
        $results[] = match ($op['method']) {
            'GET' => $api->listRecords($tenant, $branch, $actor, $authorized),
            'VIEW' => $api->viewRecord($tenant, $branch, $actor, $authorized, $op['slug'] ?? 'a'),
            'POST' => $api->createRecord($tenant, $branch, $actor, $authorized, $op['payload']),
            'PATCH' => $api->changeState($tenant, $branch, $actor, $authorized,
                $op['slug'] ?? 'a', $op['payload']),
        };
    } catch (\DomainException $exception) {
        $results[] = ['error' => 'denied'];
    }
}
$results[] = ['audit' => $store->audits];
echo json_encode($results, JSON_THROW_ON_ERROR);
}
'''


def execute(ops: list[dict]) -> list:
    process = subprocess.run(
        ['php', '-n', '-d', 'allow_url_fopen=0', '-r', FAKE_RUNTIME,
         str(SERVICE), json.dumps(ops)],
        cwd=ROOT, capture_output=True, text=True, timeout=25, check=False,
    )
    if process.returncode:
        raise AssertionError(process.stdout + process.stderr)
    return json.loads(process.stdout)


def op(method: str, tenant='tenant-a', company='company-a', **kwargs) -> dict:
    return {'method': method, 'tenant': tenant, 'company': company, **kwargs}


def sample(employee_ref='fixture:employee/a', position_ref='fixture:position/a', **kwargs) -> dict:
    return {'employee_ref': employee_ref, 'position_ref': position_ref, **kwargs}


class ModuloTests(unittest.TestCase):
    def test_aislamiento_por_empresa(self) -> None:
        rows = execute([
            op('POST', payload=sample()),
            op('POST', company='company-b', payload=sample()),
            op('POST', tenant='tenant-b', payload=sample()),
            op('GET'), op('GET', company='company-b'), op('GET', tenant='tenant-b'),
            op('VIEW', company='company-c'), op('VIEW'),
            op('GET', foreign_owner='tenant-b'),
        ])
        self.assertEqual(3, len({rows[i]['tenant_ref'] for i in range(3)}))
        for idx, created in ((3, 0), (4, 1), (5, 2)):
            self.assertEqual([rows[created]], rows[idx])
        self.assertIsNone(rows[6])
        self.assertEqual(rows[0], rows[7])
        self.assertEqual({'error': 'denied'}, rows[8])
        self.assertNotIn('company-b', json.dumps(rows))

    def test_permisos_y_auditoria(self) -> None:
        source = CONTROLLER.read_text(encoding='utf-8')
        support = (ROOT / 'src/Http/Controller/BranchApiSupport.php').read_text(encoding='utf-8')
        for perm in ("'users.view'", "'users.create'", "'users.update'"):
            self.assertIn(perm, source)
        for verb in ("methods: ['GET']", "methods: ['POST']", "methods: ['PATCH']"):
            self.assertIn(verb, source)
        self.assertIn('Membership::ROLE_OWNER', source)
        self.assertIn('$this->authorizedBranchScope($branchId, $permission)', source)
        self.assertIn('$this->authorization->require(', support)
        self.assertEqual(2, source.count('$this->requireCsrf($request);'))
        self.assertIn('Cache-Control', source)
        rows = execute([
            op('POST', payload=sample()), op('GET'), op('VIEW'),
            op('PATCH', payload={'state': 'EN_REVISION', 'expected_version': 1}),
            op('GET', forbidden=True), op('POST', payload=sample('fixture:employee/b'), inactive=True),
        ])
        self.assertEqual('EN_REVISION', rows[3]['state'])
        self.assertEqual({'error': 'denied'}, rows[4])
        self.assertEqual({'error': 'denied'}, rows[5])
        self.assertEqual(['created', 'hr.synthetic.listed', 'hr.synthetic.viewed', 'state_changed'],
                         rows[-1]['audit'])
        self.assertIn('atomically', SERVICE.read_text(encoding='utf-8'))

    def test_sin_datos_reales(self) -> None:
        for bad in (sample('person@example.com'), sample('3013334444'),
                    sample('1000123456'), sample(position_ref='https://example.com'),
                    sample(name='Real person'), {'employee_ref': None, 'position_ref': 'fixture:position/a'},
                    sample(position_ref=1234), sample(employee_ref='fixture:employee/A')):
            with self.subTest(bad=bad):
                self.assertEqual({'error': 'denied'}, execute([op('POST', payload=bad)])[0])
        source = CONTROLLER.read_text(encoding='utf-8')
        self.assertIn("getenv('CONDOR_HR_SYNTHETIC_API') !== '1'", source)
        self.assertIn("getenv('CONDOR_PRODUCTION_STAGE') !== 'construccion'", source)
        self.assertNotIn("$payload['tenant", source)
        self.assertNotIn("$payload['company", source)
        self.assertNotIn("'name' =>", SERVICE.read_text(encoding='utf-8'))

    def test_estados_explicitos(self) -> None:
        rows = execute([
            op('POST', payload=sample()),
            op('PATCH', payload={'state': 'EN_REVISION', 'expected_version': 1}),
            op('PATCH', payload={'state': 'CERRADO', 'expected_version': 2}),
            op('PATCH', payload={'state': 'APROBADO', 'expected_version': 2}),
            op('PATCH', payload={'state': 'BORRADOR', 'expected_version': 1}),
            op('PATCH', payload={'state': 'BORRADOR', 'expected_version': 2}),
            op('VIEW'),
            op('POST', payload=sample()),
        ])
        self.assertEqual('BORRADOR', rows[0]['state'])
        self.assertEqual(1, rows[0]['version'])
        self.assertEqual('EN_REVISION', rows[1]['state'])
        self.assertEqual(2, rows[1]['version'])
        for idx in (2, 3, 4, 7):
            self.assertEqual({'error': 'denied'}, rows[idx])
        self.assertEqual('BORRADOR', rows[5]['state'])
        self.assertEqual(3, rows[5]['version'])
        self.assertEqual(rows[5], rows[6])
        self.assertEqual(4, len(rows[-1]['audit']))


if __name__ == '__main__':
    unittest.main()
