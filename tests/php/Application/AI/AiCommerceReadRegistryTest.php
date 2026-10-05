<?php

declare(strict_types=1);

namespace App\Tests\Application\AI;

use App\Application\AI\AiCommerceReadRegistry;
use App\Domain\AI\AiToolPolicy;
use DomainException;
use PHPUnit\Framework\TestCase;

final class AiCommerceReadRegistryTest extends TestCase
{
    public function testRegistryBindsOnlyCatalogAndInventoryReadWithInjectedHandlers(): void
    {
        $policy = new AiToolPolicy();
        $before = $policy->allowlist();
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

        self::assertSame('catalog.read', $registry->descriptor('catalog.read')->tool());
        self::assertSame('inventory.read', $registry->descriptor('inventory.read')->tool());
        self::assertSame(AiToolPolicy::READ_ONLY, $registry->descriptor('catalog.read')->risk());
        self::assertSame(AiToolPolicy::READ_ONLY, $registry->descriptor('inventory.read')->risk());

        self::assertSame(
            [
                'product_ref' => 'product:sku_42',
                'currency' => 'COP',
                'amount' => 19900.50,
                'status' => 'available',
            ],
            $registry->execute(
                'catalog.read',
                [
                    'product_ref' => 'product:sku_42',
                    'price_list_ref' => 'price_list:wholesale',
                ],
            ),
        );
        self::assertSame(
            [
                'product_ref' => 'product:sku_42',
                'branch_ref' => 'branch:main',
                'quantity' => 12.500,
                'status' => 'in_stock',
            ],
            $registry->execute(
                'inventory.read',
                [
                    'product_ref' => 'product:sku_42',
                    'branch_ref' => 'branch:main',
                ],
            ),
        );

        self::assertSame(1, $catalogCalls);
        self::assertSame(1, $inventoryCalls);
        self::assertSame($before, $policy->allowlist());
    }

    public function testInvalidInputsFailBeforeHandlerAndInvalidOutputsFailClosed(): void
    {
        $policy = new AiToolPolicy();
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

        try {
            $registry->execute('catalog.read', ['product_ref' => 'SKU 42']);
            self::fail('Input comercial inválido debía fallar antes del handler.');
        } catch (DomainException) {
            self::addToAssertionCount(1);
        }
        self::assertSame(0, $catalogCalls);

        try {
            $registry->execute('catalog.read', ['product_ref' => 'product:sku_42']);
            self::fail('Output catalog inválido debía fallar cerrado.');
        } catch (DomainException) {
            self::addToAssertionCount(1);
        }
        self::assertSame(1, $catalogCalls);

        try {
            $registry->execute(
                'inventory.read',
                ['product_ref' => 'product:sku_42', 'branch_ref' => 'branch:main'],
            );
            self::fail('Output inventory inválido debía fallar cerrado.');
        } catch (DomainException) {
            self::addToAssertionCount(1);
        }
        self::assertSame(1, $inventoryCalls);
    }

    public function testMissingExtraOrNonClosureHandlersFailClosed(): void
    {
        $policy = new AiToolPolicy();
        $valid = static fn (array $inputs): array => $inputs;

        $cases = [
            ['catalog.read' => $valid],
            [
                'catalog.read' => $valid,
                'inventory.read' => $valid,
                'knowledge.read' => $valid,
            ],
            [
                'catalog.read' => 'strlen',
                'inventory.read' => $valid,
            ],
        ];

        foreach ($cases as $handlers) {
            try {
                AiCommerceReadRegistry::fromArray($policy, $handlers);
                self::fail('Handlers comerciales inválidos debían fallar cerrado.');
            } catch (DomainException) {
                self::addToAssertionCount(1);
            }
        }
    }

    public function testBindingIsDeterministicAndPreservesExistingToolPolicy(): void
    {
        $policy = new AiToolPolicy();
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

        self::assertSame(
            $first->descriptor('catalog.read')->snapshot(),
            $second->descriptor('catalog.read')->snapshot(),
        );
        self::assertSame(
            $first->descriptor('inventory.read')->snapshot(),
            $second->descriptor('inventory.read')->snapshot(),
        );
        self::assertSame($before, $policy->allowlist());

        foreach (['knowledge.read', 'Catalog.Read', 'unknown.read'] as $tool) {
            try {
                $first->descriptor($tool);
                self::fail('Tool fuera del registry comercial debía fallar cerrado.');
            } catch (DomainException) {
                self::addToAssertionCount(1);
            }
        }
    }
}
