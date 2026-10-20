#!/usr/bin/env python3
"""Contrato offline de API de costos, Condor #719.

Ejecuta el controller PHP auténtico con dobles tipados y sin DB/credenciales.
Valida lectura del snapshot persistido e historial UNKNOWN sin recalcular precios.
"""

import subprocess
import tempfile
import unittest
from pathlib import Path

ROOT = Path(__file__).resolve().parents[1]
CONTROLLER = ROOT / "src/Http/Controller/ProductionCostController.php"

FIXTURE = r'''<?php
declare(strict_types=1);
namespace Symfony\Component\Routing\Attribute {
    #[\Attribute(\Attribute::TARGET_METHOD)]
    class Route { public function __construct(...$arguments) {} }
}
namespace Symfony\Component\HttpKernel\Exception {
    class NotFoundHttpException extends \RuntimeException {}
    class UnprocessableEntityHttpException extends \RuntimeException {}
}
namespace Symfony\Component\HttpFoundation {
    class JsonResponse { public function __construct(public array $data) {} }
}
namespace App\Domain\Organization\Entity {
    class Tenant { public function __construct(public string $key) {} }
    class Branch { public function __construct(public string $key) {} }
    class LegalEntity { public function __construct(public string $key) {} }
}
namespace App\Domain\Identity\Entity {
    class User {}
}
namespace App\Application\Identity {
    class CurrentTenantForUser {
        public function __construct(public \App\Domain\Organization\Entity\Tenant $tenant) {}
    }
    class BranchAuthorization {}
}
namespace App\Domain\Inventory\Entity {
    class InventorySource {
        public const TYPE_BRANCH = 'branch';
        public function __construct(private \App\Domain\Organization\Entity\LegalEntity $legal) {}
        public function type(): string { return self::TYPE_BRANCH; }
        public function isActive(): bool { return true; }
        public function legalEntity(): object { return $this->legal; }
        public function id(): string { return 'source-1'; }
    }
}
namespace App\Domain\Production\Entity {
    class ProductionOrder {
        public function __construct(private string $key, private ?\DateTimeImmutable $date, private ?array $snapshot = null) {}
        public function id(): string { return $this->key; }
        public function isCompleted(): bool { return $this->date !== null; }
        public function completedAt(): ?\DateTimeImmutable { return $this->date; }
        public function costSnapshot(): ?array { return $this->snapshot; }
    }
}
namespace App\Domain\Purchasing\Entity {
    class PurchaseOrder {
        public const STATUS_RECEIVED = 'received';
        public function __construct(public \DateTimeImmutable $receivedAt) {}
    }
}
namespace App\Application\Production {
    class ProductionCostService {
        public array $seen = [];
        public function calculate(object $tenant, object $order, iterable $receipts,
                                  ?array $savedSnapshot = null): array {
            $this->seen = $receipts;
            if ($receipts !== [] || $savedSnapshot === null
                || $savedSnapshot['tenant_id'] !== $tenant->key
                || $savedSnapshot['order_id'] !== $order->id()) {
                throw new \RuntimeException('GET recalculated prices or accepted foreign proof.');
            }
            return $savedSnapshot;
        }
    }
}
namespace Doctrine\ORM {
    interface EntityManagerInterface { public function getRepository(string $class): object; }
}
namespace Symfony\Bundle\FrameworkBundle\Controller {
    abstract class AbstractController {
        public function json(array $data): \Symfony\Component\HttpFoundation\JsonResponse {
            return new \Symfony\Component\HttpFoundation\JsonResponse($data);
        }
    }
}
namespace App\Http\Controller {
    trait BranchApiSupport {
        private function authorizedBranchScope(string $id, string $permission): array {
            if ($id !== 'branch-1' || $permission !== 'inventory.view') {
                throw new \Symfony\Component\HttpKernel\Exception\NotFoundHttpException();
            }
            return [null, $this->currentTenantForUser->tenant, $GLOBALS['branch']];
        }
        private function domain(callable $operation): mixed { return $operation(); }
    }
}
namespace Acceptance {
    use App\Domain\Organization\Entity\{Tenant, Branch, LegalEntity};
    use App\Domain\Inventory\Entity\InventorySource;
    use App\Domain\Production\Entity\ProductionOrder;
    use App\Domain\Purchasing\Entity\PurchaseOrder;
    use App\Application\Identity\{CurrentTenantForUser, BranchAuthorization};
    use App\Application\Production\ProductionCostService;
    use App\Http\Controller\ProductionCostController;
    use Doctrine\ORM\EntityManagerInterface;
    use Symfony\Component\HttpKernel\Exception\{NotFoundHttpException, UnprocessableEntityHttpException};
    class Repo {
        public function __construct(private array $rows) {}
        public function findOneBy(array $where): ?object {
            foreach ($this->rows as $row) {
                $valid=true;
                foreach ($where as $key=>$value) {
                    if ($row[$key] !== $value) { $valid=false; break; }
                }
                if ($valid) { return $row['entity']; }
            }
            return null;
        }
    }
    class Query {
        private array $params = [];
        private array $conditions = [];
        public function __construct(private array $rows) {}
        public function andWhere(string $sql): self { $this->conditions[]=$sql; return $this; }
        public function setParameter(string $key, mixed $value): self { $this->params[$key]=$value; return $this; }
        public function getQuery(): self { return $this; }
        public function getResult(): array {
            foreach (['p.tenant = :tenant','p.legalEntity = :legalEntity',
                'p.status = :received','p.receivedAt <= :completion'] as $required) {
                if (!in_array($required,$this->conditions,true)) {
                    throw new \RuntimeException('Missing tenant/date protection: '.$required);
                }
            }
            $result=[];
            foreach ($this->rows as $row) {
                if ($row['tenant']===$this->params['tenant']
                    && $row['legal']===$this->params['legalEntity']
                    && $row['status']===$this->params['received']
                    && $row['entity']->receivedAt <= $this->params['completion']) {
                    $result[]=$row['entity'];
                }
            }
            return $result;
        }
    }
    class PurchaseRepo {
        public function __construct(private array $rows) {}
        public function createQueryBuilder(string $alias): Query {
            throw new \RuntimeException('GET must never query receipt history.');
        }
    }
    class Store implements EntityManagerInterface {
        public int $writes=0;
        public function __construct(private array $repos) {}
        public function getRepository(string $class): object { return $this->repos[$class]; }
        public function flush(): void { $this->writes++; }
        public function persist(object $entity): void { $this->writes++; }
    }
    require '%CONTROLLER%';
    $tenant=new Tenant('tenant-a'); $foreign=new Tenant('tenant-b');
    $branch=new Branch('branch-1'); $GLOBALS['branch']=$branch;
    $legal=new LegalEntity('legal-a'); $source=new InventorySource($legal);
    $saved=['state'=>'ESTIMATED_COST','tenant_id'=>'tenant-a','order_id'=>'completed',
        'source_id'=>'source-1','bom_id'=>'bom-1','bom_version'=>1,'completed_quantity'=>2,
        'total_cost'=>'40.0000','unit_cost'=>'20.0000',
        'consumptions'=>[['material_id'=>'material-1','quantity'=>'2.000000',
                         'unit_cost'=>'20.0000','line_cost'=>'40.0000']]];
    $completed=new ProductionOrder('completed',new \DateTimeImmutable('2026-10-20T01:00:00Z'),$saved);
    $unknown=new ProductionOrder('unknown',new \DateTimeImmutable('2026-10-20T01:00:00Z'));
    $wrong=new ProductionOrder('wrong',new \DateTimeImmutable('2026-10-20T01:00:00Z'),
        array_merge($saved,['order_id'=>'wrong','source_id'=>'other-source']));
    $draft=new ProductionOrder('draft',null);
    $other=new ProductionOrder('other-tenant',new \DateTimeImmutable('2026-10-20T01:00:00Z'));
    $store=new Store([
        InventorySource::class=>new Repo([
            ['tenant'=>$tenant,'branch'=>$branch,'entity'=>$source]
        ]),
        ProductionOrder::class=>new Repo([
            ['id'=>'completed','tenant'=>$tenant,'source'=>$source,'entity'=>$completed],
            ['id'=>'draft','tenant'=>$tenant,'source'=>$source,'entity'=>$draft],
            ['id'=>'unknown','tenant'=>$tenant,'source'=>$source,'entity'=>$unknown],
            ['id'=>'wrong','tenant'=>$tenant,'source'=>$source,'entity'=>$wrong],
            ['id'=>'other-tenant','tenant'=>$foreign,'source'=>$source,'entity'=>$other],
        ]),
        PurchaseOrder::class=>new PurchaseRepo([
            ['tenant'=>$tenant,'legal'=>$legal,'status'=>PurchaseOrder::STATUS_RECEIVED,
                'entity'=>new PurchaseOrder(new \DateTimeImmutable('2026-10-19T00:00:00Z'))],
            ['tenant'=>$tenant,'legal'=>$legal,'status'=>PurchaseOrder::STATUS_RECEIVED,
                'entity'=>new PurchaseOrder(new \DateTimeImmutable('2026-10-21T00:00:00Z'))],
            ['tenant'=>$foreign,'legal'=>$legal,'status'=>PurchaseOrder::STATUS_RECEIVED,
                'entity'=>new PurchaseOrder(new \DateTimeImmutable('2026-10-18T00:00:00Z'))],
        ])
    ]);
    $cost=new ProductionCostService();
    $api=new ProductionCostController(
        new CurrentTenantForUser($tenant),new BranchAuthorization(),$store,$cost);
    $result=$api->cost('branch-1','completed')->data;
    if ($result['cost']['total_cost']!=='40.0000'
        ||$result['cost']['unit_cost']!=='20.0000'
        ||$result['cost']['consumptions'][0]['line_cost']!=='40.0000'
        ||$result['snapshot_persisted']!==true
        ||$result['state']!=='ESTIMATED_COST'
        ||$result['provenance']!=='production_completion_snapshot') {
        throw new \RuntimeException('Completed order response invalid.');
    }
    foreach ([['branch-1','other-tenant'],['foreign-branch','completed']] as [$branchId,$orderId]) {
        try { $api->cost($branchId,$orderId); }
        catch (NotFoundHttpException) { continue; }
        throw new \RuntimeException('Cross-tenant or cross-branch access was exposed.');
    }
    try { $api->cost('branch-1','draft'); }
    catch (UnprocessableEntityHttpException) { goto checked; }
    throw new \RuntimeException('Draft order exposed a cost.');
    checked:
    $unknownResult=$api->cost('branch-1','unknown')->data;
    if ($unknownResult['state']!=='UNKNOWN' || $unknownResult['cost']!==null
        ||$unknownResult['snapshot_persisted']!==false) {
        throw new \RuntimeException('Legacy order inferred a price without evidence.');
    }
    try { $api->cost('branch-1','wrong'); }
    catch (UnprocessableEntityHttpException) { goto invalid_proof_checked; }
    throw new \RuntimeException('A cross-source snapshot was accepted.');
    invalid_proof_checked:
    if ($store->writes!==0) { throw new \RuntimeException('GET mutated storage.'); }
    echo 'OK';
}
'''


class CostoProduccionApiTests(unittest.TestCase):
    def test_acceptance(self) -> None:
        self.assertTrue(CONTROLLER.is_file())
        source = CONTROLLER.read_text(encoding="utf-8")
        self.assertIn("methods: ['GET']", source)
        self.assertIn("'tenant' => $tenant", source)
        self.assertIn("'source' => $source", source)
        with tempfile.TemporaryDirectory() as dirname:
            script = Path(dirname) / "production-cost-http-fixture.php"
            script.write_text(
                FIXTURE.replace("%CONTROLLER%", str(CONTROLLER)),
                encoding="utf-8",
            )
            result = subprocess.run(
                ["php", str(script)], text=True, capture_output=True, check=False,
            )
        self.assertEqual(result.returncode, 0, result.stderr)
        self.assertEqual(result.stdout, "OK")


if __name__ == "__main__":
    unittest.main()
