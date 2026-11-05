<?php

declare(strict_types=1);

namespace App\Application\Catalog;

use App\Application\Commercial\ExecutionContext;
use DomainException;

/**
 * Enforcement de catálogo en write boundary; nunca filtro de UI ni de tenant.
 * El snapshot comercial lo resuelve el servidor, no el cliente.
 */
final readonly class CatalogCapabilityGuard
{
    public function assertCanWrite(ExecutionContext $context, string $tenantId): void
    {
        if ($context->tenantId !== $tenantId) {
            throw new DomainException('La capacidad comercial pertenece a otro tenant.');
        }
        if (!$context->snapshot->capability('catalog')) {
            throw new DomainException('La capacidad de catálogo no está habilitada.');
        }
    }
}
