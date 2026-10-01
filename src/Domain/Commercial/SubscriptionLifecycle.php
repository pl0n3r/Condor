<?php

declare(strict_types=1);

namespace App\Domain\Commercial;

use App\Domain\Commercial\Entity\PlanVersion;
use DateInterval;
use DateTimeImmutable;
use DateTimeZone;
use DomainException;

final class SubscriptionLifecycle
{
    public const TRIAL_DURATION_DAYS = 14;

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
     * @param array<array-key,mixed> $history
     */
    public static function restore(
        string $tenantId,
        PlanVersion $planVersion,
        SubscriptionState $state,
        DateTimeImmutable $lastChangedAt,
        array $history,
    ): self {
        if ($history === [] || !array_is_list($history)) {
            throw new DomainException('Historial de suscripción vacío o no canónico.');
        }

        $restored = null;
        foreach ($history as $entry) {
            if (!is_array($entry)) {
                throw new DomainException('Historial de suscripción inválido.');
            }

            $keys = array_keys($entry);
            sort($keys);
            if (
                $keys !== ['at', 'state']
                || !is_string($entry['state'] ?? null)
                || !is_string($entry['at'] ?? null)
            ) {
                throw new DomainException('Historial de suscripción inválido.');
            }

            $entryState = SubscriptionState::tryFrom($entry['state']);
            if ($entryState === null) {
                throw new DomainException('Estado histórico de suscripción inválido.');
            }

            $entryAt = self::parseHistoricalTime($entry['at']);
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
        return $this->state;
    }

    public function lastChangedAt(): DateTimeImmutable
    {
        return $this->lastChangedAt;
    }

    public function trialStartedAt(): ?DateTimeImmutable
    {
        $first = $this->history[0];
        if ($first['state'] !== SubscriptionState::Trialing->value) {
            return null;
        }

        return self::parseHistoricalTime($first['at']);
    }

    public function trialEndsAt(): ?DateTimeImmutable
    {
        $startedAt = $this->trialStartedAt();
        if ($startedAt === null) {
            return null;
        }

        return $startedAt->add(
            new DateInterval('P'.self::TRIAL_DURATION_DAYS.'D'),
        );
    }

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

    public static function parseHistoricalTime(mixed $value): DateTimeImmutable
    {
        if (!is_string($value)) {
            throw new DomainException('Timestamp histórico de suscripción inválido.');
        }

        $value = trim($value);
        if (
            preg_match(
                '/^(\\d{4}-\\d{2}-\\d{2}T\\d{2}:\\d{2}:\\d{2})(?:\\.(\\d{1,6}))?(Z|[+-]\\d{2}:\\d{2})$/D',
                $value,
                $matches,
            ) !== 1
        ) {
            throw new DomainException('Timestamp histórico de suscripción inválido.');
        }

        $fraction = str_pad($matches[2], 6, '0');
        $offset = $matches[3] === 'Z' ? '+00:00' : $matches[3];
        $normalized = $matches[1].'.'.$fraction.$offset;
        $parsed = DateTimeImmutable::createFromFormat('!Y-m-d\\TH:i:s.uP', $normalized);
        $errors = DateTimeImmutable::getLastErrors();

        if (
            $parsed === false
            || ($errors !== false && ($errors['warning_count'] > 0 || $errors['error_count'] > 0))
            || $parsed->format('Y-m-d\\TH:i:s.uP') !== $normalized
        ) {
            throw new DomainException('Timestamp histórico de suscripción inválido.');
        }

        return $parsed;
    }

    private static function formatTime(DateTimeImmutable $value): string
    {
        return $value
            ->setTimezone(new DateTimeZone('UTC'))
            ->format('Y-m-d\\TH:i:s.u\\Z');
    }

    private static function instant(DateTimeImmutable $value): string
    {
        return $value->format('U.u');
    }
}
