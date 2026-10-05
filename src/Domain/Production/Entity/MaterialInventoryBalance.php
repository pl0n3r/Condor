<?php

declare(strict_types=1);

namespace App\Domain\Production\Entity;

use App\Domain\Inventory\Entity\InventorySource;
use App\Domain\Organization\Entity\Tenant;
use App\Shared\Id\UlidFactory;
use DateTimeImmutable;
use DateTimeZone;
use Doctrine\ORM\Mapping as ORM;
use DomainException;

#[ORM\Entity]
#[ORM\Table(name: 'condor_production_material_balance')]
#[ORM\UniqueConstraint(
    name: 'uniq_production_material_balance_scope',
    columns: ['tenant_id', 'source_id', 'material_id'],
)]
#[ORM\UniqueConstraint(
    name: 'uniq_production_material_balance_tenant_id',
    columns: ['tenant_id', 'id'],
)]
class MaterialInventoryBalance
{
    private const SCALE = 1_000_000;
    private const MAX_WHOLE = '9000000000000';
    private const MAX_MICROS = 9_000_000_000_000_000_000;

    #[ORM\Id]
    #[ORM\Column(type: 'string', length: 26)]
    private string $id;

    #[ORM\ManyToOne(targetEntity: Tenant::class)]
    #[ORM\JoinColumn(
        name: 'tenant_id',
        referencedColumnName: 'id',
        nullable: false,
        onDelete: 'CASCADE',
    )]
    private Tenant $tenant;

    #[ORM\ManyToOne(targetEntity: InventorySource::class)]
    #[ORM\JoinColumn(
        name: 'source_id',
        referencedColumnName: 'id',
        nullable: false,
        onDelete: 'RESTRICT',
    )]
    private InventorySource $source;

    #[ORM\ManyToOne(targetEntity: Material::class)]
    #[ORM\JoinColumn(
        name: 'material_id',
        referencedColumnName: 'id',
        nullable: false,
        onDelete: 'RESTRICT',
    )]
    private Material $material;

    #[ORM\Column(type: 'decimal', precision: 19, scale: 6)]
    private string $quantity = '0';

    #[ORM\Column(type: 'integer', options: ['unsigned' => true, 'default' => 0])]
    private int $version = 0;

    #[ORM\Column(name: 'created_at', type: 'datetime_immutable')]
    private DateTimeImmutable $createdAt;

    #[ORM\Column(name: 'updated_at', type: 'datetime_immutable')]
    private DateTimeImmutable $updatedAt;

    public function __construct(
        Tenant $tenant,
        InventorySource $source,
        Material $material,
        string $quantity = '0',
    ) {
        self::assertScope($tenant, $source, $material);

        $this->id = UlidFactory::new();
        $this->tenant = $tenant;
        $this->source = $source;
        $this->material = $material;
        $this->quantity = self::normalizeQuantity($quantity);
        $this->createdAt = new DateTimeImmutable('now', new DateTimeZone('UTC'));
        $this->updatedAt = $this->createdAt;
    }

    public function id(): string
    {
        return $this->id;
    }

    public function tenant(): Tenant
    {
        return $this->tenant;
    }

    public function source(): InventorySource
    {
        return $this->source;
    }

    public function material(): Material
    {
        return $this->material;
    }

    public function quantity(): string
    {
        return self::normalizeQuantity($this->quantity);
    }

    public function version(): int
    {
        return $this->version;
    }

    public function apply(string $delta): void
    {
        $deltaMicros = self::parseMicros($delta, true);
        if ($deltaMicros === 0) {
            throw new DomainException(
                'El movimiento de material no puede ser cero.',
            );
        }

        $current = self::parseMicros($this->quantity, false);
        if (
            $deltaMicros > 0
            && $current > self::MAX_MICROS - $deltaMicros
        ) {
            throw new DomainException(
                'El saldo de material excede el rango permitido.',
            );
        }

        $next = $current + $deltaMicros;
        if ($next < 0 || $next > self::MAX_MICROS) {
            throw new DomainException(
                'Stock de material insuficiente o fuera de rango.',
            );
        }

        $this->quantity = self::formatMicros($next);
        $this->version++;
        $this->updatedAt = new DateTimeImmutable('now', new DateTimeZone('UTC'));
    }

    public static function normalizeQuantity(string $quantity): string
    {
        return self::formatMicros(self::parseMicros($quantity, false));
    }

    public static function normalizeDelta(string $delta): string
    {
        $micros = self::parseMicros($delta, true);
        if ($micros === 0) {
            throw new DomainException(
                'El movimiento de material no puede ser cero.',
            );
        }

        return self::formatMicros($micros);
    }

    public static function multiply(string $quantity, int $units): string
    {
        if ($units <= 0 || $units > 2147483647) {
            throw new DomainException(
                'Las unidades terminadas deben ser un entero positivo válido.',
            );
        }

        $micros = self::parseMicros($quantity, false);
        if ($micros === 0) {
            throw new DomainException(
                'La cantidad de material debe ser mayor que cero.',
            );
        }
        if ($micros > intdiv(self::MAX_MICROS, $units)) {
            throw new DomainException(
                'El consumo calculado excede el rango permitido.',
            );
        }

        return self::formatMicros($micros * $units);
    }

    private static function parseMicros(string $quantity, bool $signed): int
    {
        $quantity = trim($quantity);
        $negative = $signed && str_starts_with($quantity, '-');
        if ($negative) {
            $quantity = substr($quantity, 1);
        }

        if (
            preg_match('/^(0|[1-9][0-9]*)(?:\.([0-9]{1,6}))?$/D', $quantity, $matches) !== 1
        ) {
            throw new DomainException(
                'La cantidad de material debe usar decimal fijo con máximo seis decimales.',
            );
        }

        $whole = $matches[1];
        if (
            strlen($whole) > strlen(self::MAX_WHOLE)
            || (
                strlen($whole) === strlen(self::MAX_WHOLE)
                && strcmp($whole, self::MAX_WHOLE) > 0
            )
        ) {
            throw new DomainException(
                'La cantidad de material excede el rango permitido.',
            );
        }

        $fraction = str_pad($matches[2] ?? '', 6, '0');
        $micros = ((int) $whole * self::SCALE) + (int) $fraction;
        if ($micros > self::MAX_MICROS) {
            throw new DomainException(
                'La cantidad de material excede el rango permitido.',
            );
        }

        return $negative ? -$micros : $micros;
    }

    private static function formatMicros(int $micros): string
    {
        $negative = $micros < 0;
        $absolute = abs($micros);
        $whole = intdiv($absolute, self::SCALE);
        $fraction = $absolute % self::SCALE;
        $formatted = (string) $whole;
        if ($fraction !== 0) {
            $formatted .= '.'.rtrim(
                str_pad((string) $fraction, 6, '0', STR_PAD_LEFT),
                '0',
            );
        }

        return $negative ? '-'.$formatted : $formatted;
    }

    private static function assertScope(
        Tenant $tenant,
        InventorySource $source,
        Material $material,
    ): void {
        if (
            $source->tenant()->id() !== $tenant->id()
            || $material->tenant()->id() !== $tenant->id()
        ) {
            throw new DomainException(
                'El saldo, la fuente y el material deben pertenecer al mismo tenant.',
            );
        }
    }
}
