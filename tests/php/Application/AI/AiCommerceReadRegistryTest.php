<?php

declare(strict_types=1);

namespace App\Tests\Application\AI;

use App\Application\AI\AiCommerceReadRegistry;
use App\Domain\AI\AiToolPolicy;
use Closure;
use DomainException;
use PHPUnit\Framework\TestCase;

final class AiCommerceReadRegistryTest extends TestCase
{
    public function testRegistryBindsOnlyCatalogAndInventoryReadWithInjectedHandlers(): void
    {
        $policy = new AiToolPolicy();
        $before = $policy->allowlist();
        $registry = AiCommerceReadRegistry::fromArray($policy, self::validHandlers());

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
        self::assertSame($before, $policy->allowlist());
    }

    public function testInvalidInputsFailBeforeHandlerAndInvalidOutputsFailClosed(): void
    {
        $registry = AiCommerceReadRegistry::fromArray(
            new AiToolPolicy(),
            [
                'catalog.read' => static fn (array $inputs): array => [
                    'product_ref' => $inputs['product_ref'],
                    'currency' => 'BTC',
                    'amount' => 10,
                    'status' => 'available',
                ],
                'inventory.read' => static fn (array $inputs): array => [
                    'product_ref' => $inputs['product_ref'],
                    'branch_ref' => $inputs['branch_ref'] ?? null,
                    'quantity' => 0,
                    'status' => 'in_stock',
                ],
            ],
        );

        $this->assertDomainFailure(
            static fn (): array => $registry->execute(
                'catalog.read',
                ['product_ref' => 'SKU 42'],
            ),
        );
        $this->assertDomainFailure(
            static fn (): array => $registry->execute(
                'catalog.read',
                ['product_ref' => 'product:sku_42'],
            ),
        );
        $this->assertDomainFailure(
            static fn (): array => $registry->execute(
                'inventory.read',
                ['product_ref' => 'product:sku_42', 'branch_ref' => 'branch:main'],
            ),
        );
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
            $this->assertDomainFailure(
                static fn (): AiCommerceReadRegistry => AiCommerceReadRegistry::fromArray(
                    $policy,
                    $handlers,
                ),
            );
        }
    }

    public function testBindingIsDeterministicAndPreservesExistingToolPolicy(): void
    {
        $policy = new AiToolPolicy();
        $before = $policy->allowlist();
        $handlers = self::validHandlers();
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
            $this->assertDomainFailure(
                static fn (): object => $first->descriptor($tool),
            );
        }
    }

    /** @return array<string, Closure> */
    private static function validHandlers(): array
    {
        return [
            'catalog.read' => static fn (array $inputs): array => [
                'product_ref' => $inputs['product_ref'],
                'currency' => 'COP',
                'amount' => 19900.50,
                'status' => 'available',
            ],
            'inventory.read' => static fn (array $inputs): array => [
                'product_ref' => $inputs['product_ref'],
                'branch_ref' => $inputs['branch_ref'] ?? null,
                'quantity' => 12.500,
                'status' => 'in_stock',
            ],
        ];
    }

    private function assertDomainFailure(Closure $operation): void
    {
        try {
            $operation();
            self::fail('La operación comercial inválida debía fallar cerrado.');
        } catch (DomainException) {
            self::addToAssertionCount(1);
        }
    }
}
