<?php

declare(strict_types=1);

namespace App\Tests\Domain\Commercial;

use App\Domain\Commercial\Entity\Plan;
use App\Domain\Commercial\Entity\PlanVersion;
use App\Domain\Commercial\UsageAggregation;
use App\Domain\Commercial\UsageLedger;
use App\Domain\Commercial\UsageRecord;
use DateTimeImmutable;
use DomainException;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

final class UsageLedgerTest extends TestCase
{
    public function testLedgerAggregationAndTenantScopeAreFailClosed(): void
    {
        $ledger = new UsageLedger('tenant-a');
        $from = new DateTimeImmutable('2026-10-01T00:00:00Z');
        $until = new DateTimeImmutable('2026-11-01T00:00:00Z');

        $ledger->add(new UsageRecord('tenant-a', 'api_calls', 2, UsageAggregation::Counter, new DateTimeImmutable('2026-10-02T00:00:00Z')));
        $ledger->add(new UsageRecord('tenant-a', 'api_calls', 3, UsageAggregation::Counter, new DateTimeImmutable('2026-10-03T00:00:00Z')));
        $ledger->add(new UsageRecord('tenant-a', 'users', 4, UsageAggregation::Gauge, new DateTimeImmutable('2026-10-02T00:00:00Z')));
        $ledger->add(new UsageRecord('tenant-a', 'users', 6, UsageAggregation::Gauge, new DateTimeImmutable('2026-10-04T00:00:00Z')));

        self::assertSame(5, $ledger->value('api_calls', $from, $until));
        self::assertSame(6, $ledger->value('users', $from, $until));

        $this->assertRejected(
            static fn () => $ledger->add(new UsageRecord('tenant-b', 'orders', 1, UsageAggregation::Counter, new DateTimeImmutable('2026-10-05T00:00:00Z'))),
        );
        $this->assertRejected(
            static fn () => $ledger->add(new UsageRecord('tenant-a', 'api_calls', 1, UsageAggregation::Gauge, new DateTimeImmutable('2026-10-06T00:00:00Z'))),
        );
        $this->assertRejected(
            static fn () => $ledger->add(new UsageRecord('tenant-a', 'users', 8, UsageAggregation::Gauge, new DateTimeImmutable('2026-10-04T00:00:00Z'))),
        );
    }

    public function testExplicitHalfOpenWindowsExcludeMissingOrOutOfRangeData(): void
    {
        $ledger = new UsageLedger('tenant-a');
        $ledger->add(new UsageRecord('tenant-a', 'api_calls', 1, UsageAggregation::Counter, new DateTimeImmutable('2026-09-30T23:59:59Z')));
        $ledger->add(new UsageRecord('tenant-a', 'api_calls', 2, UsageAggregation::Counter, new DateTimeImmutable('2026-10-01T00:00:00Z')));
        $ledger->add(new UsageRecord('tenant-a', 'api_calls', 3, UsageAggregation::Counter, new DateTimeImmutable('2026-10-31T23:59:59Z')));
        $ledger->add(new UsageRecord('tenant-a', 'api_calls', 4, UsageAggregation::Counter, new DateTimeImmutable('2026-11-01T00:00:00Z')));

        $from = new DateTimeImmutable('2026-10-01T00:00:00Z');
        $until = new DateTimeImmutable('2026-11-01T00:00:00Z');

        self::assertSame(5, $ledger->value('api_calls', $from, $until));
        self::assertNull($ledger->value('users', $from, $until));

        $this->expectException(DomainException::class);
        $ledger->value('api_calls', $until, $from);
    }

    public function testPlanVersionLimitComparisonUsesCanonicalIntegerLimit(): void
    {
        $ledger = new UsageLedger('tenant-a');
        $from = new DateTimeImmutable('2026-10-01T00:00:00Z');
        $until = new DateTimeImmutable('2026-11-01T00:00:00Z');
        $ledger->add(new UsageRecord('tenant-a', 'api_calls', 7, UsageAggregation::Counter, new DateTimeImmutable('2026-10-10T00:00:00Z')));

        $version = new PlanVersion(
            new Plan('pro', 'Pro'),
            1,
            499900,
            4999000,
            false,
            [
                'api_calls' => 5,
                'users' => 10,
                'unlimited_storage' => null,
                'tier' => 'standard',
            ],
            new DateTimeImmutable('2026-01-01T00:00:00Z'),
        );

        self::assertSame(
            ['used' => 7, 'limit' => 5, 'remaining' => 0, 'exceeded' => true],
            $ledger->againstPlanLimit($version, 'api_calls', $from, $until),
        );

        $this->assertRejected(
            static fn () => $ledger->againstPlanLimit($version, 'unknown', $from, $until),
        );
        $this->assertRejected(
            static fn () => $ledger->againstPlanLimit($version, 'tier', $from, $until),
        );
        $this->assertRejected(
            static fn () => $ledger->againstPlanLimit($version, 'users', $from, $until),
        );
    }

    public function testPureDeterministicContractHasNoPersistenceOrFreePayload(): void
    {
        $ledger = new UsageLedger('tenant-a');
        $ledger->add(new UsageRecord('tenant-a', 'users', 8, UsageAggregation::Gauge, new DateTimeImmutable('2026-10-03T00:00:00Z')));
        $ledger->add(new UsageRecord('tenant-a', 'api_calls', 2, UsageAggregation::Counter, new DateTimeImmutable('2026-10-02T00:00:00Z')));
        $ledger->add(new UsageRecord('tenant-a', 'api_calls', 1, UsageAggregation::Counter, new DateTimeImmutable('2026-10-01T00:00:00Z')));

        self::assertSame(
            ['api_calls', 'api_calls', 'users'],
            array_column($ledger->snapshot(), 'key'),
        );
        self::assertSame([], (new ReflectionClass(UsageRecord::class))->getAttributes());
        self::assertSame([], (new ReflectionClass(UsageLedger::class))->getAttributes());

        foreach ([
            __DIR__.'/../../../../src/Domain/Commercial/UsageRecord.php',
            __DIR__.'/../../../../src/Domain/Commercial/UsageLedger.php',
        ] as $path) {
            $source = file_get_contents($path);
            self::assertIsString($source);
            foreach (['Doctrine\\', 'ORM\\', 'metadata', 'payload', 'billing', 'rbac'] as $forbidden) {
                self::assertStringNotContainsString($forbidden, strtolower($source));
            }
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
