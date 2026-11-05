<?php

declare(strict_types=1);

namespace App\Application\Commercial;

use DomainException;

/**
 * Contexto de ejecución de una escritura facturable: el tenant afectado, el
 * snapshot comercial con el que se decide el límite y quién la origina.
 */
final readonly class ExecutionContext
{
    public string $actor;

    public function __construct(
        public string $tenantId,
        public EntitlementSnapshot $snapshot,
        string $actor,
    ) {
        $actor = trim($actor);
        if ($tenantId === '' || $actor === '') {
            throw new DomainException('Contexto de ejecución incompleto.');
        }
        if ($snapshot->tenantId() !== $tenantId) {
            throw new DomainException('El snapshot comercial pertenece a otro tenant.');
        }
        $this->actor = $actor;
    }
}
