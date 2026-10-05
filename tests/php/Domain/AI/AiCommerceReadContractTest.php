<?php

declare(strict_types=1);

namespace App\Tests\Domain\AI;

use App\Domain\AI\AiCommerceReadContract;
use App\Domain\AI\AiToolPolicy;
use DomainException;
use PHPUnit\Framework\TestCase;

final class AiCommerceReadContractTest extends TestCase
{
    public function testDescriptorsReuseExistingReadOnlyPolicyWithoutNewTools(): void
    {
        $policy = new AiToolPolicy();
        $before = $policy->allowlist();
        $contract = new AiCommerceReadContract($policy);

        $catalog = $contract->catalogDescriptor();
        self::assertSame('catalog.read', $catalog->tool());
        self::assertSame(AiToolPolicy::READ_ONLY, $catalog->risk());
        self::assertSame(['price_list_ref', 'product_ref'], $catalog->inputNames());
        self::assertSame(['product_ref'], $catalog->requiredInputs());
        self::assertSame(
            ['amount', 'currency', 'product_ref', 'status'],
            $catalog->outputNames(),
        );

        $inventory = $contract->inventoryDescriptor();
        self::assertSame('inventory.read', $inventory->tool());
        self::assertSame(AiToolPolicy::READ_ONLY, $inventory->risk());
        self::assertSame(['location_ref', 'product_ref'], $inventory->inputNames());
        self::assertSame(['product_ref'], $inventory->requiredInputs());
        self::assertSame(
            ['location_ref', 'product_ref', 'quantity', 'status'],
            $inventory->outputNames(),
        );

        self::assertSame($before, $policy->allowlist());
    }

    public function testCanonicalInputsAndOutputsAreAccepted(): void
    {
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
        $contract->validateCatalogOutputs([
            'product_ref' => 'product:sku_42',
            'currency' => null,
            'amount' => null,
            'status' => 'unavailable',
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
        $contract->validateInventoryOutputs([
            'product_ref' => 'product:sku_42',
            'location_ref' => null,
            'quantity' => 0,
            'status' => 'out_of_stock',
        ]);

        self::addToAssertionCount(6);
    }

    public function testInvalidRefsFreeTextExtraShapeAndNonfiniteValuesFailClosed(): void
    {
        $contract = new AiCommerceReadContract(new AiToolPolicy());

        $cases = [
            fn () => $contract->validateCatalogInputs(['product_ref' => 'SKU 42']),
            fn () => $contract->validateCatalogInputs([
                'product_ref' => 'product:sku_42',
                'query' => 'camiseta negra',
            ]),
            fn () => $contract->validateCatalogInputs([
                'product_ref' => 'product:sku_42',
                'price_list_ref' => 'Price_List:Retail',
            ]),
            fn () => $contract->validateCatalogOutputs([
                'product_ref' => 'product:sku_42',
                'currency' => 'BTC',
                'amount' => 10,
                'status' => 'available',
            ]),
            fn () => $contract->validateCatalogOutputs([
                'product_ref' => 'product:sku_42',
                'currency' => 'COP',
                'amount' => INF,
                'status' => 'available',
            ]),
            fn () => $contract->validateCatalogOutputs([
                'product_ref' => 'product:sku_42',
                'currency' => 'COP',
                'amount' => 10.123,
                'status' => 'available',
            ]),
            fn () => $contract->validateCatalogOutputs([
                'product_ref' => 'product:sku_42',
                'currency' => 'COP',
                'amount' => 10,
                'status' => 'unavailable',
            ]),
            fn () => $contract->validateInventoryOutputs([
                'product_ref' => 'product:sku_42',
                'location_ref' => 'location:main',
                'quantity' => 0,
                'status' => 'in_stock',
            ]),
            fn () => $contract->validateInventoryOutputs([
                'product_ref' => 'product:sku_42',
                'location_ref' => 'location:main',
                'quantity' => 2,
                'status' => 'out_of_stock',
            ]),
            fn () => $contract->validateInventoryOutputs([
                'product_ref' => 'product:sku_42',
                'location_ref' => 'location:main',
                'quantity' => ['raw' => 2],
                'status' => 'in_stock',
            ]),
        ];

        foreach ($cases as $case) {
            try {
                $case();
                self::fail('El caso inválido debía fallar cerrado.');
            } catch (DomainException) {
                self::addToAssertionCount(1);
            }
        }
    }
}
