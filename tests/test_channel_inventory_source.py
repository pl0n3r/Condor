#!/usr/bin/env python3
"""Condor #863: behavior-first contract with synthetic tenant/source/balance objects."""

from __future__ import annotations

import json
import shutil
import subprocess
import unittest
from pathlib import Path

ROOT = Path(__file__).resolve().parents[1]
SERVICE = ROOT / "src/Application/Inventory/ChannelInventorySource.php"

PHP_FIXTURE = r'''<?php
namespace App\Domain\Organization\Entity {
    class Tenant { public function __construct(private string $id) {} public function id(): string { return $this->id; } }
    class LegalEntity { public function __construct(private string $id) {} public function id(): string { return $this->id; } }
}
namespace App\Domain\Inventory\Entity {
    use App\Domain\Organization\Entity\Tenant;
    use App\Domain\Organization\Entity\LegalEntity;
    use App\Domain\Catalog\Entity\ProductVariant;
    class InventorySource {
        public function __construct(private string $id, private Tenant $tenant, private LegalEntity $legal, private bool $active = true) {}
        public function id(): string { return $this->id; }
        public function tenant(): Tenant { return $this->tenant; }
        public function legalEntity(): LegalEntity { return $this->legal; }
        public function isActive(): bool { return $this->active; }
    }
    class InventoryBalance {
        public function __construct(private Tenant $tenant, private InventorySource $source, private ProductVariant $variant, private int $quantity, private int $reserved = 0) {}
        public function tenant(): Tenant { return $this->tenant; }
        public function source(): InventorySource { return $this->source; }
        public function variant(): ProductVariant { return $this->variant; }
        public function legalEntity(): LegalEntity { return $this->source->legalEntity(); }
        public function sellableQuantity(): int { return $this->quantity - $this->reserved; }
    }
}
namespace App\Domain\Catalog\Entity {
    use App\Domain\Organization\Entity\Tenant;
    class Product {
        public function __construct(private bool $allowBackorder = false, private bool $active = true) {}
        public function allowsBackorder(): bool { return $this->allowBackorder; }
        public function isActive(): bool { return $this->active; }
    }
    class ProductVariant {
        public function __construct(private string $id, private Tenant $tenant, private Product $product, private bool $active = true) {}
        public function id(): string { return $this->id; }
        public function tenant(): Tenant { return $this->tenant; }
        public function product(): Product { return $this->product; }
        public function isActive(): bool { return $this->active; }
    }
}
namespace App\Domain\Commerce\Entity {
    use App\Domain\Organization\Entity\Tenant;
    use App\Domain\Organization\Entity\LegalEntity;
    use App\Domain\Inventory\Entity\InventorySource;
    class SalesChannel {
        public function __construct(private Tenant $tenant, private LegalEntity $legal, private InventorySource $source, private bool $active = true) {}
        public function tenant(): Tenant { return $this->tenant; }
        public function legalEntity(): LegalEntity { return $this->legal; }
        public function inventorySource(): InventorySource { return $this->source; }
        public function isActive(): bool { return $this->active; }
    }
}
namespace {
    use App\Application\Inventory\ChannelInventorySource;
    use App\Domain\Catalog\Entity\Product;
    use App\Domain\Catalog\Entity\ProductVariant;
    use App\Domain\Commerce\Entity\SalesChannel;
    use App\Domain\Inventory\Entity\InventorySource;
    use App\Domain\Inventory\Entity\InventoryBalance;
    use App\Domain\Organization\Entity\Tenant;
    use App\Domain\Organization\Entity\LegalEntity;

    require $argv[1];
    $a = new Tenant('empresa-a');
    $b = new Tenant('empresa-b');
    $legalA = new LegalEntity('razon-a');
    $legalB = new LegalEntity('razon-b');
    $branchA = new InventorySource('sede-a', $a, $legalA);
    $branchB = new InventorySource('sede-b', $a, $legalA);
    $sourceB = new InventorySource('externo-b', $b, $legalB);
    $channel = new SalesChannel($a, $legalA, $branchA);
    $product = new Product();
    $variant = new ProductVariant('sku-a', $a, $product);
    $service = new ChannelInventorySource();
    $report = [];
    $report['empty_source'] = $service->availability($a, $channel, $variant, null);
    $report['stock_here'] = $service->availability($a, $channel, $variant, new InventoryBalance($a, $branchA, $variant, 5, 2), 3);
    $report['not_enough'] = $service->availability($a, $channel, $variant, new InventoryBalance($a, $branchA, $variant, 2, 1), 2);
    $productBackorder = new Product(true);
    $variantBackorder = new ProductVariant('sku-b', $a, $productBackorder);
    $report['backorder'] = $service->availability($a, $channel, $variantBackorder, null, 3);
    $rejected = static function(callable $operation): bool {
        try { $operation(); } catch (\DomainException $exception) { return true; }
        return false;
    };
    $report['reject_other_branch'] = $rejected(static fn () => $service->availability($a, $channel, $variant, new InventoryBalance($a, $branchB, $variant, 100)));
    $report['reject_other_tenant'] = $rejected(static fn () => $service->availability($a, $channel, $variant, new InventoryBalance($b, $sourceB, $variant, 100)));
    $report['reject_other_channel'] = $rejected(static fn () => $service->availability($a, new SalesChannel($b, $legalB, $sourceB), $variant, null));
    $report['reject_inactive_source'] = $rejected(static fn () => $service->availability($a, new SalesChannel($a, $legalA, new InventorySource('inactive', $a, $legalA, false)), $variant, null));
    $report['reject_inactive_variant'] = $rejected(static fn () => $service->availability($a, $channel, new ProductVariant('disabled', $a, $product, false), null));
    $report['reject_zero_quantity'] = $rejected(static fn () => $service->availability($a, $channel, $variant, null, 0));
    $report['original_source'] = $channel->inventorySource()->id();
    echo json_encode($report, JSON_THROW_ON_ERROR);
}
'''


class FuenteInventarioCanalTests(unittest.TestCase):
    def test_acceptance(self) -> None:
        php = shutil.which("php")
        self.assertIsNotNone(php, "PHP CLI is required to exercise the real service")
        self.assertTrue(SERVICE.is_file(), "ChannelInventorySource PHP service missing")
        result = subprocess.run(
            [php, "-r", PHP_FIXTURE.removeprefix("<?php\n"), str(SERVICE)],
            check=False,
            capture_output=True,
            text=True,
            timeout=20,
        )
        self.assertEqual(result.returncode, 0, result.stderr)
        state = json.loads(result.stdout)
        self.assertEqual(state["empty_source"]["source_id"], "sede-a")
        self.assertEqual(state["empty_source"]["status"], "out_of_stock")
        self.assertEqual(state["empty_source"]["sellable_quantity"], 0)
        self.assertFalse(state["empty_source"]["can_fulfill"])
        self.assertEqual(state["stock_here"]["sellable_quantity"], 3)
        self.assertEqual(state["stock_here"]["status"], "in_stock")
        self.assertTrue(state["stock_here"]["can_fulfill"])
        self.assertFalse(state["not_enough"]["can_fulfill"])
        self.assertEqual(state["not_enough"]["sellable_quantity"], 1)
        self.assertEqual(state["backorder"]["status"], "out_of_stock")
        self.assertTrue(state["backorder"]["backorder_allowed"])
        self.assertTrue(state["backorder"]["can_fulfill"])
        for name in (
            "reject_other_branch", "reject_other_tenant", "reject_other_channel",
            "reject_inactive_source", "reject_inactive_variant", "reject_zero_quantity",
        ):
            with self.subTest(name=name):
                self.assertTrue(state[name], f"Must fail closed: {name}")
        self.assertEqual(state["original_source"], "sede-a")


if __name__ == "__main__":
    unittest.main()
