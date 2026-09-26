<?php

declare(strict_types=1);

namespace App\Domain\Inventory\Entity;

use App\Domain\Catalog\Entity\ProductVariant;
use App\Domain\Organization\Entity\LegalEntity;
use App\Domain\Organization\Entity\Tenant;
use App\Shared\Id\UlidFactory;
use DateTimeImmutable;
use DateTimeZone;
use Doctrine\ORM\Mapping as ORM;
use DomainException;

#[ORM\Entity]
#[ORM\Table(name: 'condor_inventory_balance')]
#[ORM\UniqueConstraint(
    name: 'uniq_inventory_balance_scope_variant',
    columns: ['tenant_id', 'legal_entity_id', 'source_id', 'variant_id'],
)]
#[ORM\UniqueConstraint(name: 'uniq_inventory_balance_tenant_id', columns: ['tenant_id', 'id'])]
class InventoryBalance
{
    #[ORM\Id]
    #[ORM\Column(type: 'string', length: 26)]
    private string $id;

    #[ORM\ManyToOne(targetEntity: Tenant::class)]
    #[ORM\JoinColumn(name: 'tenant_id', referencedColumnName: 'id', nullable: false, onDelete: 'CASCADE')]
    private Tenant $tenant;

    #[ORM\ManyToOne(targetEntity: LegalEntity::class)]
    #[ORM\JoinColumn(name: 'legal_entity_id', referencedColumnName: 'id', nullable: false, onDelete: 'RESTRICT')]
    private LegalEntity $legalEntity;

    #[ORM\ManyToOne(targetEntity: InventorySource::class)]
    #[ORM\JoinColumn(name: 'source_id', referencedColumnName: 'id', nullable: false, onDelete: 'RESTRICT')]
    private InventorySource $source;

    #[ORM\ManyToOne(targetEntity: ProductVariant::class)]
    #[ORM\JoinColumn(name: 'variant_id', referencedColumnName: 'id', nullable: false, onDelete: 'RESTRICT')]
    private ProductVariant $variant;

    #[ORM\Column(type: 'integer')]
    private int $quantity = 0;

    #[ORM\Column(name: 'reserved_quantity', type: 'integer', options: ['default' => 0])]
    private int $reservedQuantity = 0;

    #[ORM\Column(type: 'integer')]
    private int $version = 0;

    #[ORM\Column(name: 'created_at', type: 'datetime_immutable')]
    private DateTimeImmutable $createdAt;

    #[ORM\Column(name: 'updated_at', type: 'datetime_immutable')]
    private DateTimeImmutable $updatedAt;

    public function __construct(
        Tenant $tenant,
        InventorySource $source,
        ProductVariant $variant,
        int $quantity = 0,
    ) {
        self::assertTenant($tenant, $source, $variant);
        self::assertDatabaseInteger(
            $quantity,
            'El saldo inicial excede el rango permitido por inventario.',
        );
        if ($quantity < 0 && !$variant->product()->allowsBackorder()) {
            throw new DomainException(
                'El saldo inicial no puede ser negativo sin backorder habilitado.',
            );
        }

        $this->id = UlidFactory::new();
        $this->tenant = $tenant;
        $this->legalEntity = $source->legalEntity();
        $this->source = $source;
        $this->variant = $variant;
        $this->quantity = $quantity;
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

    public function legalEntity(): LegalEntity
    {
        return $this->legalEntity;
    }

    public function source(): InventorySource
    {
        return $this->source;
    }

    public function variant(): ProductVariant
    {
        return $this->variant;
    }

    public function quantity(): int
    {
        return $this->quantity;
    }

    public function reservedQuantity(): int
    {
        return $this->reservedQuantity;
    }

    public function sellableQuantity(): int
    {
        return $this->quantity - $this->reservedQuantity;
    }

    public function version(): int
    {
        return $this->version;
    }

    public function reserve(int $quantity): void
    {
        self::assertPositiveQuantity($quantity);

        $nextReserved = $this->reservedQuantity + $quantity;
        self::assertDatabaseInteger(
            $nextReserved,
            'La cantidad reservada excede el rango permitido por inventario.',
        );

        if (
            $this->quantity - $nextReserved < 0
            && !$this->variant->product()->allowsBackorder()
        ) {
            throw new DomainException(
                'Stock insuficiente: no hay disponibilidad vendible para reservar.',
            );
        }

        $this->reservedQuantity = $nextReserved;
        $this->touch();
    }

    public function releaseReserved(int $quantity): void
    {
        self::assertPositiveQuantity($quantity);
        if ($quantity > $this->reservedQuantity) {
            throw new DomainException(
                'No se puede liberar más inventario del que está reservado.',
            );
        }

        $this->reservedQuantity -= $quantity;
        $this->touch();
    }

    public function consumeReserved(int $quantity): void
    {
        self::assertPositiveQuantity($quantity);
        if ($quantity > $this->reservedQuantity) {
            throw new DomainException(
                'No se puede consumir más inventario del que está reservado.',
            );
        }

        $nextQuantity = $this->quantity - $quantity;
        self::assertDatabaseInteger(
            $nextQuantity,
            'El saldo excede el rango permitido por inventario.',
        );
        if (
            $nextQuantity < 0
            && !$this->variant->product()->allowsBackorder()
        ) {
            throw new DomainException(
                'Stock insuficiente: el producto no permite backorder.',
            );
        }

        $this->quantity = $nextQuantity;
        $this->reservedQuantity -= $quantity;
        $this->touch();
    }

    public function apply(int $delta): void
    {
        if ($delta === 0) {
            throw new DomainException('El movimiento de inventario no puede ser cero.');
        }

        self::assertDatabaseInteger(
            $delta,
            'La cantidad excede el rango permitido por inventario.',
        );

        $next = $this->quantity + $delta;
        self::assertDatabaseInteger(
            $next,
            'El saldo excede el rango permitido por inventario.',
        );
        if (
            $delta < 0
            && $next - $this->reservedQuantity < 0
            && !$this->variant->product()->allowsBackorder()
        ) {
            throw new DomainException(
                'Stock insuficiente: el producto no permite backorder. '
                .'No se pueden comprometer reservas activas.',
            );
        }

        $this->quantity = $next;
        $this->touch();
    }

    private function touch(): void
    {
        $this->version++;
        $this->updatedAt = new DateTimeImmutable('now', new DateTimeZone('UTC'));
    }

    private static function assertPositiveQuantity(int $quantity): void
    {
        if ($quantity <= 0) {
            throw new DomainException(
                'La cantidad de inventario debe ser mayor que cero.',
            );
        }
        self::assertDatabaseInteger(
            $quantity,
            'La cantidad excede el rango permitido por inventario.',
        );
    }

    private static function assertDatabaseInteger(
        int $value,
        string $message,
    ): void {
        if ($value > 2147483647 || $value < -2147483648) {
            throw new DomainException($message);
        }
    }

    private static function assertTenant(
        Tenant $tenant,
        InventorySource $source,
        ProductVariant $variant,
    ): void {
        if (
            $source->tenant()->id() !== $tenant->id()
            || $variant->tenant()->id() !== $tenant->id()
        ) {
            throw new DomainException(
                'El balance, la fuente y la variante deben pertenecer al mismo tenant.',
            );
        }
    }
}
