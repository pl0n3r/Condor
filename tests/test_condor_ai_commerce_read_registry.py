#!/usr/bin/env python3
from __future__ import annotations

import json
import subprocess
import unittest
from pathlib import Path


ROOT = Path(__file__).resolve().parents[1]
SOURCE = ROOT / "src/Application/AI/AiCommerceReadRegistry.php"
POLICY = ROOT / "src/Domain/AI/AiToolPolicy.php"


def run_php(body: str) -> dict[str, object]:
    prefix = r"""
require 'vendor/autoload.php';

use App\Application\AI\AiCommerceReadRegistry;
use App\Domain\AI\AiToolPolicy;

$policy = new AiToolPolicy();
"""
    raw = subprocess.check_output(
        ["php", "-r", prefix + body],
        cwd=ROOT,
        text=True,
        stderr=subprocess.STDOUT,
    )
    return json.loads(raw)


class CondorAiCommerceReadRegistryTests(unittest.TestCase):
    def test_registry_binds_only_catalog_and_inventory_read_with_injected_handlers(self) -> None:
        observed = run_php(
            r"""
$catalogCalls = 0;
$inventoryCalls = 0;
$registry = AiCommerceReadRegistry::fromArray(
    $policy,
    [
        'catalog.read' => static function (array $inputs) use (&$catalogCalls): array {
            ++$catalogCalls;
            return [
                'product_ref' => $inputs['product_ref'],
                'currency' => 'COP',
                'amount' => 19900.50,
                'status' => 'available',
            ];
        },
        'inventory.read' => static function (array $inputs) use (&$inventoryCalls): array {
            ++$inventoryCalls;
            return [
                'product_ref' => $inputs['product_ref'],
                'branch_ref' => $inputs['branch_ref'] ?? null,
                'quantity' => 12.500,
                'status' => 'in_stock',
            ];
        },
    ],
);

$catalog = $registry->execute(
    'catalog.read',
    ['product_ref' => 'product:sku_42', 'price_list_ref' => 'price_list:wholesale'],
);
$inventory = $registry->execute(
    'inventory.read',
    ['product_ref' => 'product:sku_42', 'branch_ref' => 'branch:main'],
);

print json_encode([
    'catalog_tool' => $registry->descriptor('catalog.read')->tool(),
    'catalog_risk' => $registry->descriptor('catalog.read')->risk(),
    'inventory_tool' => $registry->descriptor('inventory.read')->tool(),
    'inventory_risk' => $registry->descriptor('inventory.read')->risk(),
    'catalog' => $catalog,
    'inventory' => $inventory,
    'catalog_calls' => $catalogCalls,
    'inventory_calls' => $inventoryCalls,
], JSON_THROW_ON_ERROR);
"""
        )

        self.assertEqual("catalog.read", observed["catalog_tool"])
        self.assertEqual("read_only", observed["catalog_risk"])
        self.assertEqual("inventory.read", observed["inventory_tool"])
        self.assertEqual("read_only", observed["inventory_risk"])
        self.assertEqual("available", observed["catalog"]["status"])
        self.assertEqual("in_stock", observed["inventory"]["status"])
        self.assertEqual(1, observed["catalog_calls"])
        self.assertEqual(1, observed["inventory_calls"])

    def test_invalid_inputs_fail_before_handler_and_invalid_outputs_fail_closed(self) -> None:
        observed = run_php(
            r"""
$catalogCalls = 0;
$inventoryCalls = 0;
$registry = AiCommerceReadRegistry::fromArray(
    $policy,
    [
        'catalog.read' => static function (array $inputs) use (&$catalogCalls): array {
            ++$catalogCalls;
            return [
                'product_ref' => $inputs['product_ref'],
                'currency' => 'BTC',
                'amount' => 10,
                'status' => 'available',
            ];
        },
        'inventory.read' => static function (array $inputs) use (&$inventoryCalls): array {
            ++$inventoryCalls;
            return [
                'product_ref' => $inputs['product_ref'],
                'branch_ref' => $inputs['branch_ref'] ?? null,
                'quantity' => 0,
                'status' => 'in_stock',
            ];
        },
    ],
);

$out = [];
try {
    $registry->execute('catalog.read', ['product_ref' => 'SKU 42']);
    $out['input_rejected'] = false;
} catch (DomainException) {
    $out['input_rejected'] = true;
}
$out['calls_after_input'] = $catalogCalls;

try {
    $registry->execute('catalog.read', ['product_ref' => 'product:sku_42']);
    $out['catalog_output_rejected'] = false;
} catch (DomainException) {
    $out['catalog_output_rejected'] = true;
}

try {
    $registry->execute(
        'inventory.read',
        ['product_ref' => 'product:sku_42', 'branch_ref' => 'branch:main'],
    );
    $out['inventory_output_rejected'] = false;
} catch (DomainException) {
    $out['inventory_output_rejected'] = true;
}

try {
    AiCommerceReadRegistry::fromArray(
        $policy,
        ['catalog.read' => static fn (array $inputs): array => $inputs],
    );
    $out['missing_handler_rejected'] = false;
} catch (DomainException) {
    $out['missing_handler_rejected'] = true;
}

print json_encode($out + [
    'catalog_calls' => $catalogCalls,
    'inventory_calls' => $inventoryCalls,
], JSON_THROW_ON_ERROR);
"""
        )

        self.assertTrue(observed["input_rejected"])
        self.assertEqual(0, observed["calls_after_input"])
        self.assertTrue(observed["catalog_output_rejected"])
        self.assertTrue(observed["inventory_output_rejected"])
        self.assertTrue(observed["missing_handler_rejected"])
        self.assertEqual(1, observed["catalog_calls"])
        self.assertEqual(1, observed["inventory_calls"])

    def test_registry_has_no_database_network_provider_or_mutating_tool_authority(self) -> None:
        source = SOURCE.read_text(encoding="utf-8").lower()
        policy = POLICY.read_text(encoding="utf-8")

        for forbidden in (
            "pdo",
            "doctrine",
            "httpclient",
            "curl_",
            "file_get_contents(",
            "fopen(",
            "guzzle",
            "provider",
            "model",
            "channel",
            "secret",
            "token",
            "credential",
            "commerce.price.read",
            "draft.update",
            "permission.change",
        ):
            with self.subTest(forbidden=forbidden):
                self.assertNotIn(forbidden, source)

        self.assertIn("'catalog.read' => self::READ_ONLY", policy)
        self.assertIn("'inventory.read' => self::READ_ONLY", policy)
        self.assertNotIn("commerce.price.read", policy)

    def test_binding_is_deterministic_and_preserves_existing_tool_policy(self) -> None:
        observed = run_php(
            r"""
$before = $policy->allowlist();
$handlers = [
    'catalog.read' => static fn (array $inputs): array => [
        'product_ref' => $inputs['product_ref'],
        'currency' => null,
        'amount' => null,
        'status' => 'unavailable',
    ],
    'inventory.read' => static fn (array $inputs): array => [
        'product_ref' => $inputs['product_ref'],
        'branch_ref' => $inputs['branch_ref'] ?? null,
        'quantity' => 0,
        'status' => 'out_of_stock',
    ],
];
$first = AiCommerceReadRegistry::fromArray($policy, $handlers);
$second = AiCommerceReadRegistry::fromArray($policy, $handlers);

$out = [
    'catalog_equal' => (
        $first->descriptor('catalog.read')->snapshot()
        === $second->descriptor('catalog.read')->snapshot()
    ),
    'inventory_equal' => (
        $first->descriptor('inventory.read')->snapshot()
        === $second->descriptor('inventory.read')->snapshot()
    ),
    'policy_unchanged' => $before === $policy->allowlist(),
];

foreach (
    [
        'knowledge' => 'knowledge.read',
        'noncanonical' => 'Catalog.Read',
        'unknown' => 'unknown.read',
    ] as $name => $tool
) {
    try {
        $first->descriptor($tool);
        $out[$name] = false;
    } catch (DomainException) {
        $out[$name] = true;
    }
}

print json_encode($out, JSON_THROW_ON_ERROR);
"""
        )

        self.assertTrue(observed["catalog_equal"])
        self.assertTrue(observed["inventory_equal"])
        self.assertTrue(observed["policy_unchanged"])
        self.assertTrue(observed["knowledge"])
        self.assertTrue(observed["noncanonical"])
        self.assertTrue(observed["unknown"])


if __name__ == "__main__":
    unittest.main()
