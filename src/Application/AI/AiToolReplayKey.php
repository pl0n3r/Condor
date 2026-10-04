<?php

declare(strict_types=1);

namespace App\Application\AI;

use App\Domain\AI\AiTenantContext;
use App\Domain\AI\AiToolPolicy;
use DomainException;
use JsonException;

final readonly class AiToolReplayKey
{
    private function __construct(private string $value)
    {
    }

    public static function fromRequest(
        AiToolRequest $request,
        AiTenantContext $context,
        AiToolPolicy $policy,
    ): self {
        $snapshot = $request->snapshot();
        $tool = strtolower(trim($context->tool()));
        $expected = [
            'tenant_ref' => 'tenant:' . $context->tenantId(),
            'tool_ref' => $tool,
            'request_ref' => $request->requestRef(),
        ];

        if ($snapshot !== $expected) {
            throw new DomainException('Request y contexto IA no son coherentes para replay.');
        }

        $policy->risk($tool);

        try {
            $encoded = json_encode(
                $snapshot,
                JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES,
            );
        } catch (JsonException) {
            throw new DomainException('No fue posible construir la identidad de replay.');
        }

        return new self('replay:' . hash('sha256', $encoded));
    }

    public function value(): string
    {
        return $this->value;
    }

    public function __toString(): string
    {
        return $this->value;
    }
}
