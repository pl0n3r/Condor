<?php

declare(strict_types=1);

namespace App\Domain\Commerce\Entity;

use App\Domain\Organization\Entity\Tenant;
use App\Shared\Id\UlidFactory;
use DateTimeImmutable;
use DateTimeZone;
use Doctrine\ORM\Mapping as ORM;
use DomainException;

#[ORM\Entity]
#[ORM\Table(name: 'condor_price_rule')]
class PriceRule
{
    public const TYPE_PERCENTAGE = 'percentage';
    public const TYPE_FIXED = 'fixed';

    #[ORM\Id]
    #[ORM\Column(type: 'string', length: 26)]
    private string $id;

    #[ORM\ManyToOne(targetEntity: Tenant::class)]
    #[ORM\JoinColumn(name: 'tenant_id', referencedColumnName: 'id', nullable: false, onDelete: 'CASCADE')]
    private Tenant $tenant;

    #[ORM\ManyToOne(targetEntity: PriceList::class)]
    #[ORM\JoinColumn(name: 'price_list_id', referencedColumnName: 'id', nullable: false, onDelete: 'CASCADE')]
    private PriceList $priceList;

    #[ORM\ManyToOne(targetEntity: CommercialCategory::class)]
    #[ORM\JoinColumn(name: 'commercial_category_id', referencedColumnName: 'id', nullable: true, onDelete: 'RESTRICT')]
    private ?CommercialCategory $commercialCategory;

    #[ORM\Column(type: 'string', length: 160)]
    private string $name;

    #[ORM\Column(type: 'integer')]
    private int $priority;

    #[ORM\Column(name: 'discount_type', type: 'string', length: 20)]
    private string $discountType;

    #[ORM\Column(name: 'discount_value', type: 'bigint')]
    private int $discountValue;

    #[ORM\Column(name: 'valid_from', type: 'datetime_immutable', nullable: true)]
    private ?DateTimeImmutable $validFrom;

    #[ORM\Column(name: 'valid_until', type: 'datetime_immutable', nullable: true)]
    private ?DateTimeImmutable $validUntil;

    #[ORM\Column(type: 'boolean')]
    private bool $active = true;

    #[ORM\Column(name: 'created_at', type: 'datetime_immutable')]
    private DateTimeImmutable $createdAt;

    #[ORM\Column(name: 'updated_at', type: 'datetime_immutable')]
    private DateTimeImmutable $updatedAt;

    public function __construct(
        Tenant $tenant,
        PriceList $priceList,
        string $name,
        int $priority,
        string $discountType,
        int $discountValue,
        ?CommercialCategory $commercialCategory = null,
        ?DateTimeImmutable $validFrom = null,
        ?DateTimeImmutable $validUntil = null,
    ) {
        if ($priceList->tenant()->id() !== $tenant->id()) {
            throw new DomainException('La regla y la lista de precios deben pertenecer al mismo tenant.');
        }
        if ($commercialCategory !== null && $commercialCategory->tenant()->id() !== $tenant->id()) {
            throw new DomainException('La categoría de la regla debe pertenecer al mismo tenant.');
        }
        if ($validFrom !== null && $validUntil !== null && $validUntil < $validFrom) {
            throw new DomainException('La vigencia final de la regla no puede preceder su inicio.');
        }

        $discountType = strtolower(trim($discountType));
        if (!in_array($discountType, [self::TYPE_PERCENTAGE, self::TYPE_FIXED], true)) {
            throw new DomainException('El tipo de descuento no es válido.');
        }
        if ($discountValue < 0 || ($discountType === self::TYPE_PERCENTAGE && $discountValue > 10000)) {
            throw new DomainException('El valor del descuento está fuera del rango permitido.');
        }

        $name = trim($name);
        if ($name === '' || mb_strlen($name, 'UTF-8') > 160) {
            throw new DomainException('El nombre de la regla es obligatorio y admite máximo 160 caracteres.');
        }

        $this->id = UlidFactory::new();
        $this->tenant = $tenant;
        $this->priceList = $priceList;
        $this->commercialCategory = $commercialCategory;
        $this->name = $name;
        $this->priority = $priority;
        $this->discountType = $discountType;
        $this->discountValue = $discountValue;
        $this->validFrom = $validFrom;
        $this->validUntil = $validUntil;
        $this->createdAt = new DateTimeImmutable('now', new DateTimeZone('UTC'));
        $this->updatedAt = $this->createdAt;
    }

    public function id(): string { return $this->id; }
    public function tenant(): Tenant { return $this->tenant; }
    public function priceList(): PriceList { return $this->priceList; }
    public function commercialCategory(): ?CommercialCategory { return $this->commercialCategory; }
    public function priority(): int { return $this->priority; }
    public function isActive(): bool { return $this->active; }

    public function appliesTo(?CommercialCategory $category, DateTimeImmutable $at): bool
    {
        if (!$this->active) {
            return false;
        }
        if ($this->validFrom !== null && $at < $this->validFrom) {
            return false;
        }
        if ($this->validUntil !== null && $at > $this->validUntil) {
            return false;
        }

        return $this->commercialCategory === null
            || ($category !== null && $category->id() === $this->commercialCategory->id());
    }

    public function specificity(): int
    {
        return $this->commercialCategory === null ? 0 : 1;
    }

    public function applyTo(int $baseAmountMinor): int
    {
        if ($baseAmountMinor < 0) {
            throw new DomainException('El precio base no puede ser negativo.');
        }

        $discount = $this->discountType === self::TYPE_FIXED
            ? min($baseAmountMinor, $this->discountValue)
            : min(
                $baseAmountMinor,
                intdiv($baseAmountMinor, 10000) * $this->discountValue
                + intdiv(($baseAmountMinor % 10000) * $this->discountValue, 10000),
            );

        return $baseAmountMinor - $discount;
    }
}
