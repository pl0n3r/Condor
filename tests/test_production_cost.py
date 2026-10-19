#!/usr/bin/env python3
"""Aceptación offline de costo ponderado y snapshot por consumo, Issue #686."""

import subprocess
import tempfile
import unittest
from pathlib import Path

ROOT = Path(__file__).resolve().parents[1]
SERVICE = ROOT / "src/Application/Production/ProductionCostService.php"
WEIGHTED = ROOT / "src/Application/Purchasing/MaterialCostService.php"
BALANCE = ROOT / "src/Domain/Production/Entity/MaterialInventoryBalance.php"

FIXTURE = r'''<?php
declare(strict_types=1);
namespace App\Domain\Organization\Entity {
    class Tenant {
        public function __construct(private string $key) {}
        public function id(): string { return $this->key; }
    }
    class LegalEntity {
        public function __construct(private string $key) {}
        public function id(): string { return $this->key; }
    }
}
namespace App\Domain\Inventory\Entity {
    class InventorySource {
        public function __construct(private \App\Domain\Organization\Entity\Tenant $tenant, private \App\Domain\Organization\Entity\LegalEntity $legalEntity) {}
        public function tenant(): \App\Domain\Organization\Entity\Tenant { return $this->tenant; }
        public function legalEntity(): \App\Domain\Organization\Entity\LegalEntity { return $this->legalEntity; }
    }
}
namespace App\Domain\Catalog\Entity {
    class ProductVariant {
        public function __construct(private \App\Domain\Organization\Entity\Tenant $tenant) {}
        public function tenant(): \App\Domain\Organization\Entity\Tenant { return $this->tenant; }
        public function id(): string { return 'variant-1'; }
    }
}
namespace App\Domain\Production\Entity {
    use App\Domain\Organization\Entity\Tenant;
    class Material {
        public function __construct(private Tenant $tenant, private string $key) {}
        public function tenant(): Tenant { return $this->tenant; }
        public function id(): string { return $this->key; }
    }
    class BillOfMaterialsLine {
        public function __construct(private Tenant $tenant, private Material $material, private string $quantity) {}
        public function tenant(): Tenant { return $this->tenant; }
        public function material(): Material { return $this->material; }
        public function quantity(): string { return $this->quantity; }
    }
    class BillOfMaterials {
        public function __construct(private Tenant $tenant, private \App\Domain\Catalog\Entity\ProductVariant $variant, private array $lines) {}
        public function tenant(): Tenant { return $this->tenant; }
        public function variant(): \App\Domain\Catalog\Entity\ProductVariant { return $this->variant; }
        public function lines(): array { return $this->lines; }
        public function id(): string { return 'bom-1'; }
        public function version(): int { return 3; }
    }
    class ProductionOrder {
        public function __construct(private Tenant $tenant, private BillOfMaterials $bom, private \App\Domain\Catalog\Entity\ProductVariant $variant, private \App\Domain\Inventory\Entity\InventorySource $source, private bool $done = true) {}
        public function tenant(): Tenant { return $this->tenant; }
        public function billOfMaterials(): BillOfMaterials { return $this->bom; }
        public function variant(): \App\Domain\Catalog\Entity\ProductVariant { return $this->variant; }
        public function source(): \App\Domain\Inventory\Entity\InventorySource { return $this->source; }
        public function id(): string { return 'order-1'; }
        public function isCompleted(): bool { return $this->done; }
        public function completedQuantity(): ?int { return $this->done ? 2 : null; }
    }
}
namespace App\Domain\Purchasing\Entity {
    use App\Domain\Organization\Entity\Tenant;
    use App\Domain\Organization\Entity\LegalEntity;
    use App\Domain\Production\Entity\Material;
    class PurchaseOrderLine {
        public function __construct(private Tenant $tenant, private Material $material, private string $quantity, private string $price) {}
        public function tenant(): Tenant { return $this->tenant; }
        public function material(): Material { return $this->material; }
        public function quantity(): string { return $this->quantity; }
        public function unitCost(): string { return $this->price; }
    }
    class PurchaseOrder {
        public function __construct(private Tenant $tenant, private LegalEntity $legalEntity, private string $key, private array $lines, private bool $received = true) {}
        public function tenant(): Tenant { return $this->tenant; }
        public function legalEntity(): LegalEntity { return $this->legalEntity; }
        public function id(): string { return $this->key; }
        public function lines(): array { return $this->lines; }
        public function isReceived(): bool { return $this->received; }
    }
}
namespace {
    require %BALANCE%;
    require %WEIGHTED%;
    require %SERVICE%;
    use App\Domain\Organization\Entity\{Tenant, LegalEntity};
    use App\Domain\Inventory\Entity\InventorySource;
    use App\Domain\Catalog\Entity\ProductVariant;
    use App\Domain\Production\Entity\{Material, BillOfMaterialsLine, BillOfMaterials, ProductionOrder};
    use App\Domain\Purchasing\Entity\{PurchaseOrder, PurchaseOrderLine};
    use App\Application\Purchasing\MaterialCostService;
    use App\Application\Production\ProductionCostService;

    function expectFailure(callable $fn, string $reason): void {
        try { $fn(); } catch (\DomainException) { return; }
        throw new \RuntimeException('Missing expected rejection: '.$reason);
    }

    $tenant = new Tenant('tenant-1');
    $foreign = new Tenant('tenant-2');
    $legalEntity = new LegalEntity('legal-1');
    $otherLegalEntity = new LegalEntity('legal-2');
    $foreignLegalEntity = new LegalEntity('legal-foreign');
    $source = new InventorySource($tenant, $legalEntity);
    $variant = new ProductVariant($tenant);
    $materialA = new Material($tenant, 'material-a');
    $materialB = new Material($tenant, 'material-b');
    $bom = new BillOfMaterials($tenant, $variant, [
        new BillOfMaterialsLine($tenant, $materialA, '1.5'),
        new BillOfMaterialsLine($tenant, $materialB, '0.25'),
    ]);
    $order = new ProductionOrder($tenant, $bom, $variant, $source);
    $first = new PurchaseOrder($tenant, $legalEntity, 'purchase-1', [
        new PurchaseOrderLine($tenant, $materialA, '1', '10.0000'),
        new PurchaseOrderLine($tenant, $materialB, '1', '2.0000'),
    ]);
    $second = new PurchaseOrder($tenant, $legalEntity, 'purchase-2', [
        new PurchaseOrderLine($tenant, $materialA, '3', '14.0000'),
    ]);
    $service = new ProductionCostService(new MaterialCostService());
    $initial = $service->calculate($tenant, $order, [$first, $second]);
    if ($initial['state'] !== 'ESTIMATED_COST'
        || $initial['total_cost'] !== '40.0000'
        || $initial['unit_cost'] !== '20.0000'
    ) {
        throw new \RuntimeException('Wrong weighted cost: '.json_encode($initial));
    }
    $reordered = $service->calculate($tenant, $order, [$second, $first]);
    if ($reordered !== $initial) {
        throw new \RuntimeException('Weighted material cost depends on receipt order');
    }
    $foreignMaterial = new Material($foreign, 'material-a');
    $foreignReceipt = new PurchaseOrder($foreign, $foreignLegalEntity, 'foreign-receipt', [
        new PurchaseOrderLine($foreign, $foreignMaterial, '100', '0.0001'),
    ]);
    if ($service->calculate($tenant, $order, [$first, $foreignReceipt, $second]) !== $initial) {
        throw new \RuntimeException('Foreign tenant receipt affected production cost');
    }
    $otherLegalReceipt = new PurchaseOrder($tenant, $otherLegalEntity, 'purchase-other-entity', [
        new PurchaseOrderLine($tenant, $materialA, '100', '0.0001'),
        new PurchaseOrderLine($tenant, $materialB, '100', '999.0000'),
    ]);
    if ($service->calculate($tenant, $order, [$first, $otherLegalReceipt, $second]) !== $initial) {
        throw new \RuntimeException('Other legal entity changed weighted production cost');
    }
    expectFailure(fn() => $service->calculate($tenant, $order, [$otherLegalReceipt]), 'unknown cost from unrelated legal entity');
    if ($initial['consumptions'][0] !== [
        'material_id'=>'material-a',
        'quantity'=>'3',
        'unit_cost'=>'13.0000',
        'line_cost'=>'39.0000',
    ]) {
        throw new \RuntimeException('Historical unit cost is missing');
    }
    $unreceived = new PurchaseOrder($tenant, $legalEntity, 'purchase-unreceived', [
        new PurchaseOrderLine($tenant, $materialA, '100', '900.0000'),
    ], false);
    if ($service->calculate($tenant, $order, [$first, $second, $unreceived]) !== $initial) {
        throw new \RuntimeException('Unreceived purchase altered weighted material cost');
    }
    $later = new PurchaseOrder($tenant, $legalEntity, 'purchase-3', [
        new PurchaseOrderLine($tenant, $materialA, '100', '999.0000'),
        new PurchaseOrderLine($tenant, $materialB, '10', '50.0000'),
    ]);
    $liveCost = $service->calculate($tenant, $order, [$first, $second, $later]);
    if ($liveCost['total_cost'] === $initial['total_cost']) {
        throw new \RuntimeException('Expected weighted average to change with new receipts');
    }
    $stored = json_decode(json_encode($initial, JSON_THROW_ON_ERROR), true, 512, JSON_THROW_ON_ERROR);
    $forbiddenHistory = (static function (): \Generator {
        throw new \RuntimeException('Saved cost snapshot re-read current purchase history');
        yield null;
    })();
    $recalculated = $service->calculate($tenant, $order, $forbiddenHistory, $stored);
    if ($recalculated !== $initial) {
        throw new \RuntimeException('Recalculation did not preserve the original cost');
    }
    expectFailure(fn() => $service->calculate($foreign, $order, [$first]), 'cross tenant');
    expectFailure(fn() => $service->calculate($tenant, new ProductionOrder($tenant, $bom, $variant, $source, false), [$first]), 'draft order');
    expectFailure(fn() => $service->calculate($tenant, $order, []), 'unknown cost');
    $foreignLineBom = new BillOfMaterials($tenant, $variant, [new BillOfMaterialsLine($foreign, $materialA, '1')]);
    expectFailure(fn() => $service->calculate($tenant, new ProductionOrder($tenant, $foreignLineBom, $variant, $source), [$first]), 'foreign bom line');
    $foreignMatBom = new BillOfMaterials($tenant, $variant, [new BillOfMaterialsLine($tenant, new Material($foreign, 'material-x'), '1')]);
    expectFailure(fn() => $service->calculate($tenant, new ProductionOrder($tenant, $foreignMatBom, $variant, $source), [$first]), 'foreign bom material');
    $foreignSource = new InventorySource($foreign, $foreignLegalEntity);
    expectFailure(fn() => $service->calculate($tenant, new ProductionOrder($tenant, $bom, $variant, $foreignSource), [$first]), 'foreign production source');
    $bad = $initial; $bad['consumptions'][0]['quantity'] = '999';
    expectFailure(fn() => $service->calculate($tenant, $order, [], $bad), 'changed consumption');
    $bad = $initial; $bad['total_cost'] = '0.0000';
    expectFailure(fn() => $service->calculate($tenant, $order, [], $bad), 'changed total');
    $bad = $initial; $bad['consumptions'][1]['material_id'] = 'material-a';
    expectFailure(fn() => $service->calculate($tenant, $order, [], $bad), 'duplicate consumption');
    $bad = $initial; $bad['consumptions'][0]['material_id'] = 'unknown-material';
    expectFailure(fn() => $service->calculate($tenant, $order, [], $bad), 'unexpected material in snapshot');
    $bad = $initial; $bad['tenant_id'] = $foreign->id();
    expectFailure(fn() => $service->calculate($tenant, $order, [], $bad), 'cross-tenant snapshot');
    $bad = $initial; $bad['consumptions'][0]['unit_cost'] = '999999999999999999999';
    expectFailure(fn() => $service->calculate($tenant, $order, [], $bad), 'price overflow');
    echo 'ACCEPTANCE_OK';
}
'''


class CostoProduccionTests(unittest.TestCase):
    def test_acceptance(self) -> None:
        """Cost by unit/total remains stable after weighted prices change."""
        for path in (SERVICE, WEIGHTED, BALANCE):
            self.assertTrue(path.is_file(), f"Falta archivo PHP: {path}")
        syntax = subprocess.run(
            ["php", "-l", str(SERVICE)], text=True, capture_output=True, check=False
        )
        self.assertEqual(0, syntax.returncode, syntax.stdout + syntax.stderr)
        source = (
            FIXTURE.replace("%BALANCE%", repr(str(BALANCE)))
            .replace("%WEIGHTED%", repr(str(WEIGHTED)))
            .replace("%SERVICE%", repr(str(SERVICE)))
        )
        with tempfile.TemporaryDirectory() as temp:
            script = Path(temp) / "production-cost-acceptance.php"
            script.write_text(source, encoding="utf-8")
            result = subprocess.run(
                ["php", str(script)], cwd=ROOT, capture_output=True, text=True, check=False
            )
        self.assertEqual(0, result.returncode, result.stdout + result.stderr)
        self.assertIn("ACCEPTANCE_OK", result.stdout)


if __name__ == "__main__":
    unittest.main()
