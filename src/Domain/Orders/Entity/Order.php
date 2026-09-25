<?php

declare(strict_types=1);

namespace App\Domain\Orders\Entity;

use App\Domain\Commerce\Entity\Customer;
use App\Domain\Commerce\Entity\SalesChannel;
use App\Domain\Inventory\Entity\InventorySource;
use App\Domain\Organization\Entity\LegalEntity;
use App\Domain\Organization\Entity\Tenant;
use App\Shared\Id\UlidFactory;
use DateTimeImmutable;
use DateTimeZone;
use Doctrine\ORM\Mapping as ORM;
use DomainException;

#[ORM\Entity]
#[ORM\Table(name: 'condor_order')]
#[ORM\UniqueConstraint(name: 'uniq_order_tenant_key', columns: ['tenant_id', 'idempotency_key'])]
#[ORM\UniqueConstraint(name: 'uniq_order_tenant_id', columns: ['tenant_id', 'id'])]
#[ORM\UniqueConstraint(name: 'uniq_order_scope_id', columns: ['tenant_id', 'legal_entity_id', 'id'])]
class Order
{
    public const STATUS_OPEN = 'open';
    public const STATUS_CANCELLED = 'cancelled';
    public const PAYMENT_PENDING = 'pending';
    public const PAYMENT_PAID = 'paid';
    public const FULFILLMENT_RESERVED = 'reserved';
    public const FULFILLMENT_RELEASED = 'released';
    public const FULFILLMENT_CONSUMED = 'consumed';

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
    #[ORM\JoinColumn(name: 'inventory_source_id', referencedColumnName: 'id', nullable: false, onDelete: 'RESTRICT')]
    private InventorySource $inventorySource;

    #[ORM\ManyToOne(targetEntity: SalesChannel::class)]
    #[ORM\JoinColumn(name: 'sales_channel_id', referencedColumnName: 'id', nullable: true, onDelete: 'RESTRICT')]
    private ?SalesChannel $salesChannel;

    #[ORM\ManyToOne(targetEntity: Customer::class)]
    #[ORM\JoinColumn(name: 'customer_id', referencedColumnName: 'id', nullable: true, onDelete: 'RESTRICT')]
    private ?Customer $customer;

    #[ORM\Column(name: 'actor_user_id', type: 'string', length: 26, nullable: true)]
    private ?string $actorUserId;

    #[ORM\Column(name: 'idempotency_key', type: 'string', length: 120)]
    private string $idempotencyKey;

    #[ORM\Column(name: 'order_status', type: 'string', length: 20)]
    private string $orderStatus = self::STATUS_OPEN;

    #[ORM\Column(name: 'payment_status', type: 'string', length: 20)]
    private string $paymentStatus = self::PAYMENT_PENDING;

    #[ORM\Column(name: 'fulfillment_status', type: 'string', length: 20)]
    private string $fulfillmentStatus = self::FULFILLMENT_RESERVED;

    #[ORM\Column(type: 'string', length: 3)]
    private string $currency;

    #[ORM\Column(name: 'total_amount_minor', type: 'bigint')]
    private int $totalAmountMinor;

    #[ORM\Column(name: 'created_at', type: 'datetime_immutable')]
    private DateTimeImmutable $createdAt;

    #[ORM\Column(name: 'updated_at', type: 'datetime_immutable')]
    private DateTimeImmutable $updatedAt;

    #[ORM\Column(name: 'expires_at', type: 'datetime_immutable', nullable: true)]
    private ?DateTimeImmutable $expiresAt;

    public function __construct(
        Tenant $tenant,
        InventorySource $inventorySource,
        ?SalesChannel $salesChannel,
        ?Customer $customer,
        ?string $actorUserId,
        string $idempotencyKey,
        string $currency,
        int $totalAmountMinor,
        ?DateTimeImmutable $expiresAt = null,
    ) {
        self::assertScope($tenant, $inventorySource, $salesChannel, $customer);
        $idempotencyKey = trim($idempotencyKey);
        if ($idempotencyKey === '' || mb_strlen($idempotencyKey, 'UTF-8') > 120) {
            throw new DomainException(
                'La clave de idempotencia del pedido es obligatoria y admite máximo 120 caracteres.',
            );
        }
        $currency = strtoupper(trim($currency));
        if (!preg_match('/^[A-Z]{3}$/D', $currency)) {
            throw new DomainException('La moneda del pedido no es válida.');
        }
        self::assertMoney($totalAmountMinor);

        $this->id = UlidFactory::new();
        $this->tenant = $tenant;
        $this->legalEntity = $inventorySource->legalEntity();
        $this->inventorySource = $inventorySource;
        $this->salesChannel = $salesChannel;
        $this->customer = $customer;
        $this->actorUserId = $actorUserId;
        $this->idempotencyKey = $idempotencyKey;
        $this->currency = $currency;
        $this->totalAmountMinor = $totalAmountMinor;
        $this->createdAt = new DateTimeImmutable('now', new DateTimeZone('UTC'));
        $this->updatedAt = $this->createdAt;
        $this->expiresAt = $expiresAt;
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
    public function inventorySource(): InventorySource
    {
        return $this->inventorySource;
    }
    public function salesChannel(): ?SalesChannel
    {
        return $this->salesChannel;
    }
    public function customer(): ?Customer
    {
        return $this->customer;
    }
    public function actorUserId(): ?string
    {
        return $this->actorUserId;
    }
    public function idempotencyKey(): string
    {
        return $this->idempotencyKey;
    }
    public function orderStatus(): string
    {
        return $this->orderStatus;
    }
    public function paymentStatus(): string
    {
        return $this->paymentStatus;
    }
    public function fulfillmentStatus(): string
    {
        return $this->fulfillmentStatus;
    }
    public function currency(): string
    {
        return $this->currency;
    }
    public function totalAmountMinor(): int
    {
        return $this->totalAmountMinor;
    }
    public function cancelAndRelease(): bool
    {
        if (
            $this->orderStatus === self::STATUS_CANCELLED
            && $this->fulfillmentStatus === self::FULFILLMENT_RELEASED
        ) {
            return false;
        }
        if ($this->fulfillmentStatus === self::FULFILLMENT_CONSUMED) {
            throw new DomainException(
                'No se puede cancelar un pedido cuyo inventario ya fue consumido.',
            );
        }
        if ($this->fulfillmentStatus !== self::FULFILLMENT_RESERVED) {
            throw new DomainException(
                'Solo un pedido reservado puede cancelarse y liberar inventario.',
            );
        }

        $this->orderStatus = self::STATUS_CANCELLED;
        $this->fulfillmentStatus = self::FULFILLMENT_RELEASED;
        $this->touch();

        return true;
    }

    public function markReleased(): bool
    {
        if ($this->fulfillmentStatus === self::FULFILLMENT_RELEASED) {
            return false;
        }
        if ($this->fulfillmentStatus !== self::FULFILLMENT_RESERVED) {
            throw new DomainException(
                'Solo un pedido reservado puede liberar inventario.',
            );
        }

        $this->fulfillmentStatus = self::FULFILLMENT_RELEASED;
        $this->touch();

        return true;
    }

    public function markConsumed(): bool
    {
        if ($this->fulfillmentStatus === self::FULFILLMENT_CONSUMED) {
            return false;
        }
        if ($this->orderStatus !== self::STATUS_OPEN) {
            throw new DomainException(
                'No se puede consumir inventario de un pedido cancelado.',
            );
        }
        if ($this->fulfillmentStatus !== self::FULFILLMENT_RESERVED) {
            throw new DomainException(
                'Solo un pedido reservado puede consumir inventario.',
            );
        }

        $this->fulfillmentStatus = self::FULFILLMENT_CONSUMED;
        $this->touch();

        return true;
    }

    private function touch(): void
    {
        $this->updatedAt = new DateTimeImmutable('now', new DateTimeZone('UTC'));
    }

    public function createdAt(): DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function expiresAt(): ?DateTimeImmutable
    {
        return $this->expiresAt;
    }

    private static function assertScope(
        Tenant $tenant,
        InventorySource $inventorySource,
        ?SalesChannel $salesChannel,
        ?Customer $customer,
    ): void {
        if ($inventorySource->tenant()->id() !== $tenant->id()) {
            throw new DomainException('La fuente del pedido debe pertenecer al mismo tenant.');
        }
        if (
            $salesChannel !== null
            && (
                $salesChannel->tenant()->id() !== $tenant->id()
                || $salesChannel->legalEntity()->id() !== $inventorySource->legalEntity()->id()
                || $salesChannel->inventorySource()->id() !== $inventorySource->id()
            )
        ) {
            throw new DomainException(
                'El canal y la fuente del pedido deben compartir tenant, entidad legal y fuente efectiva.',
            );
        }
        if ($customer !== null && $customer->tenant()->id() !== $tenant->id()) {
            throw new DomainException('El cliente del pedido debe pertenecer al mismo tenant.');
        }
    }

    private static function assertMoney(int $amount): void
    {
        if ($amount < 0 || $amount > 9007199254740991) {
            throw new DomainException('El total del pedido está fuera del rango monetario permitido.');
        }
    }
}
