<?php

declare(strict_types=1);

namespace App\Tests\Domain\Attendance;

use App\Domain\Attendance\AttendanceEvent;
use App\Domain\Attendance\AttendanceMark;
use App\Domain\HumanResources\Employee;
use App\Domain\Organization\Entity\Tenant;
use DateTimeImmutable;
use PHPUnit\Framework\TestCase;

final class AttendanceEventTest extends TestCase
{
    public function testExposesPseudonymousIdentityAndNormalizedUtcTimestamp(): void
    {
        $tenant = new Tenant('Tenant A', 'tenant-a');
        $employee = new Employee($tenant, 'EMP001');
        $mark = new AttendanceMark(
            $employee,
            'clock-event-001',
            new DateTimeImmutable('2024-04-23T06:15:37-05:00'),
            AttendanceMark::CHECK_IN,
        );

        $event = new AttendanceEvent($tenant, $mark, 'vendor-a');

        self::assertMatchesRegularExpression('/^[0-9A-HJKMNP-TV-Z]{26}$/D', $event->id());
        self::assertSame($tenant->id(), $event->tenantId());
        self::assertSame($employee->id(), $event->employeeId());
        self::assertSame('vendor-a', $event->sourceKey());
        self::assertSame('clock-event-001', $event->externalEventId());
        self::assertSame('2024-04-23T11:15:37Z', $event->occurredAt());
        self::assertSame(AttendanceMark::CHECK_IN, $event->type());
        self::assertSame('2024-04-23T06:15:37-05:00', $mark->occurredAt->format('Y-m-d\\TH:i:sP'), 'The input mark must not be mutated.');
    }

    public function testSafeProjectionContainsOnlyExplicitFieldsAndMatchesAccessors(): void
    {
        $tenant = new Tenant('Tenant with private name', 'private-tenant');
        $employee = new Employee($tenant, 'EMP-SECRET');
        $mark = new AttendanceMark(
            $employee,
            'source-event',
            new DateTimeImmutable('2024-01-01T00:00:00+02:00'),
            AttendanceMark::CHECK_OUT,
        );
        $event = new AttendanceEvent($tenant, $mark, 'provider-key');

        self::assertSame([
            'id' => $event->id(),
            'tenant_id' => $tenant->id(),
            'employee_id' => $employee->id(),
            'source_key' => 'provider-key',
            'external_event_id' => 'source-event',
            'occurred_at' => '2023-12-31T22:00:00Z',
            'type' => AttendanceMark::CHECK_OUT,
        ], $event->toSafeArray());
        self::assertNotContains('EMP-SECRET', array_values($event->toSafeArray()));
        self::assertNotContains('Tenant with private name', array_values($event->toSafeArray()));
    }

    public function testSamePayloadIgnoresGeneratedIdAndTimezoneRepresentation(): void
    {
        $tenant = new Tenant('Tenant A', 'tenant-a');
        $employee = new Employee($tenant, 'EMP001');
        $first = new AttendanceEvent($tenant, new AttendanceMark(
            $employee, 'remote-1', new DateTimeImmutable('2024-02-05T10:30:00-05:00'), AttendanceMark::CHECK_IN,
        ), 'clock');
        $second = new AttendanceEvent($tenant, new AttendanceMark(
            $employee, 'remote-1', new DateTimeImmutable('2024-02-05T15:30:00Z'), AttendanceMark::CHECK_IN,
        ), 'clock');

        self::assertNotSame($first->id(), $second->id());
        self::assertTrue($first->hasSamePayload($second));
        self::assertTrue($second->hasSamePayload($first));
        self::assertSame($first->occurredAt(), $second->occurredAt());
    }

    public function testEmployeesWithSameCodeRemainDistinctIdentities(): void
    {
        $tenant = new Tenant('Tenant A', 'tenant-a');
        $firstEmployee = new Employee($tenant, 'EMP001');
        $secondEmployee = new Employee($tenant, 'EMP001');
        $at = new DateTimeImmutable('2024-02-05T15:30:00Z');

        $first = new AttendanceEvent($tenant, new AttendanceMark(
            $firstEmployee, 'remote-1', $at, AttendanceMark::CHECK_IN,
        ), 'clock');
        $second = new AttendanceEvent($tenant, new AttendanceMark(
            $secondEmployee, 'remote-1', $at, AttendanceMark::CHECK_IN,
        ), 'clock');

        self::assertSame($firstEmployee->code(), $secondEmployee->code());
        self::assertNotSame($firstEmployee->id(), $secondEmployee->id());
        self::assertFalse($first->hasSamePayload($second));
    }

    /**
     * @return iterable<string, array{string, string, string, string, string, string}>
     */
    public static function distinctPayloadFields(): iterable
    {
        yield 'tenant' => ['different', 'same', 'clock', 'remote-1', '2024-02-05T15:30:00Z', AttendanceMark::CHECK_IN];
        yield 'employee' => ['same', 'different', 'clock', 'remote-1', '2024-02-05T15:30:00Z', AttendanceMark::CHECK_IN];
        yield 'source' => ['same', 'same', 'another-clock', 'remote-1', '2024-02-05T15:30:00Z', AttendanceMark::CHECK_IN];
        yield 'external ID' => ['same', 'same', 'clock', 'remote-2', '2024-02-05T15:30:00Z', AttendanceMark::CHECK_IN];
        yield 'timestamp' => ['same', 'same', 'clock', 'remote-1', '2024-02-05T15:30:01Z', AttendanceMark::CHECK_IN];
        yield 'type' => ['same', 'same', 'clock', 'remote-1', '2024-02-05T15:30:00Z', AttendanceMark::CHECK_OUT];
    }

    /** @dataProvider distinctPayloadFields */
    public function testChangingAnyPayloadFieldBreaksEquality(
        string $tenantChoice,
        string $employeeChoice,
        string $source,
        string $externalId,
        string $timestamp,
        string $type,
    ): void {
        $tenant = new Tenant('Tenant A', 'tenant-a');
        $employee = new Employee($tenant, 'EMP001');
        $baseline = new AttendanceEvent($tenant, new AttendanceMark(
            $employee, 'remote-1', new DateTimeImmutable('2024-02-05T15:30:00Z'), AttendanceMark::CHECK_IN,
        ), 'clock');

        $otherTenant = $tenantChoice === 'different' ? new Tenant('Tenant B', 'tenant-b') : $tenant;
        $otherEmployee = $employeeChoice === 'different'
            ? new Employee($otherTenant, 'EMP002')
            : $employee;
        $candidate = new AttendanceEvent($otherTenant, new AttendanceMark(
            $otherEmployee, $externalId, new DateTimeImmutable($timestamp), $type,
        ), $source);

        self::assertFalse($baseline->hasSamePayload($candidate));
        self::assertFalse($candidate->hasSamePayload($baseline));
    }
}
