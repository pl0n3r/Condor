<?php

declare(strict_types=1);

namespace App\Domain\Commercial;

use App\Domain\Commercial\Entity\PlanVersion;
use DateTimeImmutable;
use DomainException;

final class SubscriptionLifecycle
{
    /** @var array<string,list<SubscriptionState>> */
    private const TRANSITIONS = [
        'trialing' => [SubscriptionState::Active, SubscriptionState::Cancelled],
        'active' => [SubscriptionState::PastDue, SubscriptionState::Cancelled],
        'past_due' => [
            SubscriptionState::Active,
            SubscriptionState::GracePeriod,
            SubscriptionState::Cancelled,
        ],
        'grace_period' => [
            SubscriptionState::Active,
            SubscriptionState::Suspended,
            SubscriptionState::Cancelled,
        ],
        'suspended' => [SubscriptionState::Active, SubscriptionState::Cancelled],
        'cancelled' => [],
    ];

    /** @var list<array{state:string,at:string}> */
    private array $history;

    private DateTimeImmutable $lastChangedAt;

    public function __construct(
        private readonly string $tenantId,
        private readonly PlanVersion $planVersion,
        private SubscriptionState $state,
        DateTimeImmutable $at,
    ) {
        if (trim($tenantId) === '') {
            throw new DomainException('Tenant de suscripción inválido.');
        }

        $this->lastChangedAt = $at;
        $this->history = [[
            'state' => $state->value,
            'at' => $at->format(DATE_ATOM),
        ]];
    }

    public function tenantId(): string { return $this->tenantId; }
    public function planVersion(): PlanVersion { return $this->planVersion; }
    public function state(): SubscriptionState { return $this->state; }
    public function lastChangedAt(): DateTimeImmutable { return $this->lastChangedAt; }

    /** @return list<array{state:string,at:string}> */
    public function history(): array
    {
        return $this->history;
    }

    public function transitionTo(SubscriptionState $target, DateTimeImmutable $at): void
    {
        if (
            !in_array($target, self::TRANSITIONS[$this->state->value], true)
            || $at <= $this->lastChangedAt
        ) {
            throw new DomainException('Transición de suscripción inválida.');
        }

        $this->state = $target;
        $this->lastChangedAt = $at;
        $this->history[] = [
            'state' => $target->value,
            'at' => $at->format(DATE_ATOM),
        ];
    }
}
