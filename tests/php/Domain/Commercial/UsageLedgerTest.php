<?php

declare(strict_types=1);

namespace App\Tests\Domain\Commercial;

use App\Domain\Commercial\UsageLedger;
use App\Domain\Commercial\UsageMetric;
use App\Domain\Commercial\UsageRecord;
use DateTimeImmutable;
use DomainException;
use PHPUnit\Framework\TestCase;

final class UsageLedgerTest extends TestCase
{
    public function testTenantIsolation(): void
    {
        [$start, $end] = $this->window();
        $ledger = new UsageLedger('tenant-a');

        $this->assertRejected(
            static fn () => $ledger->add(
                new UsageRecord(
                    'tenant-b',
                    UsageMetric::Orders,
                    1,
                    $start,
                    $end,
                    new DateTimeImmutable('2026-10-02T00:00:00Z'),
                ),
            ),
        );

        self::assertSame([], $ledger->snapshot());
    }

    public function testMetricAggregationSemantics(): void
    {
        [$start, $end] = $this->window();
        $ledger = new UsageLedger('tenant-a');

        foreach ([3, 7, 4] as $offset => $quantity) {
            $observedAt = $start->modify(sprintf('+%d days', $offset + 1));
            $ledger->add(new UsageRecord('tenant-a', UsageMetric::Users, $quantity, $start, $end, $observedAt));
            $ledger->add(new UsageRecord('tenant-a', UsageMetric::ApiCalls, $quantity, $start, $end, $observedAt));
        }

        self::assertSame(7, $ledger->quantity(UsageMetric::Users, $start, $end));
        self::assertSame(14, $ledger->quantity('api_calls', $start, $end));
    }

    public function testDeterministicSnapshotAndFailClosedQueries(): void
    {
        [$start, $end] = $this->window();
        $records = [
            new UsageRecord('tenant-a', UsageMetric::Users, 3, $start, $end, $start->modify('+1 day')),
            new UsageRecord('tenant-a', UsageMetric::Users, 7, $start, $end, $start->modify('+2 days')),
            new UsageRecord('tenant-a', UsageMetric::Users, 4, $start, $end, $start->modify('+3 days')),
            new UsageRecord('tenant-a', UsageMetric::ApiCalls, 3, $start, $end, $start->modify('+1 day')),
            new UsageRecord('tenant-a', UsageMetric::ApiCalls, 7, $start, $end, $start->modify('+2 days')),
            new UsageRecord('tenant-a', UsageMetric::ApiCalls, 4, $start, $end, $start->modify('+3 days')),
        ];

        $left = new UsageLedger('tenant-a');
        foreach ($records as $record) {
            $left->add($record);
        }

        $right = new UsageLedger('tenant-a');
        foreach (array_reverse($records) as $record) {
            $right->add($record);
        }

        self::assertSame($left->snapshot(), $right->snapshot());
        self::assertNull($left->quantity(UsageMetric::Orders, $start, $end));

        $left->add(new UsageRecord(
            'tenant-a',
            UsageMetric::Locations,
            0,
            $start,
            $end,
            $start->modify('+4 days'),
        ));
        self::assertSame(0, $left->quantity(UsageMetric::Locations, $start, $end));

        $this->assertRejected(static fn () => $left->quantity('stores', $start, $end));
        $this->assertRejected(static fn () => $left->quantity(UsageMetric::Users, $end, $start));

        $overflow = new UsageLedger('tenant-a');
        $overflow->add(new UsageRecord(
            'tenant-a',
            UsageMetric::Orders,
            PHP_INT_MAX,
            $start,
            $end,
            $start->modify('+1 day'),
        ));

        $this->assertRejected(
            static fn () => $overflow->add(new UsageRecord(
                'tenant-a',
                UsageMetric::Orders,
                1,
                $start,
                $end,
                $start->modify('+2 days'),
            )),
        );
        self::assertSame(PHP_INT_MAX, $overflow->quantity(UsageMetric::Orders, $start, $end));
    }

    public function testUsageStaysSeparatedAndPayloadFree(): void
    {
        $paths = [
            __DIR__.'/../../../../src/Domain/Commercial/UsageMetric.php',
            __DIR__.'/../../../../src/Domain/Commercial/UsageRecord.php',
            __DIR__.'/../../../../src/Domain/Commercial/UsageLedger.php',
        ];

        foreach ($paths as $path) {
            $source = file_get_contents($path);
            if ($source === false) {
                self::fail('No fue posible leer el contrato de Usage.');
            }

            foreach ([
                'PermissionCatalog',
                'EntitlementResolver',
                'metadata',
                'payload',
                'RBAC',
                'Entity\\Plan',
            ] as $forbidden) {
                self::assertStringNotContainsString($forbidden, $source);
            }
        }
    }

    /** @return array{DateTimeImmutable,DateTimeImmutable} */
    private function window(): array
    {
        return [
            new DateTimeImmutable('2026-10-01T00:00:00Z'),
            new DateTimeImmutable('2026-11-01T00:00:00Z'),
        ];
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
