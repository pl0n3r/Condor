<?php

declare(strict_types=1);

namespace App\Tests\Application\AI;

use App\Application\AI\AiToolReplayKey;
use App\Application\AI\AiToolRequest;
use App\Domain\AI\AiTenantContext;
use App\Domain\AI\AiToolPolicy;
use PHPUnit\Framework\TestCase;

final class AiToolReplayKeyTest extends TestCase
{
    public function testSameCanonicalRequestProducesStableOpaqueReplayKey(): void
    {
        $policy = new AiToolPolicy();
        $context = $this->context($policy, 'tenant-a', 'content.draft.update');

        $first = AiToolReplayKey::fromRequest(
            $this->request(
                $context,
                $policy,
                'tenant-a',
                'content.draft.update',
                'request:draft-001',
            ),
            $context,
            $policy,
        );
        $second = AiToolReplayKey::fromRequest(
            $this->request(
                $context,
                $policy,
                'tenant-a',
                'CONTENT.DRAFT.UPDATE',
                'request:draft-001',
            ),
            $context,
            $policy,
        );

        self::assertSame($first->value(), $second->value());
        self::assertMatchesRegularExpression('/^replay:[a-f0-9]{64}$/D', $first->value());
        self::assertSame($first->value(), (string) $first);
        self::assertStringNotContainsString('tenant-a', $first->value());
        self::assertStringNotContainsString('content.draft.update', $first->value());
        self::assertStringNotContainsString('request:draft-001', $first->value());
    }

    public function testTenantToolOrRequestChangeProducesDistinctKeyWithoutPayloadLeak(): void
    {
        $policy = new AiToolPolicy();

        $cases = [
            ['tenant-a', 'content.draft.update', 'request:draft-001'],
            ['tenant-b', 'content.draft.update', 'request:draft-001'],
            ['tenant-a', 'settings.draft.update', 'request:draft-001'],
            ['tenant-a', 'content.draft.update', 'request:draft-002'],
        ];

        $keys = [];
        foreach ($cases as [$tenantId, $tool, $requestRef]) {
            $context = $this->context($policy, $tenantId, $tool);
            $key = AiToolReplayKey::fromRequest(
                $this->request($context, $policy, $tenantId, $tool, $requestRef),
                $context,
                $policy,
            )->value();

            self::assertMatchesRegularExpression('/^replay:[a-f0-9]{64}$/D', $key);
            self::assertStringNotContainsString($tenantId, $key);
            self::assertStringNotContainsString($tool, $key);
            self::assertStringNotContainsString($requestRef, $key);
            $keys[] = $key;
        }

        self::assertCount(count($keys), array_unique($keys));
    }

    private function context(
        AiToolPolicy $policy,
        string $tenantId,
        string $tool,
    ): AiTenantContext {
        return AiTenantContext::fromArray([
            'tenant_id' => $tenantId,
            'tool' => $tool,
            'knowledge_refs' => ['knowledge:catalog-public'],
        ], $policy);
    }

    private function request(
        AiTenantContext $context,
        AiToolPolicy $policy,
        string $tenantId,
        string $tool,
        string $requestRef,
    ): AiToolRequest {
        return AiToolRequest::fromArray(
            $context,
            $policy,
            [
                'tenant_id' => $tenantId,
                'tool' => $tool,
                'request_ref' => $requestRef,
            ],
        );
    }
}
