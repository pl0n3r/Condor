<?php

declare(strict_types=1);

namespace App\Domain\Commercial;

use App\Domain\Commercial\Entity\PlanVersion;
use DateTimeImmutable;
use DomainException;
use Throwable;

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
            'at' => self::formatTime($at),
        ]];
    }

    /**
     * @param list<mixed> $history
     */
    public static function restore(
        string $tenantId,
        PlanVersion $planVersion,
        SubscriptionState $state,
        DateTimeImmutable $lastChangedAt,
        array $history,
    ): self {
        if ($history === []) {
            throw new DomainException('Historial de suscripción vacío.');
        }

        $restored = null;
        foreach ($history as $entry) {
            if (
                !is_array($entry)
                || !isset($entry['state'], $entry['at'])
                || !is_string($entry['state'])
                || !is_string($entry['at'])
            ) {
                throw new DomainException('Historial de suscripción inválido.');
            }

            $entryState = SubscriptionState::tryFrom($entry['state']);
            if ($entryState === null) {
                throw new DomainException('Estado histórico de suscripción inválido.');
            }

            $entryAt = self::parseTime($entry['at']);
            if ($restored === null) {
                $restored = new self($tenantId, $planVersion, $entryState, $entryAt);
                continue;
            }

            $restored->transitionTo($entryState, $entryAt);
        }

        if (
            $restored->state() !== $state
            || self::instant($restored->lastChangedAt()) !== self::instant($lastChangedAt)
        ) {
            throw new DomainException('Estado persistido incoherente con su historial.');
        }

        return $restored;
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
            'at' => self::formatTime($at),
        ];
    }

    private static function parseTime(string $value): DateTimeImmutable
    {
        $value = trim($value);
        if (
            preg_match(
                '/^\\d{4}-\\d{2}-\\d{2}T\\d{2}:\\d{2}:\\d{2}(?:\\.\\d{1,6})?(?:Z|[+-]\\d{2}:\\d{2})$/D',
                $value,
            ) !== 1
        ) {
            throw new DomainException('Timestamp histórico de suscripción inválido.');
        }

        try {
            return new DateTimeImmutable($value);
        } catch (Throwable) {
            throw new DomainException('Timestamp histórico de suscripción inválido.');
        }
    }

    private static function formatTime(DateTimeImmutable $value): string
    {
        return $value->format('Y-m-d\\TH:i:s.uP');
    }

    private static function instant(DateTimeImmutable $value): string
    {
        return $value->format('U.u');
    }
}
