<?php

declare(strict_types=1);

namespace App\Domain\Commercial\Entity;

use App\Shared\Id\UlidFactory;
use DateTimeImmutable;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;
use DomainException;

#[ORM\Entity]
#[ORM\Table(name: 'condor_commercial_plan_version')]
#[ORM\UniqueConstraint(name: 'uniq_commercial_plan_version', columns: ['plan_id', 'version_number'])]
#[ORM\Index(name: 'idx_commercial_plan_effective', columns: ['plan_id', 'effective_from', 'effective_until'])]
final class PlanVersion
{
    #[ORM\Id]
    #[ORM\Column(type: 'string', length: 26)]
    private string $id;

    #[ORM\ManyToOne(targetEntity: Plan::class)]
    #[ORM\JoinColumn(name: 'plan_id', referencedColumnName: 'id', nullable: false, onDelete: 'CASCADE')]
    private Plan $plan;

    #[ORM\Column(name: 'version_number', type: 'integer', options: ['unsigned' => true])]
    private int $version;

    #[ORM\Column(type: 'string', length: 3)]
    private string $currency;

    #[ORM\Column(name: 'monthly_amount', type: 'integer', nullable: true, options: ['unsigned' => true])]
    private ?int $monthlyAmount;

    #[ORM\Column(name: 'annual_amount', type: 'integer', nullable: true, options: ['unsigned' => true])]
    private ?int $annualAmount;

    #[ORM\Column(name: 'quote_required', type: 'boolean')]
    private bool $quoteRequired;

    /** @var array<string, bool|int|string|null> */
    #[ORM\Column(type: 'json')]
    private array $limits;

    #[ORM\Column(name: 'effective_from', type: 'datetime_immutable')]
    private DateTimeImmutable $effectiveFrom;

    #[ORM\Column(name: 'effective_until', type: 'datetime_immutable', nullable: true)]
    private ?DateTimeImmutable $effectiveUntil;

    /** @var Collection<int, Vertical> */
    #[ORM\ManyToMany(targetEntity: Vertical::class)]
    #[ORM\JoinTable(name: 'condor_commercial_plan_version_vertical')]
    #[ORM\JoinColumn(name: 'plan_version_id', referencedColumnName: 'id', nullable: false, onDelete: 'CASCADE')]
    #[ORM\InverseJoinColumn(name: 'vertical_id', referencedColumnName: 'id', nullable: false, onDelete: 'CASCADE')]
    private Collection $verticals;

    /** @var Collection<int, Capability> */
    #[ORM\ManyToMany(targetEntity: Capability::class)]
    #[ORM\JoinTable(name: 'condor_commercial_plan_version_capability')]
    #[ORM\JoinColumn(name: 'plan_version_id', referencedColumnName: 'id', nullable: false, onDelete: 'CASCADE')]
    #[ORM\InverseJoinColumn(name: 'capability_id', referencedColumnName: 'id', nullable: false, onDelete: 'CASCADE')]
    private Collection $capabilities;

    /** @var Collection<int, AddOn> */
    #[ORM\ManyToMany(targetEntity: AddOn::class)]
    #[ORM\JoinTable(name: 'condor_commercial_plan_version_addon')]
    #[ORM\JoinColumn(name: 'plan_version_id', referencedColumnName: 'id', nullable: false, onDelete: 'CASCADE')]
    #[ORM\InverseJoinColumn(name: 'addon_id', referencedColumnName: 'id', nullable: false, onDelete: 'CASCADE')]
    private Collection $addOns;

    /**
     * @param array<array-key, mixed> $limits
     */
    public function __construct(
        Plan $plan,
        int $version,
        ?int $monthlyAmount,
        ?int $annualAmount,
        bool $quoteRequired,
        array $limits,
        DateTimeImmutable $effectiveFrom,
        ?DateTimeImmutable $effectiveUntil = null,
        string $currency = 'COP',
    ) {
        if ($version < 1) {
            throw new DomainException('La versión comercial debe ser positiva.');
        }

        $currency = strtoupper(trim($currency));
        if (preg_match('/^[A-Z]{3}$/D', $currency) !== 1) {
            throw new DomainException('Moneda comercial inválida.');
        }

        if ($quoteRequired) {
            if ($monthlyAmount !== null || $annualAmount !== null) {
                throw new DomainException('Una versión cotizable no define precio.');
            }
        } elseif (
            $monthlyAmount === null
            || $monthlyAmount < 1
            || ($annualAmount !== null && $annualAmount < 1)
        ) {
            throw new DomainException('El precio mensual debe ser positivo.');
        }

        if ($effectiveUntil !== null && $effectiveUntil <= $effectiveFrom) {
            throw new DomainException('Vigencia comercial inválida.');
        }

        $this->id = UlidFactory::new();
        $this->plan = $plan;
        $this->version = $version;
        $this->monthlyAmount = $monthlyAmount;
        $this->annualAmount = $annualAmount;
        $this->quoteRequired = $quoteRequired;
        $this->limits = self::normalizeLimits($limits);
        $this->effectiveFrom = $effectiveFrom;
        $this->effectiveUntil = $effectiveUntil;
        $this->currency = $currency;
        $this->verticals = new ArrayCollection();
        $this->capabilities = new ArrayCollection();
        $this->addOns = new ArrayCollection();
    }

    public function id(): string { return $this->id; }
    public function plan(): Plan { return $this->plan; }
    public function version(): int { return $this->version; }
    public function currency(): string { return $this->currency; }
    public function monthlyAmount(): ?int { return $this->monthlyAmount; }
    public function annualAmount(): ?int { return $this->annualAmount; }
    public function quoteRequired(): bool { return $this->quoteRequired; }
    /** @return array<string, bool|int|string|null> */
    public function limits(): array { return $this->limits; }
    public function effectiveFrom(): DateTimeImmutable { return $this->effectiveFrom; }
    public function effectiveUntil(): ?DateTimeImmutable { return $this->effectiveUntil; }

    public function isEffectiveAt(DateTimeImmutable $at): bool
    {
        return $at >= $this->effectiveFrom
            && ($this->effectiveUntil === null || $at < $this->effectiveUntil);
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

    /** @return list<Vertical> */
    public function verticals(): array
    {
        return array_values($this->verticals->toArray());
    }

    public function addCapability(Capability $capability): void
    {
        foreach ($this->capabilities as $current) {
            if ($current->key() === $capability->key()) return;
        }
        $this->capabilities->add($capability);
    }

    /** @return list<Capability> */
    public function capabilities(): array
    {
        return array_values($this->capabilities->toArray());
    }

    public function addAddOn(AddOn $addOn): void
    {
        foreach ($this->addOns as $current) {
            if ($current->key() === $addOn->key()) return;
        }
        $this->addOns->add($addOn);
    }

    /** @return list<AddOn> */
    public function addOns(): array
    {
        return array_values($this->addOns->toArray());
    }

    /**
     * @param array<array-key, mixed> $limits
     * @return array<string, bool|int|string|null>
     */
    private static function normalizeLimits(array $limits): array
    {
        if (count($limits) > 50 || ($limits !== [] && array_is_list($limits))) {
            throw new DomainException('Mapa de límites comerciales inválido.');
        }

        foreach ($limits as $key => $value) {
            if (
                !is_string($key)
                || preg_match('/^[a-z][a-z0-9_]{1,63}$/D', $key) !== 1
                || (!is_bool($value) && !is_int($value) && !is_string($value) && $value !== null)
                || (is_int($value) && $value < 0)
                || (is_string($value) && mb_strlen($value, 'UTF-8') > 120)
            ) {
                throw new DomainException('Límite comercial inválido.');
            }
        }
        ksort($limits);

        return $limits;
    }
}
