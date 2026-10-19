#!/usr/bin/env python3
"""Executable acceptance for tenant-scoped stock-low notifications."""
from __future__ import annotations

import json
import subprocess
import unittest
from pathlib import Path

ROOT = Path(__file__).resolve().parents[1]


def run_php(code: str) -> dict[str, object]:
    result = subprocess.run(
        ("php", "-r", code),
        cwd=ROOT,
        text=True,
        stdout=subprocess.PIPE,
        stderr=subprocess.STDOUT,
        check=False,
    )
    if result.returncode:
        raise AssertionError(result.stdout)
    return json.loads(result.stdout)


PHP_SCENARIO = r"""
require 'vendor/autoload.php';

use App\Application\Notification\StockAlertService;
use App\Domain\Catalog\Entity\Product;
use App\Domain\Catalog\Entity\ProductVariant;
use App\Domain\Identity\Entity\Membership;
use App\Domain\Identity\Entity\User;
use App\Domain\Inventory\Entity\InventoryMovement;
use App\Domain\Inventory\Entity\InventorySource;
use App\Domain\Organization\Entity\LegalEntity;
use App\Domain\Organization\Entity\Tenant;

function stockScope(string $suffix): array {
    $tenant = new Tenant('Empresa '.$suffix, 'stock-'.$suffix);
    $legal = new LegalEntity($tenant, 'Empresa '.$suffix, null, true);
    $source = new InventorySource($tenant, $legal, 'Almacén', 'almacen', InventorySource::TYPE_LOGICAL);
    $product = new Product($tenant, 'Producto', 'producto');
    $variant = new ProductVariant($tenant, $product, 'SKU-'.$suffix, 'Variante');
    $membership = new Membership($tenant, new User('owner'.$suffix.'@example.invalid', 'Dueño'), Membership::ROLE_OWNER);
    return [$tenant, $source, $variant, $membership];
}
function stockMove(array $scope, int $delta, int $after): InventoryMovement {
    [$tenant, $source, $variant] = $scope;
    return new InventoryMovement(
        $tenant, $source, $variant,
        $delta > 0 ? InventoryMovement::TYPE_ADJUSTMENT_IN : InventoryMovement::TYPE_ADJUSTMENT_OUT,
        $delta, $after, null,
    );
}

$a = stockScope('a');
$b = stockScope('b');
$alerts = new StockAlertService();

$cross = stockMove($a, -3, 4); // 7 -> 4, minimum 5
$first = $alerts->onMovement($a[3], $cross, 5);
$repeat = $alerts->onMovement($a[3], $cross, 5);
$stillLow = $alerts->onMovement($a[3], stockMove($a, -1, 3), 5);
$replenish = $alerts->onMovement($a[3], stockMove($a, 8, 11), 5);
$again = $alerts->onMovement($a[3], stockMove($a, -8, 3), 5);
$alternate = new InventorySource($a[0], $a[1]->legalEntity(), 'Auxiliar', 'auxiliar', InventorySource::TYPE_LOGICAL);
$alternateScope = [$a[0], $alternate, $a[2], $a[3]];
$sameTenantOtherSource = $alerts->onMovement($a[3], stockMove($alternateScope, -5, 2), 3);
$otherMinimum = $alerts->onMovement($b[3], stockMove($b, -3, 4), 3);
$otherCross = $alerts->onMovement($b[3], stockMove($b, -5, 2), 3);
$mismatch = false;
try {
    $alerts->onMovement($b[3], stockMove($a, -3, 4), 5);
} catch (DomainException $exception) {
    $mismatch = true;
}
$invalid = false;
try {
    $alerts->onMovement($a[3], stockMove($a, -3, 4), -1);
} catch (DomainException $exception) {
    $invalid = true;
}
print json_encode([
    'first' => $first?->payload(),
    'event' => $first?->eventKey(),
    'channel' => $first?->channelKey(),
    'repeat' => $repeat === null,
    'still_low' => $stillLow === null,
    'replenish' => $replenish === null,
    'again' => $again?->payload(),
    'independent_minimum' => $otherMinimum === null,
    'other_tenant' => $otherCross?->payload(),
    'same_tenant_other_source' => $sameTenantOtherSource?->payload(),
    'mismatch_rejected' => $mismatch,
    'invalid_minimum_rejected' => $invalid,
], JSON_THROW_ON_ERROR);
"""


class AlertasStockBajoTests(unittest.TestCase):
    def test_acceptance(self) -> None:
        observed = run_php(PHP_SCENARIO)
        first = observed["first"]
        second = observed["again"]
        other = observed["other_tenant"]
        same_tenant_other_source = observed["same_tenant_other_source"]
        self.assertEqual("stock.low", observed["event"])
        self.assertEqual("in_app", observed["channel"])
        self.assertTrue(observed["repeat"])
        self.assertTrue(observed["still_low"])
        self.assertTrue(observed["replenish"])
        self.assertEqual(5, first["minimum"])
        self.assertEqual(4, first["quantity"])
        self.assertEqual(first["tenant_id"], second["tenant_id"])
        self.assertEqual(first["source_id"], second["source_id"])
        self.assertEqual(first["variant_id"], second["variant_id"])
        self.assertNotEqual(first["movement_id"], second["movement_id"])
        self.assertTrue(observed["independent_minimum"])
        self.assertEqual(3, other["minimum"])
        self.assertEqual(2, other["quantity"])
        self.assertNotEqual(first["tenant_id"], other["tenant_id"])
        self.assertEqual(first["tenant_id"], same_tenant_other_source["tenant_id"])
        self.assertEqual(first["variant_id"], same_tenant_other_source["variant_id"])
        self.assertNotEqual(first["source_id"], same_tenant_other_source["source_id"])
        self.assertEqual(3, same_tenant_other_source["minimum"])
        self.assertTrue(observed["mismatch_rejected"])
        self.assertTrue(observed["invalid_minimum_rejected"])


if __name__ == "__main__":
    unittest.main()
