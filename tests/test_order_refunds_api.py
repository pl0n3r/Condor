#!/usr/bin/env python3
"""Condor #860 AC-01: execute the real PHP refund service against isolated fake repositories."""
from __future__ import annotations

import json
import shutil
import subprocess
import unittest
from pathlib import Path

ROOT = Path(__file__).resolve().parents[1]
SERVICE = ROOT / 'src/Application/Orders/OrderRefundService.php'
CONTROLLER = ROOT / 'src/Http/Controller/OrderRefundController.php'

SCENARIO = r'''
namespace Doctrine\DBAL { class LockMode { public const PESSIMISTIC_WRITE = 4; } }
namespace Doctrine\ORM { interface EntityManagerInterface {
    public function wrapInTransaction(callable $fn): mixed;
    public function refresh(object $o,int $mode): void;
    public function getRepository(string $type): object;
    public function persist(object $o): void;
} }
namespace App\Domain\Orders\Entity {
    class Tenant { public function __construct(private string $id) {} public function id(): string {return $this->id;} }
    class Source {}
    class Order {
        public const STATUS_OPEN = 'open'; public const STATUS_CANCELLED = 'cancelled';
        public const FULFILLMENT_CONSUMED = 'consumed'; public const FULFILLMENT_RELEASED = 'released';
        public function __construct(private string $id,private Tenant $tenant,private string $state='open',private string $fulfillment='consumed') {}
        public function id(): string{return $this->id;}
        public function tenant(): Tenant{return $this->tenant;}
        public function currency(): string{return 'COP';}
        public function orderStatus(): string{return $this->state;}
        public function fulfillmentStatus(): string{return $this->fulfillment;}
        public function inventorySource(): Source{return new Source();}
    }
    class OrderLine {
        public function __construct(private string $id,private Order $order,private int $quantity) {}
        public function id(): string{return $this->id;}
        public function order(): Order{return $this->order;}
        public function quantity(): int{return $this->quantity;}
        public function variant(): object{return new \stdClass();}
    }
    class OrderEvent {
        public function __construct(private Order $o,private string $t,private ?string $actor=null,private array $data=[]) {}
        public function order(): Order{return $this->o;}
        public function type(): string{return $this->t;}
        public function context(): array{return $this->data;}
        public function actor(): ?string{return $this->actor;}
    }
}
namespace App\Application\Inventory {
    class InventoryService {
        public array $movements=[];
        public function adjust(object $tenant,object $source,object $variant,int $qty,?string $actor,string $key,array $ctx=[]): object {
            $this->movements[]=['qty'=>$qty,'actor'=>$actor,'key'=>$key,'ctx'=>$ctx];return new \stdClass();
        }
    }
}
namespace Test {
    use App\Domain\Orders\Entity\{Tenant,Order,OrderLine,OrderEvent};
    use Doctrine\ORM\EntityManagerInterface;
    class Repo {
        public function __construct(private Em $em,private string $class){}
        public function findBy(array $criteria): array {
            if($this->class!==OrderEvent::class)throw new \RuntimeException('wrong repository');
            return array_values(array_filter($this->em->events,static fn($x)=>$x->order()->id()===$criteria['order']->id()));
        }
        public function findOneBy(array $criteria): ?object {
            if($this->class!==OrderLine::class)throw new \RuntimeException('wrong line repository');
            foreach($this->em->lines as $line)if($line->id()===$criteria['id'] && $line->order()->id()===$criteria['order']->id())return $line;
            return null;
        }
    }
    class Em implements EntityManagerInterface {
        public array $events=[];public array $lines=[];public int $refreshes=0;
        public function wrapInTransaction(callable $cb):mixed{return $cb($this);}
        public function refresh(object $o,int $mode):void {if($mode!==4)throw new \RuntimeException('no lock');$this->refreshes++;}
        public function getRepository(string $type):object{return new Repo($this,$type);}
        public function persist(object $o):void {$this->events[]=$o;}
    }
    require $argv[1];
    $check=static function(bool $yes,string $where):void{if(!$yes)throw new \RuntimeException($where);};
    $deny=static function(callable $fn,string $name):void{try{$fn();}catch(\DomainException){return;}throw new \RuntimeException($name);};
    $em=new Em();$inventory=new \App\Application\Inventory\InventoryService();
    $svc=new \App\Application\Orders\OrderRefundService($em,$inventory);
    $order=new Order('o1',new Tenant('tenant-a'));$em->lines[] = new OrderLine('line1',$order,2);
    $em->persist(new OrderEvent($order,'manual_payment_recorded','actor',[
        'idempotency_key'=>'pay1','amount_minor'=>1000,'currency'=>'COP','method'=>'cash']));
    $call=static fn(int $amount,string $key,?string $line=null,?int $qty=null)=>$svc->refund($order,'tenant-a','actor','pay1',$amount,'customer_return',$key,$line,$qty);
    $deny(fn()=>$svc->refund($order,'tenant-b','actor','pay1',100,'customer_return','cross'), 'cross tenant');
    $deny(fn()=>$call(1001,'over'),'overrefund');
    $deny(fn()=>$call(0,'zero'),'zero');
    $deny(fn()=>$svc->refund($order,'tenant-a','actor','nonexistent',10,'customer_return','bad'),'unregistered payment');
    $deny(fn()=>$svc->refund($order,'tenant-a','actor','pay1',50,'personal freeform notes','reason'),'reason not enumerated');
    $first=$call(300,'refund1');$check($first['refundable_minor']===700 && $first['refunded_minor']===300,'partial refund');
    $replay=$call(300,'refund1');$check($replay['idempotent'] && count($em->events)===2,'idempotent refund');
    $deny(fn()=>$call(350,'refund1'),'key conflict');
    $deny(fn()=>$call(701,'refund2'),'over aggregate');
    $second=$call(200,'refund2','line1',1);
    $check($second['restocked'] && count($inventory->movements)===1,'audited restock');
    $check($inventory->movements[0]['ctx']['order_id']==='o1' && $inventory->movements[0]['qty']===1,'stock provenance');
    $third=$call(300,'refund3','line1',1);$check($third['refundable_minor']===200,'further partial refund');
    $deny(fn()=>$call(100,'refund4','line1',1),'excessive restock');
    $last=$call(200,'refund5');$check($last['refundable_minor']===0,'settled refunds');
    $deny(fn()=>$call(1,'refund6'),'over limit');
    $check(count($inventory->movements)===2 && $em->refreshes>0,'transaction-lock and movements');
    $check($em->events[2]->actor()==='actor' && $em->events[2]->context()['reason']==='customer_return','actor/reason trail');
    $notConsumed=new Order('o2',new Tenant('tenant-a'),'open','reserved');
    $em->persist(new OrderEvent($notConsumed,'manual_payment_recorded','actor',['idempotency_key'=>'p2','amount_minor'=>100,'currency'=>'COP']));
    $em->lines[]=new OrderLine('line2',$notConsumed,1);
    $deny(fn()=>$svc->refund($notConsumed,'tenant-a','actor','p2',20,'customer_return','k2','line2',1),'not-consumed stock');
    // A paid order may be cancelled before fulfillment. Its payment must
    // remain refundable, but already-released stock must not be added again.
    $cancelled = new Order('cancelled-order',new Tenant('tenant-a'),'cancelled','released');
    $em->persist(new OrderEvent($cancelled,'manual_payment_recorded','actor',[
        'idempotency_key'=>'cancelpay','amount_minor'=>100,'currency'=>'COP',
    ]));
    $ref = $svc->refund($cancelled,'tenant-a','actor','cancelpay',100,'order_cancelled','cancelrefund');
    $check($ref['refundable_minor']===0 && $ref['restocked']===false, 'cancelled order refund');
    $deny(fn()=>$svc->refund($cancelled,'tenant-a','actor','cancelpay',100,'order_cancelled','otherrefund'), 'refund cancelled twice');
    echo json_encode(['ok'=>true,'refunds'=>5,'restocks'=>count($inventory->movements)]);
}
'''


class ReembolsosApiTests(unittest.TestCase):
    def test_acceptance(self) -> None:
        php = shutil.which('php')
        self.assertIsNotNone(php, 'PHP CLI required')
        for path in (SERVICE, CONTROLLER):
            p = subprocess.run([php, '-l', str(path)], text=True, capture_output=True, timeout=30)
            self.assertEqual(0, p.returncode, p.stdout + p.stderr)
        src = CONTROLLER.read_text(encoding='utf-8')
        for invariant in ('orders.update', 'requireCsrf($request)', 'authorizedBranchScope(', 'scopedOrder(',
                          "'tenant' => $tenant", "'legalEntity' => $legalEntity", 'inventorySource()->branch()',
                          'restock_quantity', "methods: ['POST']"):
            self.assertIn(invariant, src)
        result = subprocess.run([php, '-r', SCENARIO, str(SERVICE)], capture_output=True, text=True, timeout=30)
        self.assertEqual(0, result.returncode, result.stdout + result.stderr)
        self.assertEqual({'ok': True, 'refunds': 5, 'restocks': 2}, json.loads(result.stdout))


if __name__ == '__main__':
    unittest.main()
