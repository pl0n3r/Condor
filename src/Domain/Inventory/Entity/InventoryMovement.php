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
#[ORM\Table(name: 'condor_inventory_movement')]
#[ORM\Index(
    name: 'idx_inventory_movement_scope_time',
    columns: [
        'tenant_id',
        'legal_entity_id',
        'source_id',
        'variant_id',
        'created_at',
    ],
)]
#[ORM\Index(name: 'idx_inventory_movement_transfer', columns: ['transfer_id'])]
#[ORM\UniqueConstraint(name: 'uniq_inventory_movement_tenant_key', columns: ['tenant_id', 'idempotency_key'])]
class InventoryMovement
{
    public const TYPE_ADJUSTMENT_IN = 'adjustment_in';
    public const TYPE_ADJUSTMENT_OUT = 'adjustment_out';
    public const TYPE_TRANSFER_OUT = 'transfer_out';
    public const TYPE_TRANSFER_IN = 'transfer_in';

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

    #[ORM\ManyToOne(targetEntity: InventoryTransfer::class)]
    #[ORM\JoinColumn(name: 'transfer_id', referencedColumnName: 'id', nullable: true, onDelete: 'RESTRICT')]
    private ?InventoryTransfer $transfer;

    #[ORM\Column(type: 'string', length: 30)]
    private string $type;

    #[ORM\Column(type: 'integer')]
    private int $delta;

    #[ORM\Column(name: 'balance_after', type: 'integer')]
    private int $balanceAfter;

    #[ORM\Column(name: 'actor_user_id', type: 'string', length: 26, nullable: true)]
    private ?string $actorUserId;

    #[ORM\Column(name: 'idempotency_key', type: 'string', length: 120, nullable: true)]
    private ?string $idempotencyKey;

    /** @var array<string, scalar|null> */
    #[ORM\Column(type: 'json')]
    private array $context;

    #[ORM\Column(name: 'created_at', type: 'datetime_immutable')]
    private DateTimeImmutable $createdAt;

    /** @param array<string, scalar|null> $context */
    public function __construct(
        Tenant $tenant,
        InventorySource $source,
        ProductVariant $variant,
        string $type,
        int $delta,
        int $balanceAfter,
        ?string $actorUserId,
        ?string $idempotencyKey = null,
        ?InventoryTransfer $transfer = null,
        array $context = [],
    ) {
        self::assertTenant($tenant, $source, $variant, $transfer);
        self::assertType($type, $transfer);
        if ($delta === 0) {
            throw new DomainException('El movimiento de inventario no puede ser cero.');
        }

        if ($idempotencyKey !== null) {
            $idempotencyKey = trim($idempotencyKey);
            if ($idempotencyKey === '' || mb_strlen($idempotencyKey, 'UTF-8') > 120) {
                throw new DomainException(
                    'La clave de idempotencia admite máximo 120 caracteres.',
                );
            }
        }

        $this->id = UlidFactory::new();
        $this->tenant = $tenant;
        $this->legalEntity = $source->legalEntity();
        $this->source = $source;
        $this->variant = $variant;
        $this->transfer = $transfer;
        $this->type = $type;
        $this->delta = $delta;
        $this->balanceAfter = $balanceAfter;
        $this->actorUserId = $actorUserId;
        $this->idempotencyKey = $idempotencyKey;
        $this->context = $context;
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

    public function transfer(): ?InventoryTransfer
    {
        return $this->transfer;
    }

    public function type(): string
    {
        return $this->type;
    }

    public function delta(): int
    {
        return $this->delta;
    }

    public function balanceAfter(): int
    {
        return $this->balanceAfter;
    }

    public function actorUserId(): ?string
    {
        return $this->actorUserId;
    }

    public function idempotencyKey(): ?string
    {
        return $this->idempotencyKey;
    }

    /** @return array<string, scalar|null> */
    public function context(): array
    {
        return $this->context;
    }

    public function createdAt(): DateTimeImmutable
    {
        return $this->createdAt;
    }

    private static function assertTenant(
        Tenant $tenant,
        InventorySource $source,
        ProductVariant $variant,
        ?InventoryTransfer $transfer,
    ): void {
        if (
            $source->tenant()->id() !== $tenant->id()
            || $variant->tenant()->id() !== $tenant->id()
            || (
                $transfer instanceof InventoryTransfer
                && (
                    $transfer->tenant()->id() !== $tenant->id()
                    || $transfer->legalEntity()->id() !== $source->legalEntity()->id()
                )
            )
        ) {
            throw new DomainException(
                'El movimiento, la fuente y la variante deben pertenecer al mismo tenant.',
            );
        }
    }

    private static function assertType(
        string $type,
        ?InventoryTransfer $transfer,
    ): void {
        $known = [
            self::TYPE_ADJUSTMENT_IN,
            self::TYPE_ADJUSTMENT_OUT,
            self::TYPE_TRANSFER_OUT,
            self::TYPE_TRANSFER_IN,
        ];
        if (!in_array($type, $known, true)) {
            throw new DomainException('El tipo de movimiento no es válido.');
        }

        $isTransfer = in_array(
            $type,
            [self::TYPE_TRANSFER_OUT, self::TYPE_TRANSFER_IN],
            true,
        );
        if ($isTransfer !== ($transfer instanceof InventoryTransfer)) {
            throw new DomainException(
                'Los movimientos de transferencia deben quedar vinculados a su transferencia.',
            );
        }
    }
}
