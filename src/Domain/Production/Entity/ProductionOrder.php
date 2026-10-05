<?php

declare(strict_types=1);

namespace App\Domain\Production\Entity;

use App\Domain\Catalog\Entity\ProductVariant;
use App\Domain\Inventory\Entity\InventorySource;
use App\Domain\Organization\Entity\Tenant;
use App\Shared\Id\UlidFactory;
use DateTimeImmutable;
use DateTimeZone;
use Doctrine\ORM\Mapping as ORM;
use DomainException;

#[ORM\Entity]
#[ORM\Table(name: 'condor_production_order')]
#[ORM\UniqueConstraint(
    name: 'uniq_production_order_tenant_id',
    columns: ['tenant_id', 'id'],
)]
#[ORM\UniqueConstraint(
    name: 'uniq_production_order_tenant_completion_key',
    columns: ['tenant_id', 'completion_idempotency_key'],
)]
class ProductionOrder
{
    public const STATUS_DRAFT = 'draft';
    public const STATUS_COMPLETED = 'completed';

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

    #[ORM\ManyToOne(targetEntity: BillOfMaterials::class)]
    #[ORM\JoinColumn(
        name: 'bom_id',
        referencedColumnName: 'id',
        nullable: false,
        onDelete: 'RESTRICT',
    )]
    private BillOfMaterials $billOfMaterials;

    #[ORM\ManyToOne(targetEntity: ProductVariant::class)]
    #[ORM\JoinColumn(
        name: 'variant_id',
        referencedColumnName: 'id',
        nullable: false,
        onDelete: 'RESTRICT',
    )]
    private ProductVariant $variant;

    #[ORM\ManyToOne(targetEntity: InventorySource::class)]
    #[ORM\JoinColumn(
        name: 'source_id',
        referencedColumnName: 'id',
        nullable: false,
        onDelete: 'RESTRICT',
    )]
    private InventorySource $source;

    #[ORM\Column(name: 'target_quantity', type: 'integer', options: ['unsigned' => true])]
    private int $targetQuantity;

    #[ORM\Column(name: 'completed_quantity', type: 'integer', nullable: true, options: ['unsigned' => true])]
    private ?int $completedQuantity = null;

    #[ORM\Column(type: 'string', length: 20)]
    private string $status = self::STATUS_DRAFT;

    #[ORM\Column(name: 'completion_idempotency_key', type: 'string', length: 120, nullable: true)]
    private ?string $completionIdempotencyKey = null;

    #[ORM\Column(name: 'created_at', type: 'datetime_immutable')]
    private DateTimeImmutable $createdAt;

    #[ORM\Column(name: 'completed_at', type: 'datetime_immutable', nullable: true)]
    private ?DateTimeImmutable $completedAt = null;

    public function __construct(
        Tenant $tenant,
        BillOfMaterials $billOfMaterials,
        ProductVariant $variant,
        InventorySource $source,
        int $targetQuantity,
    ) {
        self::assertScope($tenant, $billOfMaterials, $variant, $source);
        self::assertQuantity($targetQuantity);

        if ($billOfMaterials->variant()->id() !== $variant->id()) {
            throw new DomainException(
                'La BOM de la orden no corresponde a la variante terminada.',
            );
        }

        $this->id = UlidFactory::new();
        $this->tenant = $tenant;
        $this->billOfMaterials = $billOfMaterials;
        $this->variant = $variant;
        $this->source = $source;
        $this->targetQuantity = $targetQuantity;
        $this->createdAt = new DateTimeImmutable('now', new DateTimeZone('UTC'));
    }

    public function id(): string
    {
        return $this->id;
    }

    public function tenant(): Tenant
    {
        return $this->tenant;
    }

    public function billOfMaterials(): BillOfMaterials
    {
        return $this->billOfMaterials;
    }

    public function variant(): ProductVariant
    {
        return $this->variant;
    }

    public function source(): InventorySource
    {
        return $this->source;
    }

    public function targetQuantity(): int
    {
        return $this->targetQuantity;
    }

    public function completedQuantity(): ?int
    {
        return $this->completedQuantity;
    }

    public function status(): string
    {
        return $this->status;
    }

    public function completionIdempotencyKey(): ?string
    {
        return $this->completionIdempotencyKey;
    }

    public function createdAt(): DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function completedAt(): ?DateTimeImmutable
    {
        return $this->completedAt;
    }

    public function isCompleted(): bool
    {
        return $this->status === self::STATUS_COMPLETED;
    }

    public function complete(
        int $completedQuantity,
        string $idempotencyKey,
    ): void {
        self::assertQuantity($completedQuantity);
        $key = self::normalizeIdempotencyKey($idempotencyKey);

        if ($this->isCompleted()) {
            $this->assertSameCompletion($completedQuantity, $key);

            return;
        }

        if ($completedQuantity !== $this->targetQuantity) {
            throw new DomainException(
                'Producción Lite no admite cierres parciales de una orden.',
            );
        }

        $this->completedQuantity = $completedQuantity;
        $this->completionIdempotencyKey = $key;
        $this->status = self::STATUS_COMPLETED;
        $this->completedAt = new DateTimeImmutable('now', new DateTimeZone('UTC'));
    }

    public function assertSameCompletion(
        int $completedQuantity,
        string $idempotencyKey,
    ): void {
        if (
            $this->completedQuantity !== $completedQuantity
            || $this->completionIdempotencyKey !== self::normalizeIdempotencyKey(
                $idempotencyKey,
            )
        ) {
            throw new DomainException(
                'La orden ya fue completada con parámetros distintos.',
            );
        }
    }

    private static function assertQuantity(int $quantity): void
    {
        if ($quantity <= 0 || $quantity > 2147483647) {
            throw new DomainException(
                'La cantidad de producción debe ser un entero positivo válido.',
            );
        }
    }

    private static function normalizeIdempotencyKey(string $key): string
    {
        $key = trim($key);
        if ($key === '' || mb_strlen($key, 'UTF-8') > 120) {
            throw new DomainException(
                'La clave de idempotencia de completion es obligatoria y admite máximo 120 caracteres.',
            );
        }

        return $key;
    }

    private static function assertScope(
        Tenant $tenant,
        BillOfMaterials $billOfMaterials,
        ProductVariant $variant,
        InventorySource $source,
    ): void {
        if (
            $billOfMaterials->tenant()->id() !== $tenant->id()
            || $variant->tenant()->id() !== $tenant->id()
            || $source->tenant()->id() !== $tenant->id()
        ) {
            throw new DomainException(
                'La orden, BOM, variante y fuente deben pertenecer al mismo tenant.',
            );
        }
    }
}
