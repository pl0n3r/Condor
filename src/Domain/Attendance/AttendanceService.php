<?php

declare(strict_types=1);

namespace App\Domain\Attendance;

use App\Domain\HumanResources\Employee;
use App\Domain\Organization\Entity\Tenant;
use DateTimeImmutable;
use DateTimeZone;
use DomainException;

/**
 * Offline ingress: consumes only trusted employee and minimal mark metadata.
 *
 * Privacy/retention contract: keep at most 30 days; reject older or future
 * events and unexpected fields. Real employee attendance is DISABLED until
 * legal/privacy, notices, access controls, deletion schedule and consent or
 * other lawful basis have been independently verified before go-live.
 */
final readonly class AttendanceService
{
    public const RETENTION_DAYS = 30;

    public function __construct(private AttendanceEventStore $events)
    {
    }

    /**
     * Strict DTO boundary for a future reader adapter. Unknown keys (including
     * photos, credentials and raw device payload) fail closed.
     *
     * @param array<string, mixed> $payload
     */
    public function recordPayload(
        Tenant $tenant,
        Employee $employee,
        AttendanceAdapter $adapter,
        array $payload,
        DateTimeImmutable $now,
    ): AttendanceEvent {
        $keys = array_keys($payload);
        sort($keys);
        if ($keys !== ['external_event_id', 'occurred_at', 'type']
            || !is_string($payload['external_event_id'])
            || !is_string($payload['occurred_at'])
            || !is_string($payload['type'])
            || strlen($payload['occurred_at']) !== 20) {
            throw new DomainException('Metadatos de asistencia no permitidos.');
        }
        $utc = new DateTimeZone('UTC');
        $occurredAt = DateTimeImmutable::createFromFormat(
            '!Y-m-d\TH:i:s\Z', $payload['occurred_at'], $utc,
        );
        if (!$occurredAt || $occurredAt->format('Y-m-d\TH:i:s\Z') !== $payload['occurred_at']) {
            throw new DomainException('Fecha de marcación inválida.');
        }
        return $this->record(
            $tenant,
            new AttendanceMark($employee, $payload['external_event_id'], $occurredAt, $payload['type']),
            $adapter,
            $now,
        );
    }

    public function record(
        Tenant $tenant,
        AttendanceMark $mark,
        AttendanceAdapter $adapter,
        DateTimeImmutable $now,
    ): AttendanceEvent {
        if ($mark->employee->tenant()->id() !== $tenant->id()) {
            throw new DomainException('Empleado ajeno al tenant de asistencia.');
        }
        $sourceKey = $adapter->sourceKey();
        if (preg_match('/^[a-z][a-z0-9._-]{2,47}$/D', $sourceKey) !== 1) {
            throw new DomainException('Fuente de marcación inválida.');
        }
        $now = $now->setTimezone(new DateTimeZone('UTC'));
        $expireBefore = $now->modify('-' . self::RETENTION_DAYS . ' days');
        if ($mark->occurredAt < $expireBefore || $mark->occurredAt > $now) {
            throw new DomainException('Marcación fuera de la ventana de retención.');
        }

        $candidate = new AttendanceEvent($tenant, $mark, $sourceKey);
        $stored = $this->events->recordOnce($candidate, $expireBefore);
        // The store is a future untrusted boundary: cross-tenant and conflicting
        // replays must never be accepted, even if it returns an incorrect event.
        if (!$candidate->hasSamePayload($stored)) {
            throw new DomainException('Repetición externa incompatible.');
        }
        return $stored;
    }
}
