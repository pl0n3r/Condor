<?php

declare(strict_types=1);

namespace App\Domain\Commercial\Entity;

use App\Domain\Commercial\SubscriptionLifecycle;
use App\Domain\Commercial\SubscriptionState;
use App\Shared\Id\UlidFactory;
use DateTimeImmutable;
use DateTimeZone;
use Doctrine\ORM\Mapping as ORM;
use DomainException;

#[ORM\Entity]
#[ORM\Table(name: 'condor_commercial_subscription')]
#[ORM\UniqueConstraint(name: 'uniq_commercial_subscription_tenant', columns: ['tenant_id'])]
#[ORM\Index(name: 'idx_commercial_subscription_state', columns: ['state'])]
final class Subscription
{
    #[ORM\Id]
    #[ORM\Column(type: 'string', length: 26)]
    private string $id;

    #[ORM\Column(name: 'tenant_id', type: 'string', length: 120)]
    private string $tenantId;

    #[ORM\ManyToOne(targetEntity: PlanVersion::class)]
    #[ORM\JoinColumn(
        name: 'plan_version_id',
        referencedColumnName: 'id',
        nullable: false,
        onDelete: 'RESTRICT',
    )]
    private PlanVersion $planVersion;

    #[ORM\Column(type: 'string', length: 32)]
    private string $state;

    /** @var array<array-key,mixed> */
    #[ORM\Column(type: 'json')]
    private array $history;

    #[ORM\Column(
        name: 'last_changed_at',
        type: 'datetime_immutable',
        columnDefinition: 'DATETIME(6) NOT NULL',
    )]
    private DateTimeImmutable $lastChangedAt;

    #[ORM\Column(
        name: 'created_at',
        type: 'datetime_immutable',
        columnDefinition: 'DATETIME(6) NOT NULL',
    )]
    private DateTimeImmutable $createdAt;

    #[ORM\Column(
        name: 'updated_at',
        type: 'datetime_immutable',
        columnDefinition: 'DATETIME(6) NOT NULL',
    )]
    private DateTimeImmutable $updatedAt;

    #[ORM\Column(name: 'last_changed_at_exact', type: 'string', length: 32, nullable: true)]
    private ?string $lastChangedAtExact = null;

    #[ORM\Column(name: 'created_at_exact', type: 'string', length: 32, nullable: true)]
    private ?string $createdAtExact = null;

    #[ORM\Column(name: 'updated_at_exact', type: 'string', length: 32, nullable: true)]
    private ?string $updatedAtExact = null;

    #[ORM\Version]
    #[ORM\Column(name: 'lock_version', type: 'integer', options: ['unsigned' => true, 'default' => 1])]
    private int $lockVersion = 1;

    private function __construct()
    {
    }

    public static function fromLifecycle(
        SubscriptionLifecycle $lifecycle,
        DateTimeImmutable $recordedAt,
    ): self {
        if ($recordedAt < $lifecycle->lastChangedAt()) {
            throw new DomainException('Timestamp técnico anterior al cambio de suscripción.');
        }

        $subscription = new self();
        $subscription->id = UlidFactory::new();
        $subscription->tenantId = $lifecycle->tenantId();
        $subscription->planVersion = $lifecycle->planVersion();
        $subscription->state = $lifecycle->state()->value;
        $subscription->history = $lifecycle->history();
        $subscription->lastChangedAt = $lifecycle->lastChangedAt();
        $subscription->createdAt = $recordedAt;
        $subscription->updatedAt = $recordedAt;
        $subscription->lastChangedAtExact = self::formatExact($lifecycle->lastChangedAt());
        $subscription->createdAtExact = self::formatExact($recordedAt);
        $subscription->updatedAtExact = self::formatExact($recordedAt);

        return $subscription;
    }

    public function id(): string
    {
        return $this->id;
    }

    public function tenantId(): string
    {
        return $this->tenantId;
    }

    public function planVersion(): PlanVersion
    {
        return $this->planVersion;
    }

    public function state(): SubscriptionState
    {
        return SubscriptionState::from($this->state);
    }

    /** @return list<array{state:string,at:string}> */
    public function history(): array
    {
        return $this->toLifecycle()->history();
    }

    public function lastChangedAt(): DateTimeImmutable
    {
        if ($this->lastChangedAtExact !== null) {
            return SubscriptionLifecycle::parseHistoricalTime($this->lastChangedAtExact);
        }

        $key = array_key_last($this->history);
        $last = $key === null ? null : $this->history[$key];
        if (!is_array($last)) {
            throw new DomainException('Historial persistido sin timestamp final válido.');
        }

        $keys = array_keys($last);
        sort($keys);
        $state = $last['state'] ?? null;
        if (
            $keys !== ['at', 'state']
            || !is_string($state)
            || SubscriptionState::tryFrom($state) === null
        ) {
            throw new DomainException('Historial persistido sin timestamp final válido.');
        }

        return SubscriptionLifecycle::parseHistoricalTime($last['at'] ?? null);
    }

    public function createdAt(): DateTimeImmutable
    {
        return $this->createdAtExact === null
            ? $this->createdAt
            : SubscriptionLifecycle::parseHistoricalTime($this->createdAtExact);
    }

    public function updatedAt(): DateTimeImmutable
    {
        return $this->updatedAtExact === null
            ? $this->updatedAt
            : SubscriptionLifecycle::parseHistoricalTime($this->updatedAtExact);
    }

    public function lockVersion(): int
    {
        return $this->lockVersion;
    }

    public function toLifecycle(): SubscriptionLifecycle
    {
        return SubscriptionLifecycle::restore(
            $this->tenantId,
            $this->planVersion,
            $this->state(),
            $this->lastChangedAt(),
            $this->history,
        );
    }

    public function syncFromLifecycle(
        SubscriptionLifecycle $lifecycle,
        DateTimeImmutable $updatedAt,
    ): void {
        if (
            $lifecycle->tenantId() !== $this->tenantId
            || $lifecycle->planVersion()->id() !== $this->planVersion->id()
        ) {
            throw new DomainException('Identidad comercial de suscripción incompatible.');
        }

        $currentHistory = $this->history();
        $nextHistory = $lifecycle->history();
        if (
            $updatedAt < $lifecycle->lastChangedAt()
            || $updatedAt < $this->updatedAt()
            || $lifecycle->lastChangedAt() < $this->lastChangedAt()
            || count($nextHistory) < count($currentHistory)
            || array_slice($nextHistory, 0, count($currentHistory)) !== $currentHistory
        ) {
            throw new DomainException('Sincronización regresiva de suscripción.');
        }

        $this->state = $lifecycle->state()->value;
        $this->history = $nextHistory;
        $this->lastChangedAt = $lifecycle->lastChangedAt();
        $this->updatedAt = $updatedAt;
        $this->lastChangedAtExact = self::formatExact($lifecycle->lastChangedAt());
        $this->updatedAtExact = self::formatExact($updatedAt);
    }

    private static function formatExact(DateTimeImmutable $value): string
    {
        return $value
            ->setTimezone(new DateTimeZone('UTC'))
            ->format('Y-m-d\\TH:i:s.u\\Z');
    }


}
