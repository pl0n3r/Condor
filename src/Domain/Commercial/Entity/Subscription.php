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

    /** @var list<array{state:string,at:string}> */
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
        return $this->history;
    }

    public function lastChangedAt(): DateTimeImmutable
    {
        if ($this->lastChangedAtExact !== null) {
            return self::parseExact($this->lastChangedAtExact);
        }

        $key = array_key_last($this->history);
        if ($key === null || !isset($this->history[$key]['at'])) {
            throw new DomainException('Historial persistido sin timestamp final válido.');
        }

        return self::parseHistoryTime($this->history[$key]['at']);
    }

    public function createdAt(): DateTimeImmutable
    {
        return $this->createdAtExact === null
            ? $this->createdAt
            : self::parseExact($this->createdAtExact);
    }

    public function updatedAt(): DateTimeImmutable
    {
        return $this->updatedAtExact === null
            ? $this->updatedAt
            : self::parseExact($this->updatedAtExact);
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

        if (
            $updatedAt < $lifecycle->lastChangedAt()
            || $updatedAt < $this->updatedAt()
            || $lifecycle->lastChangedAt() < $this->lastChangedAt()
            || count($lifecycle->history()) < count($this->history)
            || array_slice($lifecycle->history(), 0, count($this->history)) !== $this->history
        ) {
            throw new DomainException('Sincronización regresiva de suscripción.');
        }

        $this->state = $lifecycle->state()->value;
        $this->history = $lifecycle->history();
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

    private static function parseHistoryTime(string $value): DateTimeImmutable
    {
        if (
            preg_match(
                '/^(\\d{4}-\\d{2}-\\d{2}T\\d{2}:\\d{2}:\\d{2})(?:\\.(\\d{1,6}))?(Z|[+-]\\d{2}:\\d{2})$/D',
                trim($value),
                $matches,
            ) !== 1
        ) {
            throw new DomainException('Historial persistido sin timestamp final válido.');
        }

        $fraction = str_pad($matches[2] ?? '', 6, '0');
        $offset = $matches[3] === 'Z' ? '+00:00' : $matches[3];
        $normalized = $matches[1].'.'.$fraction.$offset;
        $parsed = DateTimeImmutable::createFromFormat('!Y-m-d\\TH:i:s.uP', $normalized);
        $errors = DateTimeImmutable::getLastErrors();

        if (
            $parsed === false
            || ($errors !== false && ($errors['warning_count'] > 0 || $errors['error_count'] > 0))
            || $parsed->format('Y-m-d\\TH:i:s.uP') !== $normalized
        ) {
            throw new DomainException('Historial persistido sin timestamp final válido.');
        }

        return $parsed;
    }

    private static function parseExact(string $value): DateTimeImmutable
    {
        return self::parseHistoryTime($value);
    }
}
