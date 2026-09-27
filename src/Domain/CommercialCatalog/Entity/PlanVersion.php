<?php

declare(strict_types=1);

namespace App\Domain\CommercialCatalog\Entity;

use App\Shared\Id\UlidFactory;
use DateTimeImmutable;
use DateTimeZone;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;
use DomainException;

#[ORM\Entity]
#[ORM\Table(name: 'condor_commercial_plan_version')]
#[ORM\UniqueConstraint(
    name: 'uniq_commercial_plan_version',
    columns: ['plan_id', 'version_number'],
)]
#[ORM\Index(name: 'idx_commercial_plan_version_validity', columns: ['valid_from', 'valid_until'])]
final class PlanVersion
{
    public const PRICING_FIXED = 'fixed';
    public const PRICING_FROM = 'from';
    public const PRICING_QUOTE = 'quote';

    #[ORM\Id]
    #[ORM\Column(type: 'string', length: 26)]
    private string $id;

    #[ORM\ManyToOne(targetEntity: Plan::class)]
    #[ORM\JoinColumn(
        name: 'plan_id',
        referencedColumnName: 'id',
        nullable: false,
        onDelete: 'RESTRICT',
    )]
    private Plan $plan;

    #[ORM\Column(name: 'version_number', type: 'integer')]
    private int $versionNumber;

    #[ORM\Column(name: 'pricing_mode', type: 'string', length: 16)]
    private string $pricingMode;

    #[ORM\Column(type: 'string', length: 3)]
    private string $currency;

    #[ORM\Column(name: 'monthly_price_cop', type: 'bigint', nullable: true)]
    private ?int $monthlyPriceCop;

    #[ORM\Column(name: 'annual_price_cop', type: 'bigint', nullable: true)]
    private ?int $annualPriceCop;

    /** @var array<string, int|bool|string|null> */
    #[ORM\Column(type: 'json')]
    private array $limits;

    #[ORM\Column(name: 'valid_from', type: 'datetime_immutable')]
    private DateTimeImmutable $validFrom;

    #[ORM\Column(name: 'valid_until', type: 'datetime_immutable', nullable: true)]
    private ?DateTimeImmutable $validUntil;

    #[ORM\Column(name: 'available_for_sale', type: 'boolean', options: ['default' => true])]
    private bool $availableForSale;

    #[ORM\Column(name: 'created_at', type: 'datetime_immutable')]
    private DateTimeImmutable $createdAt;

    /** @var Collection<int, Vertical> */
    #[ORM\ManyToMany(targetEntity: Vertical::class)]
    #[ORM\JoinTable(name: 'condor_commercial_plan_version_vertical')]
    #[ORM\JoinColumn(name: 'plan_version_id', referencedColumnName: 'id', onDelete: 'CASCADE')]
    #[ORM\InverseJoinColumn(name: 'vertical_id', referencedColumnName: 'id', onDelete: 'CASCADE')]
    private Collection $verticals;

    /** @var Collection<int, Capability> */
    #[ORM\ManyToMany(targetEntity: Capability::class)]
    #[ORM\JoinTable(name: 'condor_commercial_plan_version_capability')]
    #[ORM\JoinColumn(name: 'plan_version_id', referencedColumnName: 'id', onDelete: 'CASCADE')]
    #[ORM\InverseJoinColumn(name: 'capability_id', referencedColumnName: 'id', onDelete: 'CASCADE')]
    private Collection $capabilities;

    /** @var Collection<int, AddOn> */
    #[ORM\ManyToMany(targetEntity: AddOn::class)]
    #[ORM\JoinTable(name: 'condor_commercial_plan_version_addon')]
    #[ORM\JoinColumn(name: 'plan_version_id', referencedColumnName: 'id', onDelete: 'CASCADE')]
    #[ORM\InverseJoinColumn(name: 'addon_id', referencedColumnName: 'id', onDelete: 'CASCADE')]
    private Collection $addOns;

    /**
     * @param array<string, int|bool|string|null> $limits
     */
    public function __construct(
        Plan $plan,
        int $versionNumber,
        string $pricingMode,
        ?int $monthlyPriceCop,
        ?int $annualPriceCop,
        DateTimeImmutable $validFrom,
        ?DateTimeImmutable $validUntil = null,
        array $limits = [],
        bool $availableForSale = true,
        string $currency = 'COP',
    ) {
        if ($versionNumber < 1) {
            throw new DomainException('La versión comercial debe ser positiva.');
        }
        if ($validUntil !== null && $validUntil <= $validFrom) {
            throw new DomainException('La vigencia final debe ser posterior al inicio.');
        }

        $pricingMode = strtolower(trim($pricingMode));
        if (!in_array(
            $pricingMode,
            [self::PRICING_FIXED, self::PRICING_FROM, self::PRICING_QUOTE],
            true,
        )) {
            throw new DomainException('El modo de precio del plan no es válido.');
        }

        $currency = mb_strtoupper(trim($currency), 'UTF-8');
        if ($currency !== 'COP') {
            throw new DomainException('Commercial Catalog V1 solo admite COP.');
        }
        if (
            ($monthlyPriceCop !== null && $monthlyPriceCop < 0)
            || ($annualPriceCop !== null && $annualPriceCop < 0)
        ) {
            throw new DomainException('Los precios del plan no pueden ser negativos.');
        }
        if ($pricingMode === self::PRICING_FIXED && $monthlyPriceCop === null) {
            throw new DomainException('Un plan de precio fijo requiere precio mensual.');
        }

        $this->id = UlidFactory::new();
        $this->plan = $plan;
        $this->versionNumber = $versionNumber;
        $this->pricingMode = $pricingMode;
        $this->currency = $currency;
        $this->monthlyPriceCop = $monthlyPriceCop;
        $this->annualPriceCop = $annualPriceCop;
        $this->limits = $limits;
        $this->validFrom = $validFrom;
        $this->validUntil = $validUntil;
        $this->availableForSale = $availableForSale;
        $this->createdAt = new DateTimeImmutable('now', new DateTimeZone('UTC'));
        $this->verticals = new ArrayCollection();
        $this->capabilities = new ArrayCollection();
        $this->addOns = new ArrayCollection();
    }

    public function id(): string
    {
        return $this->id;
    }

    public function plan(): Plan
    {
        return $this->plan;
    }

    public function versionNumber(): int
    {
        return $this->versionNumber;
    }

    public function pricingMode(): string
    {
        return $this->pricingMode;
    }

    public function currency(): string
    {
        return $this->currency;
    }

    public function monthlyPriceCop(): ?int
    {
        return $this->monthlyPriceCop;
    }

    public function annualPriceCop(): ?int
    {
        return $this->annualPriceCop;
    }

    /** @return array<string, int|bool|string|null> */
    public function limits(): array
    {
        return $this->limits;
    }

    public function validFrom(): DateTimeImmutable
    {
        return $this->validFrom;
    }

    public function validUntil(): ?DateTimeImmutable
    {
        return $this->validUntil;
    }

    public function isAvailableForSale(): bool
    {
        return $this->availableForSale;
    }

    public function isEffectiveAt(DateTimeImmutable $at): bool
    {
        if (!$this->availableForSale || !$this->plan->isActive()) {
            return false;
        }
        if ($at < $this->validFrom) {
            return false;
        }

        return $this->validUntil === null || $at < $this->validUntil;
    }

    public function addVertical(Vertical $vertical): void
    {
        foreach ($this->verticals as $current) {
            if ($current->key() === $vertical->key()) {
                return;
            }
        }
        $this->verticals->add($vertical);
    }

    public function addCapability(Capability $capability): void
    {
        foreach ($this->capabilities as $current) {
            if ($current->key() === $capability->key()) {
                return;
            }
        }
        $this->capabilities->add($capability);
    }

    public function addAddOn(AddOn $addOn): void
    {
        foreach ($this->addOns as $current) {
            if ($current->key() === $addOn->key()) {
                return;
            }
        }
        $this->addOns->add($addOn);
    }

    /** @return list<Vertical> */
    public function verticals(): array
    {
        return array_values($this->verticals->toArray());
    }

    /** @return list<Capability> */
    public function capabilities(): array
    {
        return array_values($this->capabilities->toArray());
    }

    /** @return list<AddOn> */
    public function addOns(): array
    {
        return array_values($this->addOns->toArray());
    }
}
