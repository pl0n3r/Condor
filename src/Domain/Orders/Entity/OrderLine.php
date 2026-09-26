<?php

declare(strict_types=1);

namespace App\Domain\Orders\Entity;

use App\Domain\Catalog\Entity\ProductVariant;
use App\Domain\Commerce\Entity\PriceList;
use App\Domain\Organization\Entity\LegalEntity;
use App\Domain\Organization\Entity\Tenant;
use App\Shared\Id\UlidFactory;
use DateTimeImmutable;
use DateTimeZone;
use Doctrine\ORM\Mapping as ORM;
use DomainException;

#[ORM\Entity]
#[ORM\Table(name: 'condor_order_line')]
#[ORM\UniqueConstraint(name: 'uniq_order_line_scope_variant', columns: ['tenant_id', 'order_id', 'variant_id'])]
#[ORM\UniqueConstraint(name: 'uniq_order_line_scope_id', columns: ['tenant_id', 'legal_entity_id', 'order_id', 'id'])]
class OrderLine
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

    #[ORM\ManyToOne(targetEntity: Order::class)]
    #[ORM\JoinColumn(name: 'order_id', referencedColumnName: 'id', nullable: false, onDelete: 'CASCADE')]
    private Order $order;

    #[ORM\ManyToOne(targetEntity: ProductVariant::class)]
    #[ORM\JoinColumn(name: 'variant_id', referencedColumnName: 'id', nullable: false, onDelete: 'RESTRICT')]
    private ProductVariant $variant;

    #[ORM\ManyToOne(targetEntity: PriceList::class)]
    #[ORM\JoinColumn(name: 'price_list_id', referencedColumnName: 'id', nullable: false, onDelete: 'RESTRICT')]
    private PriceList $priceList;

    #[ORM\Column(name: 'price_rule_id', type: 'string', length: 26, nullable: true)]
    private ?string $priceRuleId;

    #[ORM\Column(type: 'integer')]
    private int $quantity;

    #[ORM\Column(name: 'base_amount_minor', type: 'bigint')]
    private int $baseAmountMinor;

    #[ORM\Column(name: 'effective_amount_minor', type: 'bigint')]
    private int $effectiveAmountMinor;

    #[ORM\Column(name: 'line_total_minor', type: 'bigint')]
    private int $lineTotalMinor;

    #[ORM\Column(type: 'string', length: 3)]
    private string $currency;

    #[ORM\Column(name: 'created_at', type: 'datetime_immutable')]
    private DateTimeImmutable $createdAt;

    public function __construct(
        Order $order,
        ProductVariant $variant,
        PriceList $priceList,
        int $quantity,
        int $baseAmountMinor,
        int $effectiveAmountMinor,
        string $currency,
        ?string $priceRuleId,
    ) {
        self::assertScope($order, $variant, $priceList);
        if ($quantity <= 0 || $quantity > 2147483647) {
            throw new DomainException('La cantidad de la línea debe ser mayor que cero.');
        }
        self::assertMoney($baseAmountMinor);
        self::assertMoney($effectiveAmountMinor);
        $currency = strtoupper(trim($currency));
        if ($currency !== $order->currency() || $currency !== $priceList->currency()) {
            throw new DomainException('La moneda de la línea debe coincidir con pedido y lista.');
        }
        if ($priceRuleId !== null && mb_strlen(trim($priceRuleId), 'UTF-8') !== 26) {
            throw new DomainException('El identificador de regla de precio no es válido.');
        }

        if (
            $effectiveAmountMinor !== 0
            && $quantity > intdiv(9007199254740991, $effectiveAmountMinor)
        ) {
            throw new DomainException(
                'El total de la línea está fuera del rango monetario permitido.',
            );
        }

        $lineTotal = $effectiveAmountMinor * $quantity;
        self::assertMoney($lineTotal);

        $this->id = UlidFactory::new();
        $this->tenant = $order->tenant();
        $this->legalEntity = $order->legalEntity();
        $this->order = $order;
        $this->variant = $variant;
        $this->priceList = $priceList;
        $this->priceRuleId = $priceRuleId !== null ? trim($priceRuleId) : null;
        $this->quantity = $quantity;
        $this->baseAmountMinor = $baseAmountMinor;
        $this->effectiveAmountMinor = $effectiveAmountMinor;
        $this->lineTotalMinor = $lineTotal;
        $this->currency = $currency;
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
    public function order(): Order
    {
        return $this->order;
    }
    public function variant(): ProductVariant
    {
        return $this->variant;
    }
    public function priceList(): PriceList
    {
        return $this->priceList;
    }
    public function priceRuleId(): ?string
    {
        return $this->priceRuleId;
    }
    public function quantity(): int
    {
        return $this->quantity;
    }
    public function baseAmountMinor(): int
    {
        return $this->baseAmountMinor;
    }
    public function effectiveAmountMinor(): int
    {
        return $this->effectiveAmountMinor;
    }
    public function lineTotalMinor(): int
    {
        return $this->lineTotalMinor;
    }
    public function currency(): string
    {
        return $this->currency;
    }

    private static function assertScope(
        Order $order,
        ProductVariant $variant,
        PriceList $priceList,
    ): void {
        if (
            $variant->tenant()->id() !== $order->tenant()->id()
            || $priceList->tenant()->id() !== $order->tenant()->id()
        ) {
            throw new DomainException(
                'Pedido, variante y lista de precios deben pertenecer al mismo tenant.',
            );
        }
    }

    private static function assertMoney(int $amount): void
    {
        if ($amount < 0 || $amount > 9007199254740991) {
            throw new DomainException('El importe de la línea está fuera del rango permitido.');
        }
    }
}
