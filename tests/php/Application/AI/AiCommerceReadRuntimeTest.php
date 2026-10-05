<?php

declare(strict_types=1);

namespace App\Tests\Application\AI;

use App\Application\AI\AiCommerceReadRuntime;
use App\Domain\AI\AiTenantContext;
use App\Domain\AI\AiToolPolicy;
use DateTimeImmutable;
use PHPUnit\Framework\TestCase;

final class AiCommerceReadRuntimeTest extends TestCase
{
    public function testCatalogAndInventoryLookupFlowThroughExistingConversationCoreWithMinimizedReceipt(): void
    {
        $policy = new AiToolPolicy();
        $handlers = self::validHandlers();

        foreach ([
            'catalog.read' => [
                'inputs' => ['product_ref' => 'product:sku_42'],
                'result' => [
                    'product_ref' => 'product:sku_42',
                    'currency' => 'COP',
                    'amount' => 19900.50,
                    'status' => 'available',
                ],
            ],
            'inventory.read' => [
                'inputs' => ['product_ref' => 'product:sku_42', 'branch_ref' => 'branch:main'],
                'result' => [
                    'product_ref' => 'product:sku_42',
                    'branch_ref' => 'branch:main',
                    'quantity' => 12.500,
                    'status' => 'in_stock',
                ],
            ],
        ] as $tool => $case) {
            $result = AiCommerceReadRuntime::turn(
                self::context($policy, $tool),
                $policy,
                self::turn($tool, $case['inputs']),
                new DateTimeImmutable('2026-10-05T12:00:00Z'),
                $handlers,
            );

            self::assertSame('completed', $result['status']);
            self::assertSame('tool', $result['route']);
            self::assertTrue($result['executed']);
            self::assertSame($case['result'], $result['tool_result']);
            self::assertSame('authorized', $result['receipt']['decision']);
            self::assertSame(AiToolPolicy::READ_ONLY, $result['receipt']['risk']);
            self::assertSame('success', $result['receipt']['outcome']);
            self::assertSame('tenant:tenant-a', $result['receipt']['tenant_ref']);
            self::assertSame($tool, $result['receipt']['tool_ref']);
            self::assertArrayNotHasKey('tool_result', $result['receipt']);
            self::assertArrayNotHasKey('inputs', $result['receipt']);
        }
    }

    public function testCrossTenantUnknownToolOrInvalidContractHandoffBeforeAcceptingResult(): void
    {
        $policy = new AiToolPolicy();
        $calls = 0;
        $handlers = self::countedHandlers($calls);
        $context = self::context($policy, 'catalog.read');
        $at = new DateTimeImmutable('2026-10-05T12:00:00Z');

        $crossTenant = AiCommerceReadRuntime::turn(
            $context,
            $policy,
            array_replace(self::turn('catalog.read', ['product_ref' => 'product:sku_42']), ['tenant_id' => 'tenant-b']),
            $at,
            $handlers,
        );
        self::assertSame('handoff', $crossTenant['status']);
        self::assertSame('tenant_context_mismatch', $crossTenant['reason']);

        $unknown = AiCommerceReadRuntime::turn(
            $context,
            $policy,
            self::turn('unknown.read', []),
            $at,
            $handlers,
        );
        self::assertSame('denied', $unknown['status']);
        self::assertSame('tool_request_denied', $unknown['reason']);

        $invalidInput = AiCommerceReadRuntime::turn(
            $context,
            $policy,
            self::turn('catalog.read', ['product_ref' => 'SKU 42']),
            $at,
            $handlers,
        );
        self::assertSame('handoff', $invalidInput['status']);
        self::assertSame('tool_inputs_invalid', $invalidInput['reason']);
        self::assertSame(0, $calls);

        $missingHandler = AiCommerceReadRuntime::turn(
            $context,
            $policy,
            self::turn('catalog.read', ['product_ref' => 'product:sku_42']),
            $at,
            ['catalog.read' => $handlers['catalog.read']],
        );
        self::assertSame('handoff', $missingHandler['status']);
        self::assertSame('tool_handler_unavailable', $missingHandler['reason']);
        self::assertSame(0, $calls);
    }

    public function testReadOnlyCommercePathNeverRequiresOrAcquiresWriteAuthority(): void
    {
        $policy = new AiToolPolicy();
        $calls = 0;
        $handlers = self::countedHandlers($calls);
        $turn = self::turn('catalog.read', ['product_ref' => 'product:sku_42']);
        $context = self::context($policy, 'catalog.read');
        $at = new DateTimeImmutable('2026-10-05T12:00:00Z');

        $first = AiCommerceReadRuntime::turn($context, $policy, $turn, $at, $handlers);
        $second = AiCommerceReadRuntime::turn($context, $policy, $turn, $at, $handlers);

        self::assertSame('completed', $first['status']);
        self::assertSame('completed', $second['status']);
        self::assertSame(AiToolPolicy::READ_ONLY, $first['receipt']['risk']);
        self::assertSame(AiToolPolicy::READ_ONLY, $second['receipt']['risk']);
        self::assertSame(2, $calls);

        $serialized = json_encode([$first, $second], JSON_THROW_ON_ERROR);
        self::assertStringNotContainsString('replay', strtolower($serialized));
        self::assertStringNotContainsString('reversible_write', $serialized);
        self::assertStringNotContainsString('write', strtolower($serialized));
    }

    private static function context(AiToolPolicy $policy, string $tool): AiTenantContext
    {
        return AiTenantContext::fromArray([
            'tenant_id' => 'tenant-a',
            'tool' => $tool,
            'knowledge_refs' => [],
        ], $policy);
    }

    /** @param array<string, mixed> $inputs @return array<string, mixed> */
    private static function turn(string $tool, array $inputs): array
    {
        return [
            'intent' => 'tool',
            'tenant_id' => 'tenant-a',
            'tool' => $tool,
            'inputs' => $inputs,
            'request_ref' => 'request:commerce-runtime',
            'evidence_ref' => 'evidence:commerce-runtime',
            'timestamp' => '2026-10-05T12:00:00+00:00',
        ];
    }

    /** @return array<string, \Closure> */
    private static function validHandlers(): array
    {
        $calls = 0;

        return self::countedHandlers($calls);
    }

    /** @return array<string, \Closure> */
    private static function countedHandlers(int &$calls): array
    {
        return [
            'catalog.read' => static function (array $inputs) use (&$calls): array {
                ++$calls;

                return [
                    'product_ref' => $inputs['product_ref'],
                    'currency' => 'COP',
                    'amount' => 19900.50,
                    'status' => 'available',
                ];
            },
            'inventory.read' => static function (array $inputs) use (&$calls): array {
                ++$calls;

                return [
                    'product_ref' => $inputs['product_ref'],
                    'branch_ref' => $inputs['branch_ref'] ?? null,
                    'quantity' => 12.500,
                    'status' => 'in_stock',
                ];
            },
        ];
    }
}
