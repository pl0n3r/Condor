#!/usr/bin/env python3
"""SaaS #1063: execute the real PHP orchestration with synthetic fixtures.

A DBAL double exercises the idempotent, tenant-scoped decision boundary; it is
not evidence of real MariaDB persistence or production authorization.
"""
from __future__ import annotations

import json
from pathlib import Path
import subprocess
import unittest

ROOT = Path(__file__).resolve().parents[1]
OPERATIONS = ROOT / "src/Application/Commercial/ManualSubscriptionOperations.php"
CONTROLLER = ROOT / "src/Http/Controller/PlatformSubscriptionsController.php"
ACTOR = "A" * 26
PLAN = "B" * 26
ADDON = "C" * 26

PHP_BOOT = r'''
namespace Fake {
    class Connection {
        public function __construct(public array $tenants) {}
        public function transactional(callable $callback): mixed { return $callback(); }
        public function fetchOne(string $sql, array $args): mixed {
            if (!str_contains($sql, 'FOR UPDATE')) { throw new \DomainException('No row lock'); }
            return in_array($args[0], $this->tenants, true) ? $args[0] : false;
        }
    }
    class Repository {
        public array $records = [];
        public function findBy(array $conditions): array {
            return array_values(array_filter($this->records,
                fn ($item) => $item->tenantId() === $conditions['tenantId']));
        }
    }
    class EntityManager implements \Doctrine\ORM\EntityManagerInterface {
        public function __construct(private Connection $connection, private Repository $repo) {}
        public function getConnection(): Connection { return $this->connection; }
        public function getRepository(string $class): Repository { return $this->repo; }
    }
}
namespace Doctrine\ORM {
    interface EntityManagerInterface {
        public function getConnection(): \Fake\Connection;
        public function getRepository(string $class): \Fake\Repository;
    }
}
namespace App\Domain\Commercial\Entity {
    class SubscriptionChangeRecord {
        public function __construct(
            private string $tenant, private string $actor,
            private string $reason, private string $key,
        ) {}
        public function tenantId(): string { return $this->tenant; }
        public function audit(): array {
            return ['requested_by' => $this->actor, 'reason' => $this->reason];
        }
        public function id(): string { return $this->key; }
    }
}
namespace App\Application\Commercial {
    class PlatformCommercialAdjustmentManager {
        public int $calls = 0;
        public function __construct(private \Fake\Repository $repo) {}
        public function apply(
            string $tenant, string $plan, array $addOns,
            ?string $renewal, string $reason, string $actor,
        ): array {
            $this->calls++;
            $id = 'fixture-audit-'.(string) $this->calls;
            $this->repo->records[] = new \App\Domain\Commercial\Entity\SubscriptionChangeRecord(
                $tenant, $actor, $reason, $id,
            );
            return ['id' => $id, 'audit' => ['requested_by' => $actor, 'reason' => $reason]];
        }
    }
}
namespace {
require $argv[1];
use Fake\Connection;
use Fake\Repository;
use Fake\EntityManager;
use App\Application\Commercial\PlatformCommercialAdjustmentManager;
use App\Application\Commercial\ManualSubscriptionOperations;
$repo = new Repository();
$manager = new PlatformCommercialAdjustmentManager($repo);
$service = new ManualSubscriptionOperations(
    new EntityManager(new Connection(['fixture-alpha', 'fixture-beta']), $repo), $manager,
);
function command(string $event = 'fixture-event-one'): array {
    return ['event_key' => $event, 'reason' => 'fixture-manual-adjustment',
        'target_plan_version_id' => str_repeat('B', 26),
        'add_on_ids' => [str_repeat('C', 26)], 'renews_at' => null];
}
function denied(callable $run): bool {
    try { $run(); return false; } catch (\DomainException $e) { return true; }
}
'''


def run_php(code: str) -> dict:
    proc = subprocess.run(
        ["php", "-n", "-d", "allow_url_fopen=0", "-r", PHP_BOOT + code + "\n}" ,
         str(OPERATIONS)], cwd=ROOT, capture_output=True, text=True, timeout=20,
    )
    if proc.returncode:
        raise AssertionError(proc.stdout + proc.stderr)
    return json.loads(proc.stdout)


class SaasTests(unittest.TestCase):
    def test_aislamiento_por_empresa(self):
        outcome = run_php(r'''
$a = $service->adjust('fixture-alpha', str_repeat('A',26), command());
$b = $service->adjust('fixture-beta', str_repeat('A',26), command());
$repeat = $service->adjust('fixture-alpha', str_repeat('A',26), command());
echo json_encode(['a'=>$a['tenant_ref'], 'b'=>$b['tenant_ref'],
    'different_audit' => $a['audit_ref'] !== $b['audit_ref'],
    'repeat' => $repeat['status'], 'writes'=>$manager->calls,
    'records'=>count($repo->records)], JSON_THROW_ON_ERROR);
''')
        self.assertEqual(outcome["a"], "fixture-alpha")
        self.assertEqual(outcome["b"], "fixture-beta")
        self.assertTrue(outcome["different_audit"])
        self.assertEqual(outcome["repeat"], "replayed")
        self.assertEqual(outcome["writes"], 2)
        self.assertEqual(outcome["records"], 2)

    def test_auditoria_y_permisos(self):
        outcome = run_php(r'''
$denied = denied(fn () => $service->adjust('fixture-alpha', 'fake-actor', command()));
$first = $service->adjust('fixture-alpha', str_repeat('A',26), command());
$again = $service->adjust('fixture-alpha', str_repeat('A',26), command());
$conflict = command(); $conflict['reason'] = 'fixture-other-reason';
$rejected = denied(fn () => $service->adjust('fixture-alpha', str_repeat('A',26), $conflict));
$audit = $repo->records[0]->audit();
echo json_encode(['denied'=>$denied, 'first'=>$first['status'],
 'again'=>$again['status'], 'conflict'=>$rejected,
 'actor'=>$audit['requested_by'], 'proof'=>str_contains($audit['reason'], '|fixture-event-one|sha256:'),
 'writes'=>$manager->calls], JSON_THROW_ON_ERROR);
''')
        self.assertTrue(outcome["denied"])
        self.assertEqual(outcome["first"], "recorded")
        self.assertEqual(outcome["again"], "replayed")
        self.assertTrue(outcome["conflict"])
        self.assertEqual(outcome["actor"], ACTOR)
        self.assertTrue(outcome["proof"])
        self.assertEqual(outcome["writes"], 1)
        controller = CONTROLLER.read_text()
        for guard in ("$this->platformOwner()", "requireManagementCsrf(", "$this->tenants->tenant($tenantId)", "ManualSubscriptionOperations"):
            self.assertIn(guard, controller)

    def test_sin_datos_reales(self):
        result = run_php(r'''
$c = command(); $c['reason'] = 'person@example.com';
$rejectEmail = denied(fn () => $service->adjust('fixture-alpha', str_repeat('A',26), $c));
$c = command(); $c['event_key'] = '3001234567';
$rejectPhone = denied(fn () => $service->adjust('fixture-alpha', str_repeat('A',26), $c));
$c = command(); $c['customer_email'] = 'person@example.com';
$rejectField = denied(fn () => $service->adjust('fixture-alpha', str_repeat('A',26), $c));
$rejectTenant = denied(fn () => $service->adjust('real-customer-tenant', str_repeat('A',26), command()));
echo json_encode(['email'=>$rejectEmail,'phone'=>$rejectPhone,
  'field'=>$rejectField,'tenant'=>$rejectTenant, 'writes'=>$manager->calls]);
''')
        self.assertTrue(all(result[k] for k in ("email", "phone", "field", "tenant")))
        self.assertEqual(result["writes"], 0)

    def test_fail_closed(self):
        result = run_php(r'''
$invalid = [];
$c = command(); unset($c['event_key']);
$invalid[] = denied(fn () => $service->adjust('fixture-alpha', str_repeat('A',26), $c));
$c = command(); $c['discount_percent'] = 5;
$invalid[] = denied(fn () => $service->adjust('fixture-alpha', str_repeat('A',26), $c));
$c = command(); $c['add_on_ids'][] = str_repeat('C',26);
$invalid[] = denied(fn () => $service->adjust('fixture-alpha', str_repeat('A',26), $c));
$c = command(); $c['renews_at'] = '2026-02-30T18:01:00Z';
$invalid[] = denied(fn () => $service->adjust('fixture-alpha', str_repeat('A',26), $c));
$c = command(); $c['target_plan_version_id'] = 'NOT_A_PLAN';
$invalid[] = denied(fn () => $service->adjust('fixture-alpha', str_repeat('A',26), $c));
$invalid[] = denied(fn () => $service->adjust('fixture-missing', str_repeat('A',26), command()));
echo json_encode(['invalid'=>$invalid,'writes'=>$manager->calls]);
''')
        self.assertTrue(all(result["invalid"]))
        self.assertEqual(result["writes"], 0)


if __name__ == "__main__":
    unittest.main()
