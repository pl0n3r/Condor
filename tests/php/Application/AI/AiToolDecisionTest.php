<?php

declare(strict_types=1);

namespace App\Tests\Application\AI;

use App\Application\AI\AiToolDecision;
use App\Domain\AI\AiTenantContext;
use App\Domain\AI\AiToolPolicy;
use PHPUnit\Framework\TestCase;

final class AiToolDecisionTest extends TestCase
{
    public function testAuthorizedReadOnlyAndReversibleRequestsRequireMatchingPolicyAndContext(): void
    {
        $policy = new AiToolPolicy();

        foreach ([
            ['catalog.read', AiToolPolicy::READ_ONLY],
            ['content.draft.update', AiToolPolicy::REVERSIBLE_WRITE],
        ] as [$tool, $risk]) {
            $context = AiTenantContext::fromArray([
                'tenant_id' => 'tenant-a',
                'tool' => $tool,
                'knowledge_refs' => [],
            ], $policy);

            $decision = AiToolDecision::decide(
                $context,
                $policy,
                [
                    'tenant_id' => 'tenant-a',
                    'tool' => $tool,
                    'request_ref' => 'request:decision-' . str_replace('.', '-', $tool),
                ],
            );

            self::assertSame('authorized', $decision['status']);
            self::assertSame('policy_allows', $decision['reason']);
            self::assertFalse($decision['executed']);
            self::assertSame($risk, $decision['risk']);
            self::assertSame('tenant:tenant-a', $decision['request']['tenant_ref']);
            self::assertSame($tool, $decision['request']['tool_ref']);
        }
    }

    public function testSensitiveUnknownOrContextMismatchIsDeniedWithoutExecution(): void
    {
        $policy = new AiToolPolicy();

        $sensitiveContext = AiTenantContext::fromArray([
            'tenant_id' => 'tenant-a',
            'tool' => 'identity.permission.change',
            'knowledge_refs' => [],
        ], $policy);
        $sensitive = AiToolDecision::decide(
            $sensitiveContext,
            $policy,
            [
                'tenant_id' => 'tenant-a',
                'tool' => 'identity.permission.change',
                'request_ref' => 'request:sensitive',
            ],
        );
        self::assertSame('denied', $sensitive['status']);
        self::assertSame('sensitive_requires_human', $sensitive['reason']);
        self::assertFalse($sensitive['executed']);

        $readContext = AiTenantContext::fromArray([
            'tenant_id' => 'tenant-a',
            'tool' => 'catalog.read',
            'knowledge_refs' => [],
        ], $policy);

        foreach ([
            [
                'tenant_id' => 'tenant-a',
                'tool' => 'unknown.read',
                'request_ref' => 'request:unknown',
            ],
            [
                'tenant_id' => 'tenant-b',
                'tool' => 'catalog.read',
                'request_ref' => 'request:cross-tenant',
            ],
            [
                'tenant_id' => 'tenant-a',
                'tool' => 'inventory.read',
                'request_ref' => 'request:tool-mismatch',
            ],
        ] as $request) {
            $decision = AiToolDecision::decide($readContext, $policy, $request);
            self::assertSame('denied', $decision['status']);
            self::assertSame('request_invalid', $decision['reason']);
            self::assertFalse($decision['executed']);
            self::assertNull($decision['request']);
        }
    }
}
