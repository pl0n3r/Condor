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
#[ORM\Table(name: 'condor_inventory_transfer')]
#[ORM\UniqueConstraint(name: 'uniq_inventory_transfer_tenant_id', columns: ['tenant_id', 'id'])]
#[ORM\UniqueConstraint(
    name: 'uniq_inventory_transfer_tenant_legal_id',
    columns: ['tenant_id', 'legal_entity_id', 'id'],
)]
#[ORM\UniqueConstraint(name: 'uniq_inventory_transfer_tenant_key', columns: ['tenant_id', 'idempotency_key'])]
class InventoryTransfer
{
    public const STATUS_COMPLETED = 'completed';

    #[ORM\Id]
    #[ORM\Column(type: 'string', length: 26)]
    private string $id;

    #[ORM\ManyToOne(targetEntity: Tenant::class)]
    #[ORM\JoinColumn(name: 'tenant_id', referencedColumnName: 'id', nullable: false, onDelete: 'CASCADE')]
    private Tenant $tenant;

    #[ORM\ManyToOne(targetEntity: LegalEntity::class)]
    #[ORM\JoinColumn(name: 'legal_entity_id', referencedColumnName: 'id', nullable: false, onDelete: 'RESTRICT')]
    private LegalEntity $legalEntity;

    #[ORM\ManyToOne(targetEntity: ProductVariant::class)]
    #[ORM\JoinColumn(name: 'variant_id', referencedColumnName: 'id', nullable: false, onDelete: 'RESTRICT')]
    private ProductVariant $variant;

    #[ORM\ManyToOne(targetEntity: InventorySource::class)]
    #[ORM\JoinColumn(name: 'source_from_id', referencedColumnName: 'id', nullable: false, onDelete: 'RESTRICT')]
    private InventorySource $sourceFrom;

    #[ORM\ManyToOne(targetEntity: InventorySource::class)]
    #[ORM\JoinColumn(name: 'source_to_id', referencedColumnName: 'id', nullable: false, onDelete: 'RESTRICT')]
    private InventorySource $sourceTo;

    #[ORM\Column(type: 'integer')]
    private int $quantity;

    #[ORM\Column(name: 'actor_user_id', type: 'string', length: 26, nullable: true)]
    private ?string $actorUserId;

    #[ORM\Column(name: 'idempotency_key', type: 'string', length: 120)]
    private string $idempotencyKey;

    #[ORM\Column(type: 'string', length: 20)]
    private string $status = self::STATUS_COMPLETED;

    #[ORM\Column(name: 'created_at', type: 'datetime_immutable')]
    private DateTimeImmutable $createdAt;

    public function __construct(
        Tenant $tenant,
        ProductVariant $variant,
        InventorySource $sourceFrom,
        InventorySource $sourceTo,
        int $quantity,
        ?string $actorUserId,
        string $idempotencyKey,
    ) {
        if (
            $variant->tenant()->id() !== $tenant->id()
            || $sourceFrom->tenant()->id() !== $tenant->id()
            || $sourceTo->tenant()->id() !== $tenant->id()
        ) {
            throw new DomainException(
                'La transferencia, variante y fuentes deben pertenecer al mismo tenant.',
            );
        }
        if ($sourceFrom->legalEntity()->id() !== $sourceTo->legalEntity()->id()) {
            throw new DomainException(
                'Una transferencia interna no puede cruzar entidades legales.',
            );
        }
        if ($sourceFrom->id() === $sourceTo->id()) {
            throw new DomainException(
                'La fuente de origen y destino deben ser diferentes.',
            );
        }
        if ($quantity <= 0) {
            throw new DomainException(
                'La cantidad de la transferencia debe ser mayor que cero.',
            );
        }
        if ($quantity > 2147483647) {
            throw new DomainException(
                'La cantidad de la transferencia excede el rango permitido.',
            );
        }

        $idempotencyKey = trim($idempotencyKey);
        if ($idempotencyKey === '' || mb_strlen($idempotencyKey, 'UTF-8') > 120) {
            throw new DomainException(
                'La clave de idempotencia es obligatoria y admite máximo 120 caracteres.',
            );
        }

        $this->id = UlidFactory::new();
        $this->tenant = $tenant;
        $this->legalEntity = $sourceFrom->legalEntity();
        $this->variant = $variant;
        $this->sourceFrom = $sourceFrom;
        $this->sourceTo = $sourceTo;
        $this->quantity = $quantity;
        $this->actorUserId = $actorUserId;
        $this->idempotencyKey = $idempotencyKey;
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

    public function variant(): ProductVariant
    {
        return $this->variant;
    }

    public function sourceFrom(): InventorySource
    {
        return $this->sourceFrom;
    }

    public function sourceTo(): InventorySource
    {
        return $this->sourceTo;
    }

    public function quantity(): int
    {
        return $this->quantity;
    }

    public function actorUserId(): ?string
    {
        return $this->actorUserId;
    }

    public function idempotencyKey(): string
    {
        return $this->idempotencyKey;
    }

    public function status(): string
    {
        return $this->status;
    }

    public function createdAt(): DateTimeImmutable
    {
        return $this->createdAt;
    }
}
