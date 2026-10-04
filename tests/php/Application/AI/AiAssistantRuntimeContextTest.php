<?php

declare(strict_types=1);

namespace App\Tests\Application\AI;

use App\Application\AI\AiAssistantRuntimeContext;
use App\Domain\AI\AiAssistantPolicyBinding;
use App\Domain\AI\AiAssistantProfile;
use App\Domain\AI\AiToolPolicy;
use DomainException;
use PHPUnit\Framework\TestCase;

final class AiAssistantRuntimeContextTest extends TestCase
{
    public function testBoundToolResolvesExistingTenantContextWithExactKnowledgeScope(): void
    {
        $policy = new AiToolPolicy();
        $profile = self::profile('tenant-a', 'assistant:primary');
        $binding = self::binding($profile, $policy);

        $context = AiAssistantRuntimeContext::resolve(
            $profile,
            $binding,
            $policy,
            ' KNOWLEDGE.READ ',
        );

        self::assertSame('tenant-a', $context->tenantId());
        self::assertSame('knowledge.read', $context->tool());
        self::assertSame(
            ['knowledge:guide', 'knowledge:returns'],
            $context->knowledgeRefs(),
        );
        self::assertSame(
            [
                'tenant_id' => 'tenant-a',
                'tool' => 'knowledge.read',
                'knowledge_refs' => ['knowledge:guide', 'knowledge:returns'],
            ],
            $context->snapshot(),
        );
    }

    public function testUnboundSensitiveCrossTenantOrIncoherentContextFailsClosedWithoutExecution(): void
    {
        $policy = new AiToolPolicy();
        $profile = self::profile('tenant-a', 'assistant:primary');
        $binding = self::binding($profile, $policy);

        $cases = [
            [self::profile('tenant-a', 'assistant:primary'), 'inventory.read'],
            [self::profile('tenant-a', 'assistant:primary'), 'identity.permission.change'],
            [self::profile('tenant-b', 'assistant:primary'), 'knowledge.read'],
            [self::profile('tenant-a', 'assistant:other'), 'knowledge.read'],
            [self::profile('tenant-a', 'assistant:primary'), 'unknown.read'],
        ];

        foreach ($cases as [$candidateProfile, $tool]) {
            try {
                AiAssistantRuntimeContext::resolve(
                    $candidateProfile,
                    $binding,
                    $policy,
                    $tool,
                );
                self::fail('El contexto inválido debía fallar cerrado.');
            } catch (DomainException) {
                self::addToAssertionCount(1);
            }
        }
    }

    private static function profile(string $tenantId, string $assistantRef): AiAssistantProfile
    {
        return AiAssistantProfile::fromArray([
            'tenant_id' => $tenantId,
            'assistant_ref' => $assistantRef,
            'goals' => ['support'],
            'tone' => 'neutral',
            'handoff_mode' => 'required_on_unknown',
        ]);
    }

    private static function binding(
        AiAssistantProfile $profile,
        AiToolPolicy $policy,
    ): AiAssistantPolicyBinding {
        return AiAssistantPolicyBinding::fromArray(
            $profile,
            $policy,
            [
                'tenant_id' => 'tenant-a',
                'assistant_ref' => 'assistant:primary',
                'tools' => ['catalog.read', 'knowledge.read'],
                'knowledge_refs' => ['knowledge:returns', 'knowledge:guide'],
            ],
        );
    }
}
