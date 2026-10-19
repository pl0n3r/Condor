<?php

declare(strict_types=1);

namespace App\Domain\Attendance;

use App\Domain\HumanResources\Employee;
use DateTimeImmutable;
use DomainException;

/**
 * Normalized clock metadata only. No biometric template, image, fingerprint,
 * face embedding, device credential or raw vendor payload can be carried here.
 */
final readonly class AttendanceMark
{
    public const CHECK_IN = 'check_in';
    public const CHECK_OUT = 'check_out';

    public function __construct(
        public Employee $employee,
        public string $externalEventId,
        public DateTimeImmutable $occurredAt,
        public string $type,
    ) {
        if (preg_match('/^[A-Za-z0-9][A-Za-z0-9_.:-]{0,95}$/D', $externalEventId) !== 1) {
            throw new DomainException('Identificador externo inválido.');
        }
        if (!in_array($type, [self::CHECK_IN, self::CHECK_OUT], true)) {
            throw new DomainException('Tipo de marcación inválido.');
        }
    }
}
