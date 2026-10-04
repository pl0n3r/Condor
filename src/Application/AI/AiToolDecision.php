<?php

declare(strict_types=1);

namespace App\Application\AI;

use App\Domain\AI\AiTenantContext;
use App\Domain\AI\AiToolPolicy;
use DomainException;

final class AiToolDecision
{
    /**
     * @param array<string, mixed> $request
     * @return array{
     *     status:'authorized'|'denied',
     *     reason:'policy_allows'|'sensitive_requires_human'|'request_invalid',
     *     executed:false,
     *     risk:'read_only'|'reversible_write'|'sensitive'|null,
     *     request:array{tenant_ref:string,tool_ref:string,request_ref:string}|null
     * }
     */
    public static function decide(
        AiTenantContext $context,
        AiToolPolicy $policy,
        array $request,
    ): array {
        try {
            $canonical = AiToolRequest::fromArray($context, $policy, $request);
            $risk = $policy->risk($canonical->tool());
        } catch (DomainException) {
            return self::denied('request_invalid');
        }

        if ($risk === AiToolPolicy::SENSITIVE) {
            return self::denied(
                'sensitive_requires_human',
                $risk,
                $canonical->snapshot(),
            );
        }

        return [
            'status' => 'authorized',
            'reason' => 'policy_allows',
            'executed' => false,
            'risk' => $risk,
            'request' => $canonical->snapshot(),
        ];
    }

    /**
     * @param 'sensitive_requires_human'|'request_invalid' $reason
     * @param 'read_only'|'reversible_write'|'sensitive'|null $risk
     * @param array{tenant_ref:string,tool_ref:string,request_ref:string}|null $request
     * @return array{
     *     status:'denied',
     *     reason:'sensitive_requires_human'|'request_invalid',
     *     executed:false,
     *     risk:'read_only'|'reversible_write'|'sensitive'|null,
     *     request:array{tenant_ref:string,tool_ref:string,request_ref:string}|null
     * }
     */
    private static function denied(
        string $reason,
        ?string $risk = null,
        ?array $request = null,
    ): array {
        return [
            'status' => 'denied',
            'reason' => $reason,
            'executed' => false,
            'risk' => $risk,
            'request' => $request,
        ];
    }
}
