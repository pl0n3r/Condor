#!/usr/bin/env python3
"""AC 01-03: costo inmutable al terminar, scope e integridad, Condor #738."""
import subprocess
import tempfile
import unittest
from pathlib import Path

ROOT = Path(__file__).resolve().parents[1]
ENTITY = ROOT / "src/Domain/Production/Entity/ProductionOrder.php"
SERVICE = ROOT / "src/Application/Production/ProductionOrderService.php"
MIGRATION = ROOT / "migrations/Version20261020070000.php"

FIXTURE = r'''<?php
declare(strict_types=1);
namespace App\Domain\Organization\Entity {
    class Tenant { public function id(): string { return 'tenant-a'; } }
}
namespace App\Domain\Catalog\Entity { class ProductVariant {} }
namespace App\Domain\Inventory\Entity {
    class InventorySource { public function id(): string { return 'source-a'; } }
}
namespace App\Domain\Production\Entity {
    class BillOfMaterials {
        public function id(): string { return 'bom-a'; }
        public function version(): int { return 1; }
    }
    require '%ENTITY%';
}
namespace Acceptance {
    use App\Domain\Production\Entity\{BillOfMaterials, ProductionOrder};
    use App\Domain\Organization\Entity\Tenant;
    use App\Domain\Inventory\Entity\InventorySource;
    $reflect = new \ReflectionClass(ProductionOrder::class);
    $order = $reflect->newInstanceWithoutConstructor();
    foreach ([
        'id'=>'order-a', 'status'=>ProductionOrder::STATUS_COMPLETED,
        'tenant'=>new Tenant(), 'source'=>new InventorySource(),
        'billOfMaterials'=>new BillOfMaterials(), 'completedQuantity'=>2,
    ] as $key=>$value) {
        $reflect->getProperty($key)->setValue($order, $value);
    }
    $snapshot = [
        'tenant_id'=>'tenant-a', 'order_id'=>'order-a', 'source_id'=>'source-a',
        'bom_id'=>'bom-a', 'bom_version'=>1, 'completed_quantity'=>2,
        'state'=>'ESTIMATED_COST', 'total_cost'=>'40.0000',
        'unit_cost'=>'20.0000', 'consumptions'=>[
            ['material_id'=>'material-a', 'quantity'=>'2.000000',
             'unit_cost'=>'20.0000', 'line_cost'=>'40.0000'],
        ],
    ];
    $order->saveCostSnapshot($snapshot);
    $order->saveCostSnapshot($snapshot);
    if ($order->costSnapshot() !== $snapshot) throw new \RuntimeException('Snapshot no reproducible');
    $foreign=$snapshot; $foreign['tenant_id']='tenant-b';
    try { $order->saveCostSnapshot($foreign); }
    catch (\DomainException) { goto cross_tenant_rejected; }
    throw new \RuntimeException('Otro tenant puede sobrescribir costo');
    cross_tenant_rejected:
    $changed=$snapshot; $changed['total_cost']='50.0000';
    try { $order->saveCostSnapshot($changed); }
    catch (\DomainException) { goto tampering_rejected; }
    throw new \RuntimeException('El importe puede revalorizarse');
    tampering_rejected:
    $reflect->getProperty('status')->setValue($order, ProductionOrder::STATUS_DRAFT);
    try { $order->saveCostSnapshot($snapshot); }
    catch (\DomainException) { echo 'OK'; exit(0); }
    throw new \RuntimeException('Costo de borrador aceptado');
}
'''

class ProductionSnapshotPersistenceTests(unittest.TestCase):
    def test_acceptance(self) -> None:
        entity=ENTITY.read_text(encoding="utf-8")
        service=SERVICE.read_text(encoding="utf-8")
        self.assertIn("type: 'json', nullable: true",entity)
        self.assertIn("public function costSnapshot()",entity)
        self.assertIn("public function saveCostSnapshot(",entity)
        self.assertIn("$order->saveCostSnapshot($snapshot)",service)
        self.assertIn("wrapInTransaction(",service)
        self.assertIn("->andWhere('p.tenant = :tenant')",service)
        self.assertIn("->andWhere('p.legalEntity = :legalEntity')",service)
        self.assertIn("->andWhere('p.receivedAt <= :completedAt')",service)
        with tempfile.TemporaryDirectory() as folder:
            file=Path(folder)/"fixture.php"
            file.write_text(FIXTURE.replace("%ENTITY%",str(ENTITY)),encoding="utf-8")
            ran=subprocess.run(["php",str(file)],capture_output=True,text=True)
        self.assertEqual(ran.returncode,0,ran.stderr)
        self.assertEqual(ran.stdout,"OK")

    def test_idempotence_and_unknown_cost_fail_closed(self) -> None:
        e=ENTITY.read_text(encoding="utf-8")
        s=SERVICE.read_text(encoding="utf-8")
        self.assertIn("$this->costSnapshot !== $snapshot",e)
        self.assertIn("if ($order->isCompleted())",s)
        self.assertIn("$order->assertSameCompletion(",s)
        self.assertIn("Costo ponderado desconocido:",s)
        self.assertIn("throw $exception;",s)
        self.assertIn("LockMode::PESSIMISTIC_WRITE",s)
        self.assertLess(s.index("$order->complete($completedQuantity, $key);"),
                        s.index("$order->saveCostSnapshot($snapshot);"))

    def test_schema_additive_and_reversible(self) -> None:
        text=MIGRATION.read_text(encoding="utf-8")
        up=text.split("public function up",1)[1].split("public function down",1)[0]
        down=text.split("public function down",1)[1]
        self.assertIn("ALTER TABLE condor_production_order ADD cost_snapshot JSON DEFAULT NULL",up)
        self.assertNotIn("DROP TABLE",up)
        self.assertNotIn("UPDATE ",up)
        self.assertIn("ALTER TABLE condor_production_order DROP cost_snapshot",down)

if __name__ == "__main__":
    unittest.main()
