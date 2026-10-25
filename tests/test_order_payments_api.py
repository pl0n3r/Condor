#!/usr/bin/env python3
"""Condor #859 AC-01: real PHP ledger with fake Doctrine and contract checks."""
from __future__ import annotations

import json
import shutil
import subprocess
import unittest
from pathlib import Path

ROOT = Path(__file__).resolve().parents[1]
SERVICE = ROOT / "src/Application/Orders/OrderPaymentService.php"
CONTROLLER = ROOT / "src/Http/Controller/OrderPaymentController.php"

SCENARIO = r'''
namespace Doctrine\DBAL { class LockMode { public const PESSIMISTIC_WRITE = 4; } }
namespace Doctrine\ORM {
    interface EntityManagerInterface {
        public function wrapInTransaction(callable $fn): mixed;
        public function refresh(object $obj, int $mode): void;
        public function getRepository(string $name): object;
        public function persist(object $obj): void;
    }
}
namespace App\Domain\Orders\Entity {
    class Tenant {
        public function __construct(private string $id) {}
        public function id(): string { return $this->id; }
    }
    class Order {
        public const STATUS_OPEN = 'open';
        public function __construct(
            private string $id, private Tenant $tenant,
            private int $total, private string $status = 'open'
        ) {}
        public function id(): string { return $this->id; }
        public function tenant(): Tenant { return $this->tenant; }
        public function totalAmountMinor(): int { return $this->total; }
        public function currency(): string { return 'COP'; }
        public function orderStatus(): string { return $this->status; }
    }
    class OrderEvent {
        public function __construct(
            private Order $order, private string $type,
            private ?string $actor = null, private array $context = []
        ) {}
        public function order(): Order { return $this->order; }
        public function type(): string { return $this->type; }
        public function context(): array { return $this->context; }
    }
}
namespace Test {
    use App\Domain\Orders\Entity\{Order, OrderEvent, Tenant};
    use Doctrine\ORM\EntityManagerInterface;
    class Repo {
        public function __construct(private object $em) {}
        public function findBy(array $criteria): array {
            return array_values(array_filter($this->em->events,
                static fn ($e) => $e->order()->id() === $criteria['order']->id()
                    && $e->order()->tenant()->id() === $criteria['tenant']->id()
                    && $e->type() === $criteria['type']));
        }
    }
    class Em implements EntityManagerInterface {
        public array $events = [];
        public int $writes = 0;
        public function wrapInTransaction(callable $fn): mixed { return $fn($this); }
        public function refresh(object $obj, int $mode): void {
            if ($mode !== \Doctrine\DBAL\LockMode::PESSIMISTIC_WRITE) throw new \RuntimeException('no lock');
        }
        public function getRepository(string $name): object { return new Repo($this); }
        public function persist(object $obj): void { $this->events[] = $obj; $this->writes++; }
    }
    require $argv[1];
    $assert = static function (bool $ok, string $msg): void {
        if (!$ok) throw new \RuntimeException($msg);
    };
    $deny = static function (callable $fn, string $name): void {
        try { $fn(); } catch (\DomainException) { return; }
        throw new \RuntimeException($name);
    };
    $em = new Em();
    $a = new Order('a', new Tenant('tenant-a'), 10000);
    $b = new Order('b', new Tenant('tenant-b'), 10000);
    $denyService = new \App\Application\Orders\OrderPaymentService($em);
    $deny(fn () => $denyService->record($a, 'tenant-b', 'actor', 10000, 'cash', 'k1'), 'cross tenant');
    $deny(fn () => $denyService->record($a, 'tenant-a', 'actor', 2000, 'cash', 'k1'), 'partial default');
    $assert($em->writes === 0, 'write on failed authorization');

    $service = new \App\Application\Orders\OrderPaymentService($em, [
        'tenant-a' => ['allow_partial' => true, 'methods' => ['cash', 'transfer']],
        'tenant-b' => ['allow_partial' => false, 'methods' => ['cash']],
    ]);
    $deny(fn () => $service->record($a, 'tenant-a', 'actor', 1, 'credit_card', 'xx'), 'unconfigured method');
    $first = $service->record($a, 'tenant-a', 'actor', 2000, 'cash', 'k1');
    $assert($first['pending_minor'] === 8000 && $first['payment_status'] === 'pending', 'partial balance');
    $again = $service->record($a, 'tenant-a', 'actor', 2000, 'cash', 'k1');
    $assert($again['idempotent'] === true && $em->writes === 1, 'duplicate charge');
    $deny(fn () => $service->record($a, 'tenant-a', 'actor', 3000, 'cash', 'k1'), 'key conflict');
    $deny(fn () => $service->record($a, 'tenant-a', 'actor', 9000, 'cash', 'k2'), 'overpay');
    $second = $service->record($a, 'tenant-a', 'actor', 8000, 'transfer', 'k2');
    $assert($second['pending_minor'] === 0 && $second['payment_status'] === 'paid', 'settlement');
    $assert($service->balance($a, 'tenant-a')['paid_minor'] === 10000, 'server ledger');
    $deny(fn () => $service->record($a, 'tenant-b', 'actor', 1, 'cash', 'zzz'), 'foreign order');
    $deny(fn () => $service->record($a, 'tenant-a', 'actor', 1, 'cash', 'k3'), 'settled order overpayment');
    $deny(fn () => $service->record($b, 'tenant-b', 'actor', 2000, 'cash', 'q1'), 'tenant partial policy isolation');
    $assert($em->writes === 2 && count($em->events) === 2, 'ledger integrity');
    $cancelled = new Order('c', new Tenant('tenant-a'), 5000, 'cancelled');
    $deny(fn () => $service->record($cancelled, 'tenant-a', 'actor', 5000, 'cash', 'q2'), 'cancelled');
    $assert($service->balance($b, 'tenant-b')['pending_minor'] === 10000, 'tenant ledger bleed');
    echo json_encode(['ok'=>true,'payments'=>$em->writes,'balance'=>$second['pending_minor']]);
}
'''

class PagosManualesApiTests(unittest.TestCase):
    def test_acceptance(self) -> None:
        php = shutil.which("php")
        self.assertIsNotNone(php, "PHP CLI required for AC-01")
        for source in (SERVICE, CONTROLLER):
            self.assertTrue(source.is_file(), str(source))
            lint = subprocess.run(
                [php, "-l", str(source)], capture_output=True, text=True,
                check=False, timeout=30,
            )
            self.assertEqual(0, lint.returncode, lint.stdout + lint.stderr)
        controller = CONTROLLER.read_text(encoding="utf-8")
        for invariant in (
            "orders.update", "orders.view", "requireCsrf($request)",
            "authorizedBranchScope(", "scopedOrder(", "'tenant' => $tenant",
            "'legalEntity' => $legalEntity", "inventorySource()->branch()",
            "amount_minor", "idempotency_key", "'POST'", "'GET'",
        ):
            self.assertIn(invariant, controller)
        self.assertNotIn("allow_partial", controller)
        executed = subprocess.run(
            [php, "-r", SCENARIO, str(SERVICE)], capture_output=True,
            text=True, check=False, timeout=30,
        )
        self.assertEqual(0, executed.returncode, executed.stdout + executed.stderr)
        self.assertEqual({"ok": True, "payments": 2, "balance": 0},
                         json.loads(executed.stdout))


if __name__ == "__main__":
    unittest.main()
