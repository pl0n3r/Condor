<?php

declare(strict_types=1);

namespace App\Application\AI;

use App\Domain\AI\AiAuditEnvelope;
use App\Domain\AI\AiTenantContext;
use App\Domain\AI\AiToolDescriptor;
use App\Domain\AI\AiToolPolicy;
use DomainException;
use Throwable;

final class AiToolInvocation
{
    /**
     * @param callable():mixed|callable(array<string, mixed>):mixed $executor
     * @param array<string, mixed> $inputs
     * @return array{
     *     outcome:'success'|'denied'|'failure',
     *     executed:bool,
     *     evidence_ref:string,
     *     tool_result?:array<string, string|int|float|bool|null>,
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
        AiToolDescriptor $descriptor,
        array $inputs = [],
    ): array {
        self::assertAuditMetadataValid($context, $policy, $evidenceRef, $timestamp);

        if (!self::tenantMatches($context, $requestedTenantId)) {
            return self::result($context, $policy, 'denied', false, $evidenceRef, $timestamp);
        }

        if (!self::toolMatchesAndIsAutonomous($context, $policy, $requestedTool)) {
            return self::result($context, $policy, 'denied', false, $evidenceRef, $timestamp);
        }

        self::assertInputBindingValid(
            $context,
            $policy,
            $requestedTool,
            $descriptor,
            $inputs,
        );

        try {
            $handlerResult = $executor($inputs);

            if ($descriptor->outputNames() === []) {
                return self::result(
                    $context,
                    $policy,
                    'success',
                    true,
                    $evidenceRef,
                    $timestamp,
                );
            }

            if (!is_array($handlerResult)) {
                return self::result(
                    $context,
                    $policy,
                    'failure',
                    true,
                    $evidenceRef,
                    $timestamp,
                );
            }

            $descriptor->validateOutputs($handlerResult);
        } catch (Throwable) {
            return self::result($context, $policy, 'failure', true, $evidenceRef, $timestamp);
        }

        return self::result(
            $context,
            $policy,
            'success',
            true,
            $evidenceRef,
            $timestamp,
            $handlerResult,
        );
    }

    /**
     * @param array<string, mixed> $inputs
     */
    private static function assertInputBindingValid(
        AiTenantContext $context,
        AiToolPolicy $policy,
        string $requestedTool,
        AiToolDescriptor $descriptor,
        array $inputs,
    ): void {
        $canonicalTool = strtolower(trim($requestedTool));
        if (
            $descriptor->tool() !== $canonicalTool
            || $descriptor->tool() !== $context->tool()
            || $descriptor->risk() !== $policy->risk($canonicalTool)
        ) {
            throw new DomainException('Descriptor de inputs no corresponde a la tool solicitada.');
        }

        $descriptor->validateInputs($inputs);
    }

    private static function assertAuditMetadataValid(
        AiTenantContext $context,
        AiToolPolicy $policy,
        string $evidenceRef,
        string $timestamp,
    ): void {
        AiAuditEnvelope::fromArray(
            [
                'tenant_ref' => 'tenant:' . $context->tenantId(),
                'tool_ref' => $context->tool(),
                'outcome' => 'denied',
                'evidence_ref' => $evidenceRef,
                'timestamp' => $timestamp,
            ],
            $policy,
        );
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
     * @param array<string, string|int|float|bool|null>|null $toolResult
     * @return array{
     *     outcome:'success'|'denied'|'failure',
     *     executed:bool,
     *     evidence_ref:string,
     *     tool_result?:array<string, string|int|float|bool|null>,
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
        ?array $toolResult = null,
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

        $result = [
            'outcome' => $outcome,
            'executed' => $executed,
            'evidence_ref' => $audit->evidenceRef(),
            'audit' => $audit->snapshot(),
        ];

        if ($toolResult !== null) {
            $result['tool_result'] = $toolResult;
        }

        return $result;
    }
}
