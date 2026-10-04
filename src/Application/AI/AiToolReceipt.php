<?php

declare(strict_types=1);

namespace App\Application\AI;

use App\Domain\AI\AiAuditEnvelope;
use App\Domain\AI\AiTenantContext;
use App\Domain\AI\AiToolPolicy;
use DomainException;

final readonly class AiToolReceipt
{
    /** @var AiToolPolicy::READ_ONLY|AiToolPolicy::REVERSIBLE_WRITE */
    private string $risk;

    /** @var 'success'|'denied'|'failure' */
    private string $outcome;

    /**
     * @param AiToolPolicy::READ_ONLY|AiToolPolicy::REVERSIBLE_WRITE $risk
     * @param 'success'|'denied'|'failure' $outcome
     */
    private function __construct(
        private string $tenantRef,
        private string $toolRef,
        private string $requestRef,
        string $risk,
        string $outcome,
        private string $evidenceRef,
        private string $timestamp,
    ) {
        $this->risk = $risk;
        $this->outcome = $outcome;
    }

    /**
     * @param array{
     *     request:mixed,
     *     decision:mixed,
     *     audit:mixed
     * } $receipt
     */
    public static function fromArray(
        AiTenantContext $context,
        AiToolPolicy $policy,
        array $receipt,
    ): self {
        $keys = array_keys($receipt);
        sort($keys);
        if ($keys !== ['audit', 'decision', 'request']) {
            throw new DomainException('Receipt de tool IA no canónico.');
        }

        $request = self::canonicalRequest($context, $policy, $receipt['request']);
        $risk = self::authorizedDecision($policy, $request, $receipt['decision']);
        $audit = self::canonicalAudit($policy, $request, $receipt['audit']);

        return new self(
            $request['tenant_ref'],
            $request['tool_ref'],
            $request['request_ref'],
            $risk,
            $audit['outcome'],
            $audit['evidence_ref'],
            $audit['timestamp'],
        );
    }

    public function tenantRef(): string
    {
        return $this->tenantRef;
    }

    public function toolRef(): string
    {
        return $this->toolRef;
    }

    public function requestRef(): string
    {
        return $this->requestRef;
    }

    public function risk(): string
    {
        return $this->risk;
    }

    public function outcome(): string
    {
        return $this->outcome;
    }

    public function evidenceRef(): string
    {
        return $this->evidenceRef;
    }

    public function timestamp(): string
    {
        return $this->timestamp;
    }

    /**
     * @return array{
     *     tenant_ref:string,
     *     tool_ref:string,
     *     request_ref:string,
     *     decision:'authorized',
     *     risk:'read_only'|'reversible_write',
     *     outcome:'success'|'denied'|'failure',
     *     evidence_ref:string,
     *     timestamp:string
     * }
     */
    public function snapshot(): array
    {
        return [
            'tenant_ref' => $this->tenantRef,
            'tool_ref' => $this->toolRef,
            'request_ref' => $this->requestRef,
            'decision' => 'authorized',
            'risk' => $this->risk,
            'outcome' => $this->outcome,
            'evidence_ref' => $this->evidenceRef,
            'timestamp' => $this->timestamp,
        ];
    }

    /**
     * @return array{tenant_ref:string,tool_ref:string,request_ref:string}
     */
    private static function canonicalRequest(
        AiTenantContext $context,
        AiToolPolicy $policy,
        mixed $request,
    ): array {
        if (!is_array($request)) {
            throw new DomainException('Request del receipt IA inválida.');
        }

        $keys = array_keys($request);
        sort($keys);
        if ($keys !== ['request_ref', 'tenant_ref', 'tool_ref']) {
            throw new DomainException('Request del receipt IA no canónica.');
        }

        $tenantRef = $request['tenant_ref'];
        $toolRef = $request['tool_ref'];
        $requestRef = $request['request_ref'];
        if (!is_string($tenantRef) || !is_string($toolRef) || !is_string($requestRef)) {
            throw new DomainException('Request del receipt IA inválida.');
        }

        $tenantRef = trim($tenantRef);
        if (!str_starts_with($tenantRef, 'tenant:')) {
            throw new DomainException('Tenant ref del receipt IA inválida.');
        }

        $canonical = AiToolRequest::fromArray(
            $context,
            $policy,
            [
                'tenant_id' => substr($tenantRef, strlen('tenant:')),
                'tool' => $toolRef,
                'request_ref' => $requestRef,
            ],
        )->snapshot();

        if ($canonical !== $request) {
            throw new DomainException('Request del receipt IA no coincide con el contexto.');
        }

        return $canonical;
    }

    /**
     * @param array{tenant_ref:string,tool_ref:string,request_ref:string} $request
     * @return AiToolPolicy::READ_ONLY|AiToolPolicy::REVERSIBLE_WRITE
     */
    private static function authorizedDecision(
        AiToolPolicy $policy,
        array $request,
        mixed $decision,
    ): string {
        if (!is_array($decision)) {
            throw new DomainException('Decisión del receipt IA inválida.');
        }

        $keys = array_keys($decision);
        sort($keys);
        if ($keys !== ['executed', 'reason', 'request', 'risk', 'status']) {
            throw new DomainException('Decisión del receipt IA no canónica.');
        }

        if (
            $decision['status'] !== 'authorized'
            || $decision['reason'] !== 'policy_allows'
            || $decision['executed'] !== false
            || $decision['request'] !== $request
            || !is_string($decision['risk'])
        ) {
            throw new DomainException('Decisión del receipt IA no autorizada.');
        }

        $risk = $policy->risk($request['tool_ref']);
        if (
            $risk === AiToolPolicy::SENSITIVE
            || $decision['risk'] !== $risk
        ) {
            throw new DomainException('Riesgo del receipt IA inconsistente.');
        }

        return $risk;
    }

    /**
     * @param array{tenant_ref:string,tool_ref:string,request_ref:string} $request
     * @return array{
     *     tenant_ref:string,
     *     tool_ref:string,
     *     outcome:'success'|'denied'|'failure',
     *     evidence_ref:string,
     *     timestamp:string
     * }
     */
    private static function canonicalAudit(
        AiToolPolicy $policy,
        array $request,
        mixed $audit,
    ): array {
        if (!is_array($audit)) {
            throw new DomainException('Auditoría del receipt IA inválida.');
        }

        $envelope = AiAuditEnvelope::fromArray($audit, $policy);
        $snapshot = $envelope->snapshot();

        if (
            $snapshot['tenant_ref'] !== $request['tenant_ref']
            || $snapshot['tool_ref'] !== $request['tool_ref']
        ) {
            throw new DomainException('Auditoría del receipt IA no coincide con la request.');
        }

        /** @var array{
         *     tenant_ref:string,
         *     tool_ref:string,
         *     outcome:'success'|'denied'|'failure',
         *     evidence_ref:string,
         *     timestamp:string
         * } $snapshot
         */
        return $snapshot;
    }
}
