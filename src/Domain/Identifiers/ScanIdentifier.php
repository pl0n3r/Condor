<?php

declare(strict_types=1);

namespace App\Domain\Identifiers;

use App\Domain\Catalog\Entity\ProductVariant;
use App\Domain\Organization\Entity\Tenant;
use DomainException;

/**
 * Identificador imprimible/escaneable asociado a un único tenant.
 *
 * El código es dato, no una orden de mutación ni un permiso de acceso.
 */
final readonly class ScanIdentifier
{
    public const VARIANT = 'variant';
    public const UNIT = 'unit';

    private function __construct(
        private string $tenantId,
        private string $code,
        private string $kind,
        private string $entityId,
    ) {
    }

    public static function forVariant(Tenant $tenant, ProductVariant $variant, string $code): self
    {
        if ($variant->tenant()->id() !== $tenant->id() || !$variant->isActive()) {
            throw new DomainException('La variante no pertenece al tenant o no está activa.');
        }

        return new self($tenant->id(), self::canonicalCode($code), self::VARIANT, $variant->id());
    }

    /**
     * Uso únicamente tras validar la pertenencia de la unidad al tenant.
     * La persistencia y la autorización del usuario corresponden al consumidor.
     */
    public static function forUnit(Tenant $tenant, string $unitId, string $code): self
    {
        $unitId = trim($unitId);
        if ($unitId === '' || strlen($unitId) > 120 || preg_match('/^[A-Za-z0-9_-]+$/D', $unitId) !== 1) {
            throw new DomainException('El identificador de unidad no es válido.');
        }

        return new self($tenant->id(), self::canonicalCode($code), self::UNIT, $unitId);
    }

    public static function canonicalCode(string $code): string
    {
        $code = strtoupper(trim($code));
        if (strlen($code) < 4 || strlen($code) > 64
            || preg_match('/^[A-Z0-9][A-Z0-9._-]*$/D', $code) !== 1) {
            throw new DomainException('Código escaneable inválido.');
        }

        return $code;
    }

    public function tenantId(): string
    {
        return $this->tenantId;
    }

    public function code(): string
    {
        return $this->code;
    }

    public function kind(): string
    {
        return $this->kind;
    }

    public function entityId(): string
    {
        return $this->entityId;
    }

    public function action(): string
    {
        return $this->kind === self::VARIANT ? 'view_variant' : 'view_unit';
    }
}
