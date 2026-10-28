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
namespace App\Infrastructure\Persistence {
    class ManualCommercialDecisionRepository {
        public array $rows = [];
        public function record(
            \App\Domain\Commercial\Entity\ManualCommercialDecision $decision,
            string $tenant,
            string $actor,
            bool $allowed,
        ): array {
            $row = $decision->snapshot();
            if (!$allowed || $row['tenant_id'] !== $tenant || $row['actor_ref'] !== $actor) {
                throw new \DomainException('Forbidden manual terms');
            }
            $key = $tenant.'|'.$row['event_key'];
            $fingerprint = hash('sha256', json_encode($row, JSON_THROW_ON_ERROR));
            $effective = intdiv(120000 * (10000 - $row['discount_bps']), 10000);
            if (isset($this->rows[$key])) {
                if ($this->rows[$key]['fingerprint'] !== $fingerprint) {
                    throw new \DomainException('Conflicting event');
                }
                return ['recorded' => false, 'fingerprint' => $fingerprint,
                    'effective_monthly_amount' => $effective];
            }
            $this->rows[$key] = ['fingerprint' => $fingerprint, ...$row];
            return ['recorded' => true, 'fingerprint' => $fingerprint,
                'effective_monthly_amount' => $effective];
        }
        public function forTenant(string $tenant, string $actor, bool $allowed): array {
            if (!$allowed || strlen($actor) !== 26) {
                throw new \DomainException('Forbidden list');
            }
            return array_values(array_filter($this->rows,
                static fn (array $row): bool => $row['tenant_id'] === $tenant));
        }
    }
}
namespace {
require $argv[1];
require $argv[2];
use Fake\Connection;
use Fake\Repository;
use Fake\EntityManager;
use App\Application\Commercial\PlatformCommercialAdjustmentManager;
use App\Application\Commercial\ManualSubscriptionOperations;
$repo = new Repository();
$manager = new PlatformCommercialAdjustmentManager($repo);
$termRepo = new \App\Infrastructure\Persistence\ManualCommercialDecisionRepository();
$service = new ManualSubscriptionOperations(
    new EntityManager(new Connection(['fixture-alpha', 'fixture-beta']), $repo), $manager,
);
function command(string $event = 'fixture-event-one'): array {
    return ['event_key' => $event, 'reason' => 'fixture-manual-adjustment',
        'target_plan_version_id' => str_repeat('B', 26),
        'add_on_ids' => [str_repeat('C', 26)], 'renews_at' => null];
}
function terms(): array {
    return [
        'approval_expires_at' => '2026-12-01T12:00:00Z',
        'approver_id' => str_repeat('A',26),
        'discount_bps' => 1000,
        'event_key' => 'fixture-approved-one',
        'payment_state' => 'MANUAL_UNVERIFIED',
        'plan_version_id' => str_repeat('P',26),
        'reason' => 'fixture-approved-discount',
        'renews_at' => '2026-11-01T12:00:00Z',
        'starts_at' => '2026-10-01T12:00:00Z',
        'subscription_id' => str_repeat('S',26),
        'vertical_id' => str_repeat('V',26),
    ];
}
function denied(callable $run): bool {
    try { $run(); return false; } catch (\DomainException $e) { return true; }
}
'''


def run_php(code: str) -> dict:
    proc = subprocess.run(
        ["php", "-n", "-d", "allow_url_fopen=0", "-r", PHP_BOOT + code + "\n}" ,
         str(OPERATIONS), str(ROOT / 'src/Domain/Commercial/Entity/ManualCommercialDecision.php')], cwd=ROOT, capture_output=True, text=True, timeout=20,
    )
    if proc.returncode:
        raise AssertionError(proc.stdout + proc.stderr)
    return json.loads(proc.stdout)


class SaasTests(unittest.TestCase):
    def test_aislamiento_por_empresa(self):
        decisions = run_php(r'''
$first=$service->recordTerms('fixture-alpha', str_repeat('A',26), terms(), $termRepo);
$other=$service->recordTerms('fixture-beta', str_repeat('A',26), terms(), $termRepo);
$replay=$service->recordTerms('fixture-alpha', str_repeat('A',26), terms(), $termRepo);
echo json_encode(['a'=>$first['status'], 'b'=>$other['status'],
  'repeat'=>$replay['status'], 'alpha'=>count($termRepo->forTenant('fixture-alpha',str_repeat('A',26),true)),
  'beta'=>count($termRepo->forTenant('fixture-beta',str_repeat('A',26),true))]);
''')
        self.assertEqual((decisions["a"], decisions["b"], decisions["repeat"]), ("recorded", "recorded", "replayed"))
        self.assertEqual((decisions["alpha"], decisions["beta"]), (1, 1))
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
        terms = run_php(r'''
$deniedActor=denied(fn()=> $service->recordTerms('fixture-alpha','bad-actor',terms(),$termRepo));
$wrongApprover=terms(); $wrongApprover['approver_id']=str_repeat('B',26);
$deniedApproval=denied(fn()=> $service->recordTerms('fixture-alpha',str_repeat('A',26),$wrongApprover,$termRepo));
$ok=$service->recordTerms('fixture-alpha',str_repeat('A',26),terms(),$termRepo);
$repeat=$service->recordTerms('fixture-alpha',str_repeat('A',26),terms(),$termRepo);
$changed=terms(); $changed['discount_bps']=2000;
$rejected=denied(fn()=> $service->recordTerms('fixture-alpha',str_repeat('A',26),$changed,$termRepo));
echo json_encode(['deniedActor'=>$deniedActor,'deniedApproval'=>$deniedApproval,
 'recorded'=>$ok['status'],'replay'=>$repeat['status'],'conflict'=>$rejected,
 'amount'=>$ok['effective_monthly_amount'],'verified'=>$ok['payment_verified']]);
''')
        self.assertTrue(terms["deniedActor"] and terms["deniedApproval"] and terms["conflict"])
        self.assertEqual((terms["recorded"], terms["replay"]), ("recorded", "replayed"))
        self.assertEqual(terms["amount"], 108000)
        self.assertFalse(terms["verified"])
        self.assertIn("manual-subscription-terms", CONTROLLER.read_text())
        self.assertIn("requireManagementCsrf", CONTROLLER.read_text())
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
        decisions = run_php(r'''
$bad=terms(); $bad['reason']='real.person@example.com';
$email=denied(fn()=> $service->recordTerms('fixture-alpha',str_repeat('A',26),$bad,$termRepo));
$bad=terms(); $bad['event_key']='3011234567';
$phone=denied(fn()=> $service->recordTerms('fixture-alpha',str_repeat('A',26),$bad,$termRepo));
$bad=terms(); $bad['customer_phone']='3101112233';
$field=denied(fn()=> $service->recordTerms('fixture-alpha',str_repeat('A',26),$bad,$termRepo));
echo json_encode(['email'=>$email,'phone'=>$phone,'extra'=>$field,'writes'=>count($termRepo->rows)]);
''')
        self.assertTrue(all(decisions[k] for k in ("email", "phone", "extra")))
        self.assertEqual(decisions["writes"], 0)
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
        terms = run_php(r'''
$bad=terms(); unset($bad['approval_expires_at']);
$missing=denied(fn()=> $service->recordTerms('fixture-alpha',str_repeat('A',26),$bad,$termRepo));
$bad=terms(); $bad['discount_bps']=6500;
$discount=denied(fn()=> $service->recordTerms('fixture-alpha',str_repeat('A',26),$bad,$termRepo));
$bad=terms(); $bad['payment_state']='PAID';
$payment=denied(fn()=> $service->recordTerms('fixture-alpha',str_repeat('A',26),$bad,$termRepo));
$bad=terms(); $bad['starts_at']='2026-11-02T12:00:00Z';
$dates=denied(fn()=> $service->recordTerms('fixture-alpha',str_repeat('A',26),$bad,$termRepo));
$bad=terms(); $bad['discount_bps']='10';
$type=denied(fn()=> $service->recordTerms('fixture-alpha',str_repeat('A',26),$bad,$termRepo));
echo json_encode(['invalid'=>$missing&&$discount&&$payment&&$dates&&$type,
 'writes'=>count($termRepo->rows)]);
''')
        self.assertTrue(terms["invalid"])
        self.assertEqual(terms["writes"], 0)
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
