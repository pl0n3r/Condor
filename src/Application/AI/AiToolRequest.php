<?php

declare(strict_types=1);

namespace App\Application\AI;

use App\Domain\AI\AiTenantContext;
use App\Domain\AI\AiToolPolicy;
use DomainException;

final readonly class AiToolRequest
{
    private function __construct(
        private string $tenantId,
        private string $tool,
        private string $requestRef,
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
        if ($keys !== ['request_ref', 'tenant_id', 'tool']) {
            throw new DomainException('Solicitud de tool IA no canónica.');
        }

        $tenantId = $request['tenant_id'];
        $tool = $request['tool'];
        $requestRef = $request['request_ref'];
        if (!is_string($tenantId) || !is_string($tool) || !is_string($requestRef)) {
            throw new DomainException('Solicitud de tool IA inválida.');
        }

        $tenantId = trim($tenantId);
        $tool = strtolower(trim($tool));
        $requestRef = trim($requestRef);

        if ($tenantId !== $context->tenantId()) {
            throw new DomainException('Solicitud cross-tenant no autorizada.');
        }

        if ($tool !== strtolower(trim($context->tool()))) {
            throw new DomainException('Tool fuera del contexto IA.');
        }

        $policy->risk($tool);

        if (
            strlen($requestRef) > 136
            || preg_match('/^request:[A-Za-z0-9][A-Za-z0-9._-]{0,127}$/D', $requestRef) !== 1
        ) {
            throw new DomainException('Request ref de IA inválida.');
        }

        return new self($tenantId, $tool, $requestRef);
    }

    public function tenantId(): string
    {
        return $this->tenantId;
    }

    public function tool(): string
    {
        return $this->tool;
    }

    public function requestRef(): string
    {
        return $this->requestRef;
    }

    /** @return array{tenant_ref:string,tool_ref:string,request_ref:string} */
    public function snapshot(): array
    {
        return [
            'tenant_ref' => 'tenant:' . $this->tenantId,
            'tool_ref' => $this->tool,
            'request_ref' => $this->requestRef,
        ];
    }
}
