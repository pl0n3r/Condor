<?php

declare(strict_types=1);

namespace App\Application\AI;

use App\Domain\AI\AiTenantContext;
use App\Domain\AI\AiToolPolicy;
use DomainException;

final readonly class AiSensitiveHandoffRequest
{
    private const SENSITIVE_TOOL = 'identity.permission.change';

    private function __construct(
        private string $tenantRef,
        private string $toolRef,
        private string $requestRef,
        private string $evidenceRef,
    ) {
    }

    /** @param array<string, mixed> $request */
    public static function fromArray(
        AiTenantContext $context,
        AiToolPolicy $policy,
        array $request,
    ): self {
        $keys = array_keys($request);
        sort($keys);
        if ($keys !== ['evidence_ref', 'request_ref', 'tenant_ref', 'tool_ref']) {
            throw new DomainException('Solicitud sensible de handoff IA no canónica.');
        }

        $tenantRef = $request['tenant_ref'];
        $toolRef = $request['tool_ref'];
        $requestRef = $request['request_ref'];
        $evidenceRef = $request['evidence_ref'];

        if (
            !is_string($tenantRef)
            || !is_string($toolRef)
            || !is_string($requestRef)
            || !is_string($evidenceRef)
        ) {
            throw new DomainException('Solicitud sensible de handoff IA inválida.');
        }

        if ($tenantRef !== 'tenant:' . $context->tenantId()) {
            throw new DomainException('Tenant ref sensible no coincide con el contexto IA.');
        }

        $expectedTool = strtolower(trim($context->tool()));
        if ($toolRef !== $expectedTool || $toolRef !== self::SENSITIVE_TOOL) {
            throw new DomainException('Tool sensible fuera del contexto autorizado.');
        }

        if ($policy->risk($toolRef) !== AiToolPolicy::SENSITIVE) {
            throw new DomainException('Tool de handoff no está clasificada como sensible.');
        }

        if (
            strlen($requestRef) > 136
            || preg_match('/^request:[A-Za-z0-9][A-Za-z0-9._-]{0,127}$/D', $requestRef) !== 1
        ) {
            throw new DomainException('Request ref sensible inválida.');
        }

        if (
            strlen($evidenceRef) > 169
            || preg_match('/^evidence:[A-Za-z0-9][A-Za-z0-9._-]{0,159}$/D', $evidenceRef) !== 1
        ) {
            throw new DomainException('Evidence ref sensible inválida.');
        }

        return new self(
            $tenantRef,
            $toolRef,
            $requestRef,
            $evidenceRef,
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

    public function evidenceRef(): string
    {
        return $this->evidenceRef;
    }

    /**
     * @return array{
     *     tenant_ref:string,
     *     tool_ref:string,
     *     request_ref:string,
     *     evidence_ref:string
     * }
     */
    public function snapshot(): array
    {
        return [
            'tenant_ref' => $this->tenantRef,
            'tool_ref' => $this->toolRef,
            'request_ref' => $this->requestRef,
            'evidence_ref' => $this->evidenceRef,
        ];
    }
}
