<?php

declare(strict_types=1);

namespace App\Domain\Commercial\Entity;

use App\Domain\Commercial\SubscriptionLifecycle;
use App\Domain\Commercial\SubscriptionState;
use App\Shared\Id\UlidFactory;
use DateTimeImmutable;
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
        columnDefinition: "DATETIME(6) NOT NULL COMMENT '(DC2Type:datetime_immutable)'",
    )]
    private DateTimeImmutable $lastChangedAt;

    #[ORM\Column(
        name: 'created_at',
        type: 'datetime_immutable',
        columnDefinition: "DATETIME(6) NOT NULL COMMENT '(DC2Type:datetime_immutable)'",
    )]
    private DateTimeImmutable $createdAt;

    #[ORM\Column(
        name: 'updated_at',
        type: 'datetime_immutable',
        columnDefinition: "DATETIME(6) NOT NULL COMMENT '(DC2Type:datetime_immutable)'",
    )]
    private DateTimeImmutable $updatedAt;

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
        $subscription = new self();
        $subscription->id = UlidFactory::new();
        $subscription->tenantId = $lifecycle->tenantId();
        $subscription->planVersion = $lifecycle->planVersion();
        $subscription->state = $lifecycle->state()->value;
        $subscription->history = $lifecycle->history();
        $subscription->lastChangedAt = $lifecycle->lastChangedAt();
        $subscription->createdAt = $recordedAt;
        $subscription->updatedAt = $recordedAt;

        return $subscription;
    }

    public function id(): string { return $this->id; }
    public function tenantId(): string { return $this->tenantId; }
    public function planVersion(): PlanVersion { return $this->planVersion; }
    public function state(): SubscriptionState { return SubscriptionState::from($this->state); }
    /** @return list<array{state:string,at:string}> */
    public function history(): array { return $this->history; }
    public function lastChangedAt(): DateTimeImmutable { return $this->lastChangedAt; }
    public function createdAt(): DateTimeImmutable { return $this->createdAt; }
    public function updatedAt(): DateTimeImmutable { return $this->updatedAt; }
    public function lockVersion(): int { return $this->lockVersion; }

    public function toLifecycle(): SubscriptionLifecycle
    {
        return SubscriptionLifecycle::restore(
            $this->tenantId,
            $this->planVersion,
            $this->state(),
            $this->lastChangedAt,
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
            $updatedAt < $this->updatedAt
            || $lifecycle->lastChangedAt() < $this->lastChangedAt
            || count($lifecycle->history()) < count($this->history)
            || array_slice($lifecycle->history(), 0, count($this->history)) !== $this->history
        ) {
            throw new DomainException('Sincronización regresiva de suscripción.');
        }

        $this->state = $lifecycle->state()->value;
        $this->history = $lifecycle->history();
        $this->lastChangedAt = $lifecycle->lastChangedAt();
        $this->updatedAt = $updatedAt;
    }
}
