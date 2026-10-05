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
#[ORM\Table(name: 'condor_commercial_subscription_configuration')]
#[ORM\UniqueConstraint(
    name: 'uniq_commercial_subscription_configuration_subscription',
    columns: ['subscription_id'],
)]
final class SubscriptionConfiguration
{
    #[ORM\Id]
    #[ORM\Column(type: 'string', length: 26)]
    private string $id;

    #[ORM\ManyToOne(targetEntity: Subscription::class)]
    #[ORM\JoinColumn(
        name: 'subscription_id',
        referencedColumnName: 'id',
        nullable: false,
        onDelete: 'CASCADE',
    )]
    private Subscription $subscription;

    #[ORM\ManyToOne(targetEntity: Vertical::class)]
    #[ORM\JoinColumn(
        name: 'vertical_id',
        referencedColumnName: 'id',
        nullable: false,
        onDelete: 'RESTRICT',
    )]
    private Vertical $vertical;

    /** @var array<string,int> */
    #[ORM\Column(type: 'json')]
    private array $quantities;

    /** @var Collection<int,AddOn> */
    #[ORM\ManyToMany(targetEntity: AddOn::class)]
    #[ORM\JoinTable(name: 'condor_commercial_subscription_configuration_addon')]
    #[ORM\JoinColumn(
        name: 'subscription_configuration_id',
        referencedColumnName: 'id',
        onDelete: 'CASCADE',
    )]
    #[ORM\InverseJoinColumn(
        name: 'addon_id',
        referencedColumnName: 'id',
        onDelete: 'RESTRICT',
    )]
    private Collection $addOns;

    #[ORM\Column(
        name: 'configured_at',
        type: 'datetime_immutable',
        columnDefinition: 'DATETIME(6) NOT NULL',
    )]
    private DateTimeImmutable $configuredAt;

    #[ORM\Column(
        name: 'updated_at',
        type: 'datetime_immutable',
        columnDefinition: 'DATETIME(6) NOT NULL',
    )]
    private DateTimeImmutable $updatedAt;

    #[ORM\Version]
    #[ORM\Column(
        name: 'lock_version',
        type: 'integer',
        options: ['unsigned' => true, 'default' => 1],
    )]
    private int $lockVersion = 1;

    /**
     * @param array<array-key,mixed> $quantities
     * @param array<array-key,mixed> $addOns
     */
    public function __construct(
        Subscription $subscription,
        Vertical $vertical,
        array $quantities,
        array $addOns,
        DateTimeImmutable $configuredAt,
    ) {
        self::assertVerticalAllowed($subscription->planVersion(), $vertical);

        $this->id = UlidFactory::new();
        $this->subscription = $subscription;
        $this->vertical = $vertical;
        $this->quantities = self::normalizeQuantities(
            $subscription->planVersion(),
            $quantities,
        );
        $this->addOns = new ArrayCollection(
            self::normalizeAddOns(
                $subscription->planVersion(),
                $addOns,
            ),
        );
        $this->configuredAt = $configuredAt;
        $this->updatedAt = $configuredAt;
    }

    public function id(): string
    {
        return $this->id;
    }

    public function subscription(): Subscription
    {
        return $this->subscription;
    }

    public function vertical(): Vertical
    {
        return $this->vertical;
    }

    /** @return array<string,int> */
    public function quantities(): array
    {
        return $this->quantities;
    }

    /** @return list<AddOn> */
    public function addOns(): array
    {
        $addOns = array_values($this->addOns->toArray());
        usort(
            $addOns,
            static fn (AddOn $left, AddOn $right): int =>
                $left->key() <=> $right->key(),
        );

        return $addOns;
    }

    public function configuredAt(): DateTimeImmutable
    {
        return $this->configuredAt;
    }

    public function updatedAt(): DateTimeImmutable
    {
        return $this->updatedAt;
    }

    public function lockVersion(): int
    {
        return $this->lockVersion;
    }

    private static function assertVerticalAllowed(
        PlanVersion $planVersion,
        Vertical $vertical,
    ): void {
        if (!$vertical->isActive()) {
            throw new DomainException('Vertical comercial inactivo.');
        }

        foreach ($planVersion->verticals() as $allowed) {
            if (
                $allowed->id() === $vertical->id()
                && $allowed->isActive()
            ) {
                return;
            }
        }

        throw new DomainException(
            'Vertical incompatible con la PlanVersion de la suscripción.',
        );
    }

    /**
     * @param array<array-key,mixed> $quantities
     * @return array<string,int>
     */
    private static function normalizeQuantities(
        PlanVersion $planVersion,
        array $quantities,
    ): array {
        $baseline = [];
        foreach ($planVersion->limits() as $key => $value) {
            if (is_int($value)) {
                $baseline[$key] = $value;
            }
        }
        ksort($baseline);

        if (
            ($quantities !== [] && array_is_list($quantities))
            || count($quantities) !== count($baseline)
        ) {
            throw new DomainException(
                'Cantidades de suscripción incompatibles con la PlanVersion.',
            );
        }

        $normalized = [];
        foreach ($quantities as $key => $value) {
            if (
                !is_string($key)
                || !array_key_exists($key, $baseline)
                || !is_int($value)
                || $value < $baseline[$key]
            ) {
                throw new DomainException(
                    'Cantidad comercial configurada inválida.',
                );
            }
            $normalized[$key] = $value;
        }
        ksort($normalized);

        if (array_keys($normalized) !== array_keys($baseline)) {
            throw new DomainException(
                'Cantidades de suscripción incompletas.',
            );
        }

        return $normalized;
    }

    /**
     * @param array<array-key,mixed> $addOns
     * @return list<AddOn>
     */
    private static function normalizeAddOns(
        PlanVersion $planVersion,
        array $addOns,
    ): array {
        if (!array_is_list($addOns) || count($addOns) > 50) {
            throw new DomainException('Selección de add-ons inválida.');
        }

        $allowed = [];
        foreach ($planVersion->addOns() as $candidate) {
            $allowed[$candidate->id()] = $candidate;
        }

        $normalized = [];
        foreach ($addOns as $addOn) {
            if (
                !$addOn instanceof AddOn
                || !$addOn->isActive()
                || !isset($allowed[$addOn->id()])
            ) {
                throw new DomainException(
                    'Add-on incompatible o inactivo para la PlanVersion.',
                );
            }
            if (isset($normalized[$addOn->id()])) {
                throw new DomainException('Add-on duplicado.');
            }
            $normalized[$addOn->id()] = $addOn;
        }

        uasort(
            $normalized,
            static fn (AddOn $left, AddOn $right): int =>
                $left->key() <=> $right->key(),
        );

        return array_values($normalized);
    }
}
