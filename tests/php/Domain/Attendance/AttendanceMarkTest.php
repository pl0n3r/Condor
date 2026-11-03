<?php

declare(strict_types=1);

namespace App\Tests\Domain\Attendance;

use App\Domain\Attendance\AttendanceMark;
use App\Domain\HumanResources\Employee;
use App\Domain\Organization\Entity\Tenant;
use DateTimeImmutable;
use DomainException;
use PHPUnit\Framework\TestCase;

final class AttendanceMarkTest extends TestCase
{
    private Employee $employee;
    private DateTimeImmutable $occurredAt;

    protected function setUp(): void
    {
        $this->employee = new Employee(new Tenant('Test Tenant', 'test-tenant'), 'EMP001');
        $this->occurredAt = new DateTimeImmutable('2023-01-01 10:00:00');
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function validTypes(): iterable
    {
        yield 'check in' => [AttendanceMark::CHECK_IN];
        yield 'check out' => [AttendanceMark::CHECK_OUT];
    }

    /**
     * @dataProvider validTypes
     */
    public function testConstructKeepsEveryValueItWasGiven(string $type): void
    {
        $mark = new AttendanceMark($this->employee, 'event-123', $this->occurredAt, $type);

        self::assertSame($this->employee, $mark->employee);
        self::assertSame('event-123', $mark->externalEventId);
        self::assertSame($this->occurredAt, $mark->occurredAt);
        self::assertSame($type, $mark->type);
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function validExternalEventIds(): iterable
    {
        yield 'single character' => ['a'];
        yield 'digit first' => ['9'];
        yield 'with dash' => ['event-123'];
        yield 'with underscore' => ['event_456'];
        yield 'with dot' => ['event.789'];
        yield 'with colon' => ['event:012'];
        yield 'mixed separators' => ['Event-123_456.789:012'];
        yield 'maximum length of 96' => [str_repeat('a', 96)];
    }

    /**
     * @dataProvider validExternalEventIds
     */
    public function testAcceptsWellFormedExternalEventIds(string $externalEventId): void
    {
        $mark = new AttendanceMark($this->employee, $externalEventId, $this->occurredAt, AttendanceMark::CHECK_IN);

        self::assertSame($externalEventId, $mark->externalEventId);
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function invalidExternalEventIds(): iterable
    {
        yield 'empty' => [''];
        yield 'starts with dash' => ['-event'];
        yield 'starts with dot' => ['.event'];
        yield 'starts with underscore' => ['_event'];
        yield 'invalid character' => ['invalid@event'];
        yield 'contains space' => ['invalid event'];
        yield 'trailing newline' => ["event\n"];
        yield 'longer than 96' => [str_repeat('a', 97)];
    }

    /**
     * @dataProvider invalidExternalEventIds
     */
    public function testRejectsMalformedExternalEventIds(string $externalEventId): void
    {
        $this->expectException(DomainException::class);
        $this->expectExceptionMessage('Identificador externo inválido.');

        new AttendanceMark($this->employee, $externalEventId, $this->occurredAt, AttendanceMark::CHECK_IN);
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function invalidTypes(): iterable
    {
        yield 'unknown' => ['invalid_type'];
        yield 'empty' => [''];
        yield 'dash instead of underscore' => ['check-in'];
        yield 'different case' => ['CHECK_IN'];
        yield 'surrounding space' => [' check_in'];
    }

    /**
     * @dataProvider invalidTypes
     */
    public function testRejectsUnknownTypes(string $type): void
    {
        $this->expectException(DomainException::class);
        $this->expectExceptionMessage('Tipo de marcación inválido.');

        new AttendanceMark($this->employee, 'event-123', $this->occurredAt, $type);
    }

    public function testValidatesTheExternalEventIdBeforeTheType(): void
    {
        $this->expectException(DomainException::class);
        $this->expectExceptionMessage('Identificador externo inválido.');

        new AttendanceMark($this->employee, '', $this->occurredAt, 'invalid_type');
    }
}
