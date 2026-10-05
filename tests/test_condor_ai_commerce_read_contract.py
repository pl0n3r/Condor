#!/usr/bin/env python3
from __future__ import annotations

import json
import subprocess
import unittest
from pathlib import Path

ROOT = Path(__file__).resolve().parents[1]
SOURCE = ROOT / "src/Domain/AI/AiCommerceReadContract.php"
POLICY = ROOT / "src/Domain/AI/AiToolPolicy.php"


def run_php(body: str) -> dict[str, object]:
    prefix = r"""
require 'vendor/autoload.php';
use App\Domain\AI\AiCommerceReadContract;
use App\Domain\AI\AiToolPolicy;
$contract = new AiCommerceReadContract(new AiToolPolicy());
"""
    raw = subprocess.check_output(
        ["php", "-r", prefix + body],
        cwd=ROOT,
        text=True,
        stderr=subprocess.STDOUT,
    )
    return json.loads(raw)


class CondorAiCommerceReadContractTests(unittest.TestCase):
    def test_catalog_and_inventory_contracts_reuse_existing_read_only_policy_without_new_tools(self) -> None:
        observed = run_php(
            r"""
$c = $contract->catalogDescriptor();
$i = $contract->inventoryDescriptor();
print json_encode([
  'catalog' => [$c->tool(), $c->risk(), $c->inputNames(), $c->outputNames()],
  'inventory' => [$i->tool(), $i->risk(), $i->inputNames(), $i->outputNames()],
], JSON_THROW_ON_ERROR);
"""
        )
        self.assertEqual("catalog.read", observed["catalog"][0])
        self.assertEqual("read_only", observed["catalog"][1])
        self.assertEqual("inventory.read", observed["inventory"][0])
        self.assertEqual("read_only", observed["inventory"][1])
        self.assertNotIn(
            "commerce.price.read",
            POLICY.read_text(encoding="utf-8"),
        )

    def test_exact_product_stock_and_price_inputs_outputs_are_scalar_bounded_and_canonical(self) -> None:
        observed = run_php(
            r"""
$contract->validateCatalogInputs([
  'product_ref' => 'product:sku_42',
  'price_list_ref' => 'price_list:wholesale',
]);
$contract->validateCatalogOutputs([
  'product_ref' => 'product:sku_42',
  'currency' => 'COP',
  'amount' => 19900.50,
  'status' => 'available',
]);
$contract->validateInventoryInputs([
  'product_ref' => 'product:sku_42',
  'branch_ref' => 'branch:main',
]);
$contract->validateInventoryOutputs([
  'product_ref' => 'product:sku_42',
  'branch_ref' => 'branch:main',
  'quantity' => 12.500,
  'status' => 'in_stock',
]);
print json_encode(['accepted' => true], JSON_THROW_ON_ERROR);
"""
        )
        self.assertTrue(observed["accepted"])

    def test_free_text_extra_cross_shape_or_nonfinite_values_fail_closed(self) -> None:
        observed = run_php(
            r"""
$cases = [
  'free_text' => fn () => $contract->validateCatalogInputs(['product_ref' => 'SKU 42']),
  'extra' => fn () => $contract->validateCatalogInputs([
    'product_ref' => 'product:sku_42', 'query' => 'camiseta negra'
  ]),
  'currency' => fn () => $contract->validateCatalogOutputs([
    'product_ref' => 'product:sku_42', 'currency' => 'BTC',
    'amount' => 10, 'status' => 'available'
  ]),
  'nonfinite' => fn () => $contract->validateCatalogOutputs([
    'product_ref' => 'product:sku_42', 'currency' => 'COP',
    'amount' => INF, 'status' => 'available'
  ]),
  'price_shape' => fn () => $contract->validateCatalogOutputs([
    'product_ref' => 'product:sku_42', 'currency' => 'COP',
    'amount' => 10, 'status' => 'unavailable'
  ]),
  'stock_shape' => fn () => $contract->validateInventoryOutputs([
    'product_ref' => 'product:sku_42', 'branch_ref' => 'branch:main',
    'quantity' => 0, 'status' => 'in_stock'
  ]),
];
$out = [];
foreach ($cases as $name => $case) {
  try { $case(); $out[$name] = false; }
  catch (DomainException) { $out[$name] = true; }
}
print json_encode($out, JSON_THROW_ON_ERROR);
"""
        )
        self.assertTrue(all(observed.values()), observed)

    def test_contract_has_no_io_provider_channel_or_sensitive_material(self) -> None:
        source = SOURCE.read_text(encoding="utf-8").lower()
        for forbidden in (
            "pdo", "doctrine", "httpclient", "curl_", "file_get_contents(",
            "fopen(", "provider", "model", "channel", "secret", "token",
            "credential", "callable", "closure",
        ):
            with self.subTest(forbidden=forbidden):
                self.assertNotIn(forbidden, source)


if __name__ == "__main__":
    unittest.main()
