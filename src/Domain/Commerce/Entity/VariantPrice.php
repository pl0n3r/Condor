<?php

declare(strict_types=1);

namespace App\Domain\Commerce\Entity;

use App\Domain\Catalog\Entity\ProductVariant;
use App\Domain\Organization\Entity\Tenant;
use App\Shared\Id\UlidFactory;
use DateTimeImmutable;
use DateTimeZone;
use Doctrine\ORM\Mapping as ORM;
use DomainException;

#[ORM\Entity]
#[ORM\Table(name: 'condor_variant_price')]
#[ORM\UniqueConstraint(name: 'uniq_variant_price_scope', columns: ['tenant_id', 'price_list_id', 'variant_id'])]
class VariantPrice
{
    #[ORM\Id]
    #[ORM\Column(type: 'string', length: 26)]
    private string $id;

    #[ORM\ManyToOne(targetEntity: Tenant::class)]
    #[ORM\JoinColumn(name: 'tenant_id', referencedColumnName: 'id', nullable: false, onDelete: 'CASCADE')]
    private Tenant $tenant;

    #[ORM\ManyToOne(targetEntity: PriceList::class)]
    #[ORM\JoinColumn(name: 'price_list_id', referencedColumnName: 'id', nullable: false, onDelete: 'CASCADE')]
    private PriceList $priceList;

    #[ORM\ManyToOne(targetEntity: ProductVariant::class)]
    #[ORM\JoinColumn(name: 'variant_id', referencedColumnName: 'id', nullable: false, onDelete: 'CASCADE')]
    private ProductVariant $variant;

    #[ORM\Column(name: 'amount_minor', type: 'bigint')]
    private int $amountMinor;

    #[ORM\Column(name: 'created_at', type: 'datetime_immutable')]
    private DateTimeImmutable $createdAt;

    #[ORM\Column(name: 'updated_at', type: 'datetime_immutable')]
    private DateTimeImmutable $updatedAt;

    public function __construct(Tenant $tenant, PriceList $priceList, ProductVariant $variant, int $amountMinor)
    {
        if ($priceList->tenant()->id() !== $tenant->id() || $variant->tenant()->id() !== $tenant->id()) {
            throw new DomainException('La lista, la variante y el precio deben pertenecer al mismo tenant.');
        }

        if ($amountMinor < 0) {
            throw new DomainException('El precio no puede ser negativo.');
        }

        $this->id = UlidFactory::new();
        $this->tenant = $tenant;
        $this->priceList = $priceList;
        $this->variant = $variant;
        $this->amountMinor = $amountMinor;
        $this->createdAt = new DateTimeImmutable('now', new DateTimeZone('UTC'));
        $this->updatedAt = $this->createdAt;
    }

    public function id(): string { return $this->id; }
    public function tenant(): Tenant { return $this->tenant; }
    public function priceList(): PriceList { return $this->priceList; }
    public function variant(): ProductVariant { return $this->variant; }
    public function amountMinor(): int { return $this->amountMinor; }

    public function updateAmount(int $amountMinor): void
    {
        if ($amountMinor < 0) {
            throw new DomainException('El precio no puede ser negativo.');
        }

        $this->amountMinor = $amountMinor;
        $this->updatedAt = new DateTimeImmutable('now', new DateTimeZone('UTC'));
    }
}
