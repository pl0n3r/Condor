#!/usr/bin/env python3
from __future__ import annotations

import json
import subprocess
import unittest
from pathlib import Path

ROOT = Path(__file__).resolve().parents[1]
SOURCE = ROOT / "src/Domain/AI/AiCommerceReadContract.php"
POLICY = ROOT / "src/Domain/AI/AiToolPolicy.php"


def run_php(script: str) -> dict[str, object]:
    raw = subprocess.check_output(
        ["php", "-r", script],
        cwd=ROOT,
        text=True,
        stderr=subprocess.STDOUT,
    )
    return json.loads(raw)


class CondorAiCommerceReadContractTests(unittest.TestCase):
    def test_catalog_and_inventory_contracts_reuse_existing_read_only_policy_without_new_tools(self) -> None:
        observed = run_php(
            r"""
require 'vendor/autoload.php';

use App\Domain\AI\AiCommerceReadContract;
use App\Domain\AI\AiToolPolicy;

$policy = new AiToolPolicy();
$before = $policy->allowlist();
$contract = new AiCommerceReadContract($policy);
$catalog = $contract->catalogDescriptor();
$inventory = $contract->inventoryDescriptor();

print json_encode([
    'before' => $before,
    'after' => $policy->allowlist(),
    'catalog' => [
        'tool' => $catalog->tool(),
        'risk' => $catalog->risk(),
        'inputs' => $catalog->inputNames(),
        'required_inputs' => $catalog->requiredInputs(),
        'outputs' => $catalog->outputNames(),
        'required_outputs' => $catalog->requiredOutputs(),
    ],
    'inventory' => [
        'tool' => $inventory->tool(),
        'risk' => $inventory->risk(),
        'inputs' => $inventory->inputNames(),
        'required_inputs' => $inventory->requiredInputs(),
        'outputs' => $inventory->outputNames(),
        'required_outputs' => $inventory->requiredOutputs(),
    ],
], JSON_THROW_ON_ERROR);
"""
        )

        self.assertEqual(observed["before"], observed["after"])
        self.assertEqual("catalog.read", observed["catalog"]["tool"])
        self.assertEqual("read_only", observed["catalog"]["risk"])
        self.assertEqual(
            ["price_list_ref", "product_ref"],
            observed["catalog"]["inputs"],
        )
        self.assertEqual(["product_ref"], observed["catalog"]["required_inputs"])
        self.assertEqual(
            ["amount", "currency", "product_ref", "status"],
            observed["catalog"]["outputs"],
        )
        self.assertEqual(
            observed["catalog"]["outputs"],
            observed["catalog"]["required_outputs"],
        )
        self.assertEqual("inventory.read", observed["inventory"]["tool"])
        self.assertEqual("read_only", observed["inventory"]["risk"])
        self.assertEqual(
            ["location_ref", "product_ref"],
            observed["inventory"]["inputs"],
        )

        policy_source = POLICY.read_text(encoding="utf-8")
        self.assertNotIn("commerce.price.read", policy_source)

    def test_exact_product_stock_and_price_inputs_outputs_are_scalar_bounded_and_canonical(self) -> None:
        observed = run_php(
            r"""
require 'vendor/autoload.php';

use App\Domain\AI\AiCommerceReadContract;
use App\Domain\AI\AiToolPolicy;

$contract = new AiCommerceReadContract(new AiToolPolicy());
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
    'location_ref' => 'location:main',
]);
$contract->validateInventoryOutputs([
    'product_ref' => 'product:sku_42',
    'location_ref' => 'location:main',
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
require 'vendor/autoload.php';

use App\Domain\AI\AiCommerceReadContract;
use App\Domain\AI\AiToolPolicy;

$contract = new AiCommerceReadContract(new AiToolPolicy());
$cases = [
    'free_text' => fn () => $contract->validateCatalogInputs([
        'product_ref' => 'SKU 42',
    ]),
    'extra_query' => fn () => $contract->validateCatalogInputs([
        'product_ref' => 'product:sku_42',
        'query' => 'camiseta negra',
    ]),
    'bad_currency' => fn () => $contract->validateCatalogOutputs([
        'product_ref' => 'product:sku_42',
        'currency' => 'BTC',
        'amount' => 10,
        'status' => 'available',
    ]),
    'nonfinite_amount' => fn () => $contract->validateCatalogOutputs([
        'product_ref' => 'product:sku_42',
        'currency' => 'COP',
        'amount' => INF,
        'status' => 'available',
    ]),
    'cross_price_shape' => fn () => $contract->validateCatalogOutputs([
        'product_ref' => 'product:sku_42',
        'currency' => 'COP',
        'amount' => 10,
        'status' => 'unavailable',
    ]),
    'cross_stock_shape' => fn () => $contract->validateInventoryOutputs([
        'product_ref' => 'product:sku_42',
        'location_ref' => 'location:main',
        'quantity' => 0,
        'status' => 'in_stock',
    ]),
    'nested_quantity' => fn () => $contract->validateInventoryOutputs([
        'product_ref' => 'product:sku_42',
        'location_ref' => 'location:main',
        'quantity' => ['raw' => 1],
        'status' => 'in_stock',
    ]),
];

$out = [];
foreach ($cases as $name => $case) {
    try {
        $case();
        $out[$name] = false;
    } catch (DomainException) {
        $out[$name] = true;
    }
}

print json_encode($out, JSON_THROW_ON_ERROR);
"""
        )

        self.assertTrue(all(observed.values()), observed)

    def test_contract_has_no_io_provider_channel_or_sensitive_material(self) -> None:
        source = SOURCE.read_text(encoding="utf-8").lower()
        for forbidden in (
            "pdo",
            "doctrine",
            "httpclient",
            "curl_",
            "file_get_contents(",
            "fopen(",
            "provider",
            "model",
            "channel",
            "secret",
            "token",
            "credential",
            "callable",
            "closure",
        ):
            with self.subTest(forbidden=forbidden):
                self.assertNotIn(forbidden, source)


if __name__ == "__main__":
    unittest.main()
