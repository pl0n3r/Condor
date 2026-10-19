<?php

declare(strict_types=1);

namespace App\Application\Identifiers;

use App\Domain\Identifiers\ScanIdentifier;
use App\Domain\Organization\Entity\Tenant;
use DomainException;

/**
 * Registro de alcance local para escaneos (sin I/O, hardware o publicación).
 *
 * Un adaptador de persistencia futuro debe garantizar la misma unicidad
 * (tenant_id, code) de manera transaccional; este registro no es una DB.
 */
final class ScanResolver
{
    /** @var array<string, array<string, ScanIdentifier>> */
    private array $identifiers = [];

    public function register(ScanIdentifier $identifier): void
    {
        $tenantId = $identifier->tenantId();
        $code = $identifier->code();
        $existing = $this->identifiers[$tenantId][$code] ?? null;

        if ($existing !== null
            && ($existing->kind() !== $identifier->kind()
                || $existing->entityId() !== $identifier->entityId())) {
            throw new DomainException('Código duplicado para otra entidad en el tenant.');
        }

        $this->identifiers[$tenantId][$code] = $identifier;
    }

    public function resolve(Tenant $tenant, string $code): ?ScanIdentifier
    {
        try {
            $code = ScanIdentifier::canonicalCode($code);
        } catch (DomainException) {
            return null;
        }

        return $this->identifiers[$tenant->id()][$code] ?? null;
    }
}
