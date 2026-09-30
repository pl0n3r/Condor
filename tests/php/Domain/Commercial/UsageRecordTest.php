<?php

declare(strict_types=1);

namespace App\Tests\Domain\Commercial;

use App\Domain\Commercial\UsageMetric;
use App\Domain\Commercial\UsageRecord;
use DateTimeImmutable;
use DomainException;
use PHPUnit\Framework\TestCase;

final class UsageRecordTest extends TestCase
{
    public function testRecordInvariants(): void
    {
        $start = new DateTimeImmutable('2026-10-01T00:00:00Z');
        $end = new DateTimeImmutable('2026-11-01T00:00:00Z');
        $observedAt = new DateTimeImmutable('2026-10-15T12:00:00Z');

        $record = new UsageRecord(
            ' tenant-a ',
            UsageMetric::fromKey(' USERS '),
            7,
            $start,
            $end,
            $observedAt,
        );

        self::assertSame('tenant-a', $record->tenantId());
        self::assertSame(UsageMetric::Users, $record->metric());
        self::assertSame(7, $record->quantity());
        self::assertSame($start, $record->windowStart());
        self::assertSame($end, $record->windowEnd());
        self::assertSame($observedAt, $record->observedAt());

        foreach ([
            ['', UsageMetric::Users, 1, $start, $end, $observedAt],
            ['tenant-a', UsageMetric::Users, -1, $start, $end, $observedAt],
            ['tenant-a', UsageMetric::Users, 1, $start, $start, $observedAt],
            ['tenant-a', UsageMetric::Users, 1, $end, $start, $observedAt],
            ['tenant-a', UsageMetric::Users, 1, $start, $end, new DateTimeImmutable('2026-09-30T23:59:59Z')],
        ] as $arguments) {
            $this->assertRejected(static fn () => new UsageRecord(...$arguments));
        }

        $this->assertRejected(static fn () => UsageMetric::fromKey('stores'));
    }

    public function testMetricCatalogOwnsAggregationSemantics(): void
    {
        foreach ([
            UsageMetric::Companies,
            UsageMetric::Users,
            UsageMetric::Locations,
            UsageMetric::StorageMb,
        ] as $metric) {
            self::assertSame('max', $metric->aggregation());
        }

        foreach ([UsageMetric::Orders, UsageMetric::ApiCalls] as $metric) {
            self::assertSame('sum', $metric->aggregation());
        }
    }

    /** @param callable():mixed $operation */
    private function assertRejected(callable $operation): void
    {
        try {
            $operation();
            self::fail('La operación inválida debía fallar.');
        } catch (DomainException) {
            self::assertTrue(true);
        }
    }
}
