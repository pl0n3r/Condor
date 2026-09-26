<?php

declare(strict_types=1);

namespace App\Domain\Inventory\Entity;

use App\Domain\Catalog\Entity\ProductVariant;
use App\Domain\Orders\Entity\Order;
use App\Domain\Orders\Entity\OrderLine;
use App\Domain\Organization\Entity\LegalEntity;
use App\Domain\Organization\Entity\Tenant;
use App\Shared\Id\UlidFactory;
use DateTimeImmutable;
use DateTimeZone;
use Doctrine\ORM\Mapping as ORM;
use DomainException;

#[ORM\Entity]
#[ORM\Table(name: 'condor_inventory_reservation')]
#[ORM\UniqueConstraint(name: 'uniq_inventory_reservation_line', columns: ['tenant_id', 'order_line_id'])]
#[ORM\UniqueConstraint(name: 'uniq_inventory_reservation_reserve_key', columns: ['tenant_id', 'reserve_key'])]
#[ORM\UniqueConstraint(name: 'uniq_inventory_reservation_release_key', columns: ['tenant_id', 'release_key'])]
#[ORM\UniqueConstraint(name: 'uniq_inventory_reservation_consume_key', columns: ['tenant_id', 'consume_key'])]
class InventoryReservation
{
    public const STATUS_ACTIVE = 'active';
    public const STATUS_RELEASED = 'released';
    public const STATUS_CONSUMED = 'consumed';

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

    #[ORM\ManyToOne(targetEntity: Order::class)]
    #[ORM\JoinColumn(name: 'order_id', referencedColumnName: 'id', nullable: false, onDelete: 'CASCADE')]
    private Order $order;

    #[ORM\ManyToOne(targetEntity: OrderLine::class)]
    #[ORM\JoinColumn(name: 'order_line_id', referencedColumnName: 'id', nullable: false, onDelete: 'CASCADE')]
    private OrderLine $orderLine;

    #[ORM\Column(type: 'integer')]
    private int $quantity;

    #[ORM\Column(type: 'string', length: 20)]
    private string $status = self::STATUS_ACTIVE;

    #[ORM\Column(name: 'reserve_key', type: 'string', length: 120)]
    private string $reserveKey;

    #[ORM\Column(name: 'release_key', type: 'string', length: 120, nullable: true)]
    private ?string $releaseKey = null;

    #[ORM\Column(name: 'consume_key', type: 'string', length: 120, nullable: true)]
    private ?string $consumeKey = null;

    #[ORM\Column(name: 'created_at', type: 'datetime_immutable')]
    private DateTimeImmutable $createdAt;

    #[ORM\Column(name: 'updated_at', type: 'datetime_immutable')]
    private DateTimeImmutable $updatedAt;

    public function __construct(
        OrderLine $orderLine,
        InventorySource $source,
        string $reserveKey,
    ) {
        $order = $orderLine->order();
        if (
            $source->tenant()->id() !== $order->tenant()->id()
            || $source->legalEntity()->id() !== $order->legalEntity()->id()
            || $source->id() !== $order->inventorySource()->id()
            || $orderLine->variant()->tenant()->id() !== $order->tenant()->id()
        ) {
            throw new DomainException(
                'La reserva debe respetar tenant, entidad legal y fuente efectiva del pedido.',
            );
        }
        $reserveKey = self::normalizeKey($reserveKey);

        $this->id = UlidFactory::new();
        $this->tenant = $order->tenant();
        $this->legalEntity = $order->legalEntity();
        $this->source = $source;
        $this->variant = $orderLine->variant();
        $this->order = $order;
        $this->orderLine = $orderLine;
        $this->quantity = $orderLine->quantity();
        $this->reserveKey = $reserveKey;
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
    public function order(): Order
    {
        return $this->order;
    }
    public function orderLine(): OrderLine
    {
        return $this->orderLine;
    }
    public function quantity(): int
    {
        return $this->quantity;
    }
    public function status(): string
    {
        return $this->status;
    }
    public function reserveKey(): string
    {
        return $this->reserveKey;
    }
    public function releaseKey(): ?string
    {
        return $this->releaseKey;
    }
    public function consumeKey(): ?string
    {
        return $this->consumeKey;
    }

    public function markReleased(string $key): bool
    {
        $key = self::normalizeKey($key);
        if ($this->status === self::STATUS_RELEASED) {
            if ($this->releaseKey !== $key) {
                throw new DomainException('La reserva ya fue liberada con otra clave de idempotencia.');
            }

            return false;
        }
        if ($this->status !== self::STATUS_ACTIVE) {
            throw new DomainException('Solo una reserva activa puede liberarse.');
        }

        $this->status = self::STATUS_RELEASED;
        $this->releaseKey = $key;
        $this->touch();

        return true;
    }

    public function markConsumed(string $key): bool
    {
        $key = self::normalizeKey($key);
        if ($this->status === self::STATUS_CONSUMED) {
            if ($this->consumeKey !== $key) {
                throw new DomainException('La reserva ya fue consumida con otra clave de idempotencia.');
            }

            return false;
        }
        if ($this->status !== self::STATUS_ACTIVE) {
            throw new DomainException('Solo una reserva activa puede consumirse.');
        }

        $this->status = self::STATUS_CONSUMED;
        $this->consumeKey = $key;
        $this->touch();

        return true;
    }

    private static function normalizeKey(string $key): string
    {
        $key = trim($key);
        if ($key === '' || mb_strlen($key, 'UTF-8') > 120) {
            throw new DomainException(
                'La clave de idempotencia admite máximo 120 caracteres.',
            );
        }

        return $key;
    }

    private function touch(): void
    {
        $this->updatedAt = new DateTimeImmutable('now', new DateTimeZone('UTC'));
    }
}
