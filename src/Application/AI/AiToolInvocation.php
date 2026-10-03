<?php

declare(strict_types=1);

namespace App\Application\AI;

use App\Domain\AI\AiAuditEnvelope;
use App\Domain\AI\AiTenantContext;
use App\Domain\AI\AiToolPolicy;
use DomainException;
use Throwable;

final class AiToolInvocation
{
    /**
     * @param callable():mixed $executor
     * @return array{
     *     outcome:'success'|'denied'|'failure',
     *     executed:bool,
     *     evidence_ref:string,
     *     audit:array{
     *         tenant_ref:string,
     *         tool_ref:string,
     *         outcome:string,
     *         evidence_ref:string,
     *         timestamp:string
     *     }
     * }
     */
    public static function invoke(
        AiTenantContext $context,
        AiToolPolicy $policy,
        string $requestedTenantId,
        string $requestedTool,
        string $evidenceRef,
        string $timestamp,
        callable $executor,
    ): array {
        if (!self::tenantMatches($context, $requestedTenantId)) {
            return self::result($context, $policy, 'denied', false, $evidenceRef, $timestamp);
        }

        if (!self::toolMatchesAndIsAutonomous($context, $policy, $requestedTool)) {
            return self::result($context, $policy, 'denied', false, $evidenceRef, $timestamp);
        }

        try {
            $executor();
        } catch (Throwable) {
            return self::result($context, $policy, 'failure', true, $evidenceRef, $timestamp);
        }

        return self::result($context, $policy, 'success', true, $evidenceRef, $timestamp);
    }

    private static function tenantMatches(
        AiTenantContext $context,
        string $requestedTenantId,
    ): bool {
        try {
            $context->rebindTenant($requestedTenantId);

            return true;
        } catch (DomainException) {
            return false;
        }
    }

    private static function toolMatchesAndIsAutonomous(
        AiTenantContext $context,
        AiToolPolicy $policy,
        string $requestedTool,
    ): bool {
        $requestedTool = strtolower(trim($requestedTool));
        $contextTool = strtolower(trim($context->tool()));

        if ($requestedTool !== $contextTool) {
            return false;
        }

        try {
            return $policy->allowsAutonomousExecution($requestedTool)
                && $policy->risk($requestedTool) !== AiToolPolicy::SENSITIVE;
        } catch (DomainException) {
            return false;
        }
    }

    /**
     * @param 'success'|'denied'|'failure' $outcome
     * @return array{
     *     outcome:'success'|'denied'|'failure',
     *     executed:bool,
     *     evidence_ref:string,
     *     audit:array{
     *         tenant_ref:string,
     *         tool_ref:string,
     *         outcome:string,
     *         evidence_ref:string,
     *         timestamp:string
     *     }
     * }
     */
    private static function result(
        AiTenantContext $context,
        AiToolPolicy $policy,
        string $outcome,
        bool $executed,
        string $evidenceRef,
        string $timestamp,
    ): array {
        $audit = AiAuditEnvelope::fromArray(
            [
                'tenant_ref' => 'tenant:' . $context->tenantId(),
                'tool_ref' => $context->tool(),
                'outcome' => $outcome,
                'evidence_ref' => $evidenceRef,
                'timestamp' => $timestamp,
            ],
            $policy,
        );

        return [
            'outcome' => $outcome,
            'executed' => $executed,
            'evidence_ref' => $audit->evidenceRef(),
            'audit' => $audit->snapshot(),
        ];
    }
}
