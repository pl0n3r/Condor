<?php

declare(strict_types=1);

namespace App\Application\AI;

use App\Domain\AI\AiAssistantPolicyBinding;
use App\Domain\AI\AiAssistantProfile;
use App\Domain\AI\AiTenantContext;
use App\Domain\AI\AiToolPolicy;
use DomainException;

final class AiAssistantRuntimeContext
{
    public static function resolve(
        AiAssistantProfile $profile,
        AiAssistantPolicyBinding $binding,
        AiToolPolicy $policy,
        string $tool,
    ): AiTenantContext {
        if (
            $binding->tenantRef() !== 'tenant:' . $profile->tenantId()
            || $binding->assistantRef() !== $profile->assistantRef()
        ) {
            throw new DomainException('Profile y binding de asistente IA no son coherentes.');
        }

        $tool = strtolower(trim($tool));
        $risk = $policy->risk($tool);
        if ($risk === AiToolPolicy::SENSITIVE) {
            throw new DomainException('Tool sensible no puede resolverse autónomamente.');
        }

        if (!in_array($tool, $binding->toolRefs(), true)) {
            throw new DomainException('Tool no vinculada al asistente IA.');
        }

        return AiTenantContext::fromArray(
            [
                'tenant_id' => $profile->tenantId(),
                'tool' => $tool,
                'knowledge_refs' => $binding->knowledgeRefs(),
            ],
            $policy,
        );
    }
}
