<?php

declare(strict_types=1);

namespace App\Tests\Domain\AI;

use App\Domain\AI\AiCommerceReadContract;
use App\Domain\AI\AiToolPolicy;
use DomainException;
use PHPUnit\Framework\TestCase;

final class AiCommerceReadContractTest extends TestCase
{
    public function testReadOnlyDescriptorsAndCanonicalShapes(): void
    {
        $policy = new AiToolPolicy();
        $before = $policy->allowlist();
        $contract = new AiCommerceReadContract($policy);

        self::assertSame('catalog.read', $contract->catalogDescriptor()->tool());
        self::assertSame('inventory.read', $contract->inventoryDescriptor()->tool());
        self::assertSame(AiToolPolicy::READ_ONLY, $contract->catalogDescriptor()->risk());
        self::assertSame(AiToolPolicy::READ_ONLY, $contract->inventoryDescriptor()->risk());
        self::assertSame($before, $policy->allowlist());

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
        self::addToAssertionCount(4);
    }

    public function testInvalidCommercialShapesFailClosed(): void
    {
        $contract = new AiCommerceReadContract(new AiToolPolicy());
        $cases = [
            fn () => $contract->validateCatalogInputs(['product_ref' => 'SKU 42']),
            fn () => $contract->validateCatalogInputs([
                'product_ref' => 'product:sku_42',
                'query' => 'camiseta negra',
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
                'amount' => 10,
                'status' => 'unavailable',
            ]),
            fn () => $contract->validateInventoryOutputs([
                'product_ref' => 'product:sku_42',
                'location_ref' => 'location:main',
                'quantity' => 0,
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
