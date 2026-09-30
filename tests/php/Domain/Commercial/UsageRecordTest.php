<?php

declare(strict_types=1);

namespace App\Tests\Domain\Commercial;

use App\Domain\Commercial\UsageAggregation;
use App\Domain\Commercial\UsageRecord;
use DateTimeImmutable;
use DomainException;
use PHPUnit\Framework\TestCase;

final class UsageRecordTest extends TestCase
{
    public function testUsageRecordContractIsClosedAndCanonical(): void
    {
        $at = new DateTimeImmutable('2026-10-01T10:00:00.123456Z');
        $record = new UsageRecord(
            'tenant-a',
            ' API_CALLS ',
            7,
            UsageAggregation::Counter,
            $at,
        );

        self::assertSame('tenant-a', $record->tenantId());
        self::assertSame('api_calls', $record->key());
        self::assertSame(7, $record->quantity());
        self::assertSame(UsageAggregation::Counter, $record->aggregation());
        self::assertSame($at, $record->observedAt());
        self::assertSame(
            ['tenant_id', 'key', 'quantity', 'aggregation', 'observed_at'],
            array_keys($record->snapshot()),
        );

        foreach ([
            ['', 'users', 1],
            ['tenant-a', 'x', 1],
            ['tenant-a', 'bad-key', 1],
            ['tenant-a', 'users', -1],
        ] as [$tenant, $key, $quantity]) {
            try {
                new UsageRecord(
                    $tenant,
                    $key,
                    $quantity,
                    UsageAggregation::Gauge,
                    $at,
                );
                self::fail('UsageRecord inválido debía fallar.');
            } catch (DomainException) {
                self::assertTrue(true);
            }
        }
    }
}
