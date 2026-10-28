#!/usr/bin/env python3
"""M10 HTTP assets: actual PHP facade/persistence behavior and HTTP RBAC guards."""
from __future__ import annotations

import json
import subprocess
import unittest
from pathlib import Path

ROOT = Path(__file__).resolve().parents[1]
CONTROLLER = ROOT / 'src/Http/Controller/AssetsController.php'
SERVICE = ROOT / 'src/Application/Assets/AssetApiService.php'
ENTITY = ROOT / 'src/Domain/Assets/Entity/AssetRecord.php'
REPOSITORY = ROOT / 'src/Infrastructure/Persistence/AssetRecordRepository.php'

# Fake DBAL supplies deterministic transactions while executing the actual PHP classes.
FAKE_DBAL = r'''
namespace Doctrine\DBAL {
    class Connection {
        public array $records = [];
        public array $audits = [];
        public function transactional(callable $run): mixed { return $run(); }
        public function insert(string $table, array $row): void {
            if ($table === 'condor_asset_audit') { $this->audits[] = $row; return; }
            if ($table !== 'condor_asset_record') { throw new \DomainException('table'); }
            $key = $row['tenant_ref'].'|'.$row['asset_ref'];
            if (isset($this->records[$key])) { throw new \DomainException('duplicate'); }
            $this->records[$key] = $row;
        }
        public function fetchAllAssociative(string $sql, array $params): array {
            if (!str_contains($sql, 'WHERE tenant_ref = ?')) { throw new \DomainException('scope'); }
            $rows = array_values(array_filter($this->records,
                static fn(array $r): bool => $r['tenant_ref'] === $params[0]));
            usort($rows, static fn(array $a, array $b): int => strcmp($a['asset_ref'], $b['asset_ref']));
            return $rows;
        }
        public function fetchAssociative(string $sql, array $params): array|false {
            if (!str_contains($sql, 'WHERE tenant_ref = ? AND asset_ref = ?')) {
                throw new \DomainException('scope');
            }
            return $this->records[$params[0].'|'.$params[1]] ?? false;
        }
        public function update(string $table, array $fields, array $keys): int {
            if ($table !== 'condor_asset_record' || !isset($keys['tenant_ref'], $keys['asset_ref'])) {
                throw new \DomainException('scope');
            }
            $key = $keys['tenant_ref'].'|'.$keys['asset_ref'];
            if (!isset($this->records[$key])) { return 0; }
            $this->records[$key] = array_replace($this->records[$key], $fields);
            return 1;
        }
    }
}
namespace App\Domain\Organization\Entity {
    class Tenant {
        public function __construct(private string $id) {}
        public function id(): string { return $this->id; }
    }
    class LegalEntity {
        public function __construct(private string $id, private Tenant $tenant) {}
        public function id(): string { return $this->id; }
        public function tenant(): Tenant { return $this->tenant; }
    }
    class Branch {
        public function __construct(private Tenant $tenant, private ?LegalEntity $company) {}
        public function tenant(): Tenant { return $this->tenant; }
        public function legalEntity(): ?LegalEntity { return $this->company; }
    }
}
namespace {
require $argv[1];
require $argv[2];
require $argv[3];
use App\Application\Assets\AssetApiService;
use App\Domain\Organization\Entity\Tenant;
use App\Domain\Organization\Entity\LegalEntity;
use App\Domain\Organization\Entity\Branch;
use App\Infrastructure\Persistence\AssetRecordRepository;
$db = new \Doctrine\DBAL\Connection();
$service = new AssetApiService(new AssetRecordRepository($db));
$out = [];
foreach (json_decode($argv[4], true, 32, JSON_THROW_ON_ERROR) as $op) {
    $tenant = new Tenant($op['tenant'] ?? 'tenant-a');
    $owner = new Tenant($op['foreign_owner'] ?? ($op['tenant'] ?? 'tenant-a'));
    $company = isset($op['missing_company']) ? null : new LegalEntity($op['company'] ?? 'company-a', $owner);
    $branch = new Branch($tenant, $company);
    try {
        $out[] = match ($op['method']) {
            'GET' => $service->listRecords($tenant, $branch),
            'VIEW' => $service->viewRecord($tenant, $branch, $op['slug'] ?? 'demo'),
            'POST' => $service->createRecord($tenant, $branch, $op['payload']),
            'PATCH' => $service->changeState($tenant, $branch, $op['slug'] ?? 'demo', $op['payload']),
        };
    } catch (\DomainException $e) {
        $out[] = ['error' => 'denied'];
    }
}
$out[] = ['audits' => array_column($db->audits, 'event')];
echo json_encode($out, JSON_THROW_ON_ERROR);
}
'''


def execute(ops: list[dict]) -> list:
    run = subprocess.run(
        ['php', '-n', '-d', 'allow_url_fopen=0', '-r', FAKE_DBAL,
         str(ENTITY), str(REPOSITORY), str(SERVICE), json.dumps(ops)],
        cwd=ROOT, text=True, capture_output=True, timeout=25, check=False,
    )
    if run.returncode:
        raise AssertionError(run.stdout + run.stderr)
    return json.loads(run.stdout)


def op(method: str, tenant: str = 'tenant-a', company: str = 'company-a', **kwargs) -> dict:
    return {'method': method, 'tenant': tenant, 'company': company, **kwargs}


def create(ref: str = 'fixture:asset/demo', **kwargs) -> dict:
    return {'asset_ref': ref, **kwargs}


class ModuloTests(unittest.TestCase):
    def test_aislamiento_por_empresa(self) -> None:
        rows = execute([
            op('POST', payload=create()), op('POST', company='company-b', payload=create()),
            op('POST', tenant='tenant-b', payload=create()),
            op('GET'), op('GET', company='company-b'), op('GET', tenant='tenant-b'),
            op('VIEW', company='company-c'), op('VIEW'),
            op('GET', foreign_owner='tenant-c'),
        ])
        self.assertEqual(3, len({rows[i]['tenant_ref'] for i in range(3)}))
        for i, origin in ((3, 0), (4, 1), (5, 2)):
            self.assertEqual([rows[origin]], rows[i])
        self.assertIsNone(rows[6])
        self.assertEqual(rows[0], rows[7])
        self.assertEqual({'error': 'denied'}, rows[8])
        self.assertNotIn('company-a', json.dumps(rows))

    def test_permisos_y_auditoria(self) -> None:
        source = CONTROLLER.read_text(encoding='utf-8')
        support = (ROOT / 'src/Http/Controller/BranchApiSupport.php').read_text(encoding='utf-8')
        for permission in ("'inventory.view'", "'inventory.create'", "'inventory.update'"):
            self.assertIn(permission, source)
        for verb in ("methods: ['GET']", "methods: ['POST']", "methods: ['PATCH']"):
            self.assertIn(verb, source)
        self.assertEqual(4, source.count('$this->access($branchId,'))
        self.assertIn('$this->authorizedBranchScope($branchId, $permission)', source)
        self.assertEqual(2, source.count('$this->requireCsrf($request);'))
        self.assertIn('$this->authorization->require(', support)
        self.assertIn("'tenant' => $tenant", support)
        self.assertIn('use BranchApiSupport;', source)
        rows = execute([
            op('POST', payload=create()), op('GET'), op('VIEW'),
            op('PATCH', payload={'state': 'EN_REVISION'}),
        ])
        self.assertEqual(['synthetic.created', 'synthetic.listed', 'synthetic.viewed',
                          'synthetic.state_changed'], rows[-1]['audits'])
        self.assertEqual('EN_REVISION', rows[3]['state'])
        self.assertIn('transactional(', REPOSITORY.read_text(encoding='utf-8'))
        self.assertIn('FOR UPDATE', REPOSITORY.read_text(encoding='utf-8'))

    def test_sin_datos_reales(self) -> None:
        for bad in (
            create('someone@example.com'), create('3105551212'),
            create('https://fake.invalid/asset'), create('fixture:asset/demo', name='Real'),
            create('fixture:asset/demo', warranty_until='2026-02-30'),
            create('fixture:asset/demo', warranty_until='3005551212'),
            create('fixture:asset/demo', warranty_until=123), {'asset_ref': None},
        ):
            with self.subTest(bad=bad):
                self.assertEqual({'error': 'denied'}, execute([op('POST', payload=bad)])[0])
        source = CONTROLLER.read_text(encoding='utf-8')
        self.assertIn("$environment !== 'dev'", source)
        self.assertIn("getenv('CONDOR_ASSETS_SYNTHETIC_API') !== '1'", source)
        self.assertIn("getenv('CONDOR_PRODUCTION_STAGE') !== 'construccion'", source)
        self.assertNotIn("$payload['tenant", source)
        self.assertNotIn("$payload['company", source)

    def test_estados_explicitos(self) -> None:
        rows = execute([
            op('POST', payload=create()),
            op('PATCH', payload={'state': 'EN_REVISION'}),
            op('PATCH', payload={'state': 'CERRADO'}),
            op('PATCH', payload={'state': 'APROBADO'}),
            op('PATCH', payload={'state': 'BAJA_REQUIERE_REVISION'}),
            op('PATCH', payload={'state': 'BAJA_REQUIERE_REVISION', 'evidence_ref': 'fixture:evidence/acta'}),
            op('VIEW'),
            op('POST', payload=create()),
        ])
        self.assertEqual('PENDIENTE', rows[0]['state'])
        self.assertEqual('EN_REVISION', rows[1]['state'])
        for i in (2, 3, 4, 7):
            self.assertEqual({'error': 'denied'}, rows[i])
        self.assertEqual('BAJA_REQUIERE_REVISION', rows[5]['state'])
        self.assertEqual(rows[5], rows[6])
        self.assertEqual(4, len(rows[-1]['audits']))


if __name__ == '__main__':
    unittest.main()
