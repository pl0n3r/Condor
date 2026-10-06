<?php

declare(strict_types=1);

namespace App\Application\AI;

use App\Domain\AI\AiTenantContext;
use App\Domain\AI\AiToolPolicy;
use DomainException;

final readonly class AiSensitiveHandoffDecision
{
    private function __construct(
        private AiSensitiveHandoffRequest $request,
    ) {
    }

    /**
     * @param array<string, mixed> $decision
     */
    public static function fromDecision(
        AiTenantContext $context,
        AiToolPolicy $policy,
        array $decision,
        mixed $handoffRequest,
    ): self {
        $keys = array_keys($decision);
        sort($keys);
        if ($keys !== ['executed', 'reason', 'request', 'risk', 'status']) {
            throw new DomainException('Decisión sensible de handoff IA no canónica.');
        }

        if (
            $decision['status'] !== 'denied'
            || $decision['reason'] !== 'sensitive_requires_human'
            || $decision['executed'] !== false
            || $decision['risk'] !== AiToolPolicy::SENSITIVE
        ) {
            throw new DomainException('La decisión IA no requiere handoff sensible.');
        }

        $decisionRequest = $decision['request'];
        if (!is_array($decisionRequest)) {
            throw new DomainException('La decisión sensible carece de request canónica.');
        }

        $requestKeys = array_keys($decisionRequest);
        sort($requestKeys);
        if ($requestKeys !== ['request_ref', 'tenant_ref', 'tool_ref']) {
            throw new DomainException('Request de decisión sensible no canónica.');
        }

        $tenantRef = $decisionRequest['tenant_ref'];
        $toolRef = $decisionRequest['tool_ref'];
        $requestRef = $decisionRequest['request_ref'];
        if (
            !is_string($tenantRef)
            || !is_string($toolRef)
            || !is_string($requestRef)
        ) {
            throw new DomainException('Identidad de decisión sensible inválida.');
        }

        if (!is_array($handoffRequest)) {
            throw new DomainException('Solicitud sensible de revisión humana inválida.');
        }

        $request = AiSensitiveHandoffRequest::fromArray(
            $context,
            $policy,
            $handoffRequest,
        );
        $snapshot = $request->snapshot();

        if (
            $tenantRef !== $snapshot['tenant_ref']
            || $toolRef !== $snapshot['tool_ref']
            || $requestRef !== $snapshot['request_ref']
        ) {
            throw new DomainException('Decisión y solicitud sensible no coinciden.');
        }

        if (
            $policy->risk($request->toolRef()) !== AiToolPolicy::SENSITIVE
            || $policy->allowsAutonomousExecution($request->toolRef())
        ) {
            throw new DomainException('La tool sensible no puede adquirir autoridad autónoma.');
        }

        return new self($request);
    }

    /**
     * @return array{
     *     status:'denied',
     *     reason:'sensitive_requires_human',
     *     executed:false,
     *     risk:'sensitive',
     *     request:array{
     *         tenant_ref:string,
     *         tool_ref:string,
     *         request_ref:string,
     *         evidence_ref:string
     *     }
     * }
     */
    public function snapshot(): array
    {
        return [
            'status' => 'denied',
            'reason' => 'sensitive_requires_human',
            'executed' => false,
            'risk' => AiToolPolicy::SENSITIVE,
            'request' => $this->request->snapshot(),
        ];
    }
}
