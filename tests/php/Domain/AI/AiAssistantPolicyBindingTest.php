<?php

declare(strict_types=1);

namespace App\Tests\Domain\AI;

use App\Domain\AI\AiAssistantPolicyBinding;
use App\Domain\AI\AiAssistantProfile;
use App\Domain\AI\AiToolPolicy;
use DomainException;
use PHPUnit\Framework\TestCase;

final class AiAssistantPolicyBindingTest extends TestCase
{
    public function testBindingAcceptsOnlySameTenantAllowlistedNonSensitiveToolsAndKnowledge(): void
    {
        $policy = new AiToolPolicy();
        $profile = self::profile();

        $binding = AiAssistantPolicyBinding::fromArray(
            $profile,
            $policy,
            [
                'tenant_id' => 'tenant-a',
                'assistant_ref' => 'assistant:primary',
                'tools' => [
                    'settings.draft.update',
                    'knowledge.read',
                    'catalog.read',
                ],
                'knowledge_refs' => [
                    'knowledge:zeta',
                    'knowledge:guide',
                ],
            ],
        );

        self::assertSame('tenant:tenant-a', $binding->tenantRef());
        self::assertSame('assistant:primary', $binding->assistantRef());
        self::assertSame(
            ['catalog.read', 'knowledge.read', 'settings.draft.update'],
            $binding->toolRefs(),
        );
        self::assertSame(
            ['knowledge:guide', 'knowledge:zeta'],
            $binding->knowledgeRefs(),
        );
        self::assertSame(
            [
                'tenant_ref' => 'tenant:tenant-a',
                'assistant_ref' => 'assistant:primary',
                'tool_refs' => [
                    'catalog.read',
                    'knowledge.read',
                    'settings.draft.update',
                ],
                'knowledge_refs' => [
                    'knowledge:guide',
                    'knowledge:zeta',
                ],
            ],
            $binding->snapshot(),
        );
    }

    public function testCrossTenantUnknownSensitiveDuplicateOrExtraBindingFailsClosed(): void
    {
        $policy = new AiToolPolicy();
        $profile = self::profile();
        $base = [
            'tenant_id' => 'tenant-a',
            'assistant_ref' => 'assistant:primary',
            'tools' => ['catalog.read'],
            'knowledge_refs' => ['knowledge:guide'],
        ];

        $cases = [
            array_replace($base, ['tenant_id' => 'tenant-b']),
            array_replace($base, ['assistant_ref' => 'assistant:other']),
            array_replace($base, ['tools' => ['unknown.read']]),
            array_replace($base, ['tools' => ['identity.permission.change']]),
            array_replace($base, ['tools' => ['catalog.read', 'catalog.read']]),
            array_replace($base, ['knowledge_refs' => ['knowledge:guide', 'knowledge:guide']]),
            array_replace($base, ['knowledge_refs' => ['person@example.test']]),
            $base + ['prompt' => 'free form'],
            $base + ['provider' => 'external'],
        ];

        foreach ($cases as $case) {
            try {
                AiAssistantPolicyBinding::fromArray($profile, $policy, $case);
                self::fail('El binding inválido debía fallar cerrado.');
            } catch (DomainException) {
                self::addToAssertionCount(1);
            }
        }
    }

    private static function profile(): AiAssistantProfile
    {
        return AiAssistantProfile::fromArray([
            'tenant_id' => 'tenant-a',
            'assistant_ref' => 'assistant:primary',
            'goals' => ['support', 'sales'],
            'tone' => 'neutral',
            'handoff_mode' => 'required_on_unknown',
        ]);
    }
}
