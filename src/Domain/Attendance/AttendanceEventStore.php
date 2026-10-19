<?php

declare(strict_types=1);

namespace App\Domain\Attendance;

use DateTimeImmutable;

/**
 * Future store MUST atomically enforce uniqueness on
 * (tenant_id, source_key, external_event_id), return the existing event on
 * replay, and delete expired records before/within the same transaction.
 * An implementation cannot persist biometric data or original device frames.
 *
 * No database adapter or migration is authorized by #694.
 */
interface AttendanceEventStore
{
    public function recordOnce(AttendanceEvent $event, DateTimeImmutable $expireBefore): AttendanceEvent;
}
