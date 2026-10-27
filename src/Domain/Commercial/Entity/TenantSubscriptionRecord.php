<?php

declare(strict_types=1);

namespace App\Domain\Commercial\Entity;

use App\Domain\Commercial\UsageRecord;
use DateTimeImmutable;
use DateTimeZone;
use DomainException;

/**
 * Offline-only, immutable link between the EXISTING commercial subscription
 * and the EXISTING UsageRecord. Never stores a second price or lifecycle state.
 * The repository checks the canonical subscription's tenant before insertion.
 */
final readonly class TenantSubscriptionRecord
{
    public function __construct(
        private string $tenantId,
        private string $subscriptionId,
        private UsageRecord $usage,
        private string $eventKey,
        private string $actorId,
        private DateTimeImmutable $recordedAt,
    ) {
        self::assertFixtureScope($tenantId, $actorId);
        if ($this->usage->tenantId() !== $tenantId) {
            throw new DomainException('Usage pertenece a otra empresa.');
        }
        if (preg_match('/^[0-9A-HJKMNP-TV-Z]{26}$/D', $subscriptionId) !== 1) {
            throw new DomainException('Identificador de suscripción no canónico.');
        }
        if (preg_match('/^fixture-[a-z0-9-]{1,72}$/D', $eventKey) !== 1) {
            throw new DomainException('Evento sintético inválido.');
        }
        if ($recordedAt < $usage->observedAt()) {
            throw new DomainException('Registro anterior a la observación.');
        }
    }

    public static function assertFixtureScope(string $tenantId, string $actorId): void
    {
        if (
            preg_match('/^fixture-[a-z0-9-]{1,72}$/D', $tenantId) !== 1
            || preg_match('/^fixture-[a-z0-9-]{1,72}$/D', $actorId) !== 1
        ) {
            throw new DomainException('Solo identidades sintéticas sin datos reales.');
        }
    }

    public function tenantId(): string { return $this->tenantId; }
    public function subscriptionId(): string { return $this->subscriptionId; }
    public function eventKey(): string { return $this->eventKey; }
    public function actorId(): string { return $this->actorId; }

    /**
     * @return array{tenant_id:string,subscription_id:string,event_key:string,metric:string,
     *     quantity:int,window_start:string,window_end:string,observed_at:string,
     *     actor_id:string,recorded_at:string,fingerprint:string}
     */
    public function snapshot(): array
    {
        $record = $this->usage->snapshot();
        $values = [
            'tenant_id' => $this->tenantId,
            'subscription_id' => $this->subscriptionId,
            'event_key' => $this->eventKey,
            'metric' => $record['metric'],
            'quantity' => $record['quantity'],
            'window_start' => self::utc($this->usage->windowStart()),
            'window_end' => self::utc($this->usage->windowEnd()),
            'observed_at' => self::utc($this->usage->observedAt()),
            'actor_id' => $this->actorId,
            'recorded_at' => self::utc($this->recordedAt),
        ];

        return [
            ...$values,
            'fingerprint' => hash('sha256', json_encode($values, JSON_THROW_ON_ERROR)),
        ];
    }

    private static function utc(DateTimeImmutable $time): string
    {
        return $time->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d\TH:i:s.u\Z');
    }
}
