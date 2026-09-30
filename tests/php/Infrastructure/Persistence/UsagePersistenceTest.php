<?php

declare(strict_types=1);

namespace App\Tests\Infrastructure\Persistence;

use App\Domain\Commercial\Entity\UsageObservation;
use App\Domain\Commercial\UsageLedger;
use App\Domain\Commercial\UsageMetric;
use App\Domain\Commercial\UsageRecord;
use DateTimeImmutable;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Tools\SchemaTool;
use DomainException;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

final class UsagePersistenceTest extends KernelTestCase
{
    public function testSchemaContract(): void
    {
        self::bootKernel();
        $manager = $this->entityManager();
        $generated = (new SchemaTool($manager))->getSchemaFromMetadata(
            $manager->getMetadataFactory()->getAllMetadata(),
        );
        $table = $generated->getTable('condor_commercial_usage_observation');
        $migrated = $manager->getConnection()
            ->createSchemaManager()
            ->introspectTable('condor_commercial_usage_observation');

        foreach ([
            'id',
            'tenant_id',
            'metric',
            'quantity',
            'window_start',
            'window_end',
            'observed_at',
        ] as $column) {
            self::assertTrue($table->hasColumn($column), $column);
            self::assertTrue($migrated->hasColumn($column), $column);
        }

        foreach (['window_start', 'window_end', 'observed_at'] as $column) {
            self::assertSame(32, $table->getColumn($column)->getLength());
            self::assertSame(32, $migrated->getColumn($column)->getLength());
        }

        foreach ([
            'idx_commercial_usage_tenant_metric_window',
            'idx_commercial_usage_tenant_observed',
        ] as $index) {
            self::assertTrue($table->hasIndex($index), $index);
            self::assertTrue($migrated->hasIndex($index), $index);
        }

        $migration = file_get_contents(
            dirname(__DIR__, 4).'/migrations/Version20260930150000.php',
        );
        self::assertIsString($migration);
        $up = explode('public function down', $migration, 2)[0];
        foreach ([
            'UPDATE ',
            'DELETE ',
            'DROP ',
            'TRUNCATE ',
            'RENAME ',
            'MODIFY ',
            'CHANGE ',
        ] as $destructive) {
            self::assertStringNotContainsString($destructive, $up);
        }
    }

    public function testExactRoundTrip(): void
    {
        self::bootKernel();
        $manager = $this->entityManager();
        $record = new UsageRecord(
            'tenant-round-trip',
            UsageMetric::ApiCalls,
            17,
            new DateTimeImmutable('2026-10-01T00:00:00.000001-05:00'),
            new DateTimeImmutable('2026-10-01T01:00:00.999999-05:00'),
            new DateTimeImmutable('2026-10-01T00:30:00.654321-05:00'),
        );
        $observation = UsageObservation::fromRecord($record);
        $manager->persist($observation);
        $manager->flush();

        $id = $observation->id();
        $manager->clear();

        $loaded = $manager->find(UsageObservation::class, $id);
        self::assertInstanceOf(UsageObservation::class, $loaded);
        $restored = $loaded->toRecord();

        self::assertSame($record->tenantId(), $restored->tenantId());
        self::assertSame($record->metric(), $restored->metric());
        self::assertSame($record->quantity(), $restored->quantity());
        self::assertSame($record->windowStart()->format('U.u'), $restored->windowStart()->format('U.u'));
        self::assertSame($record->windowEnd()->format('U.u'), $restored->windowEnd()->format('U.u'));
        self::assertSame($record->observedAt()->format('U.u'), $restored->observedAt()->format('U.u'));

        $row = $manager->getConnection()->fetchAssociative(
            'SELECT window_start, window_end, observed_at '
            .'FROM condor_commercial_usage_observation WHERE id = ?',
            [$id],
        );
        self::assertIsArray($row);
        self::assertSame('2026-10-01T05:00:00.000001Z', $row['window_start']);
        self::assertSame('2026-10-01T06:00:00.999999Z', $row['window_end']);
        self::assertSame('2026-10-01T05:30:00.654321Z', $row['observed_at']);
    }

    public function testCorruptPersistenceFailsClosed(): void
    {
        self::bootKernel();
        $manager = $this->entityManager();
        $observation = UsageObservation::fromRecord(new UsageRecord(
            'tenant-corrupt',
            UsageMetric::Orders,
            4,
            new DateTimeImmutable('2026-10-01T00:00:00.000001Z'),
            new DateTimeImmutable('2026-10-01T01:00:00.000001Z'),
            new DateTimeImmutable('2026-10-01T00:30:00.000001Z'),
        ));
        $manager->persist($observation);
        $manager->flush();
        $id = $observation->id();

        $valid = [
            'tenant-corrupt',
            'orders',
            4,
            '2026-10-01T00:00:00.000001Z',
            '2026-10-01T01:00:00.000001Z',
            '2026-10-01T00:30:00.000001Z',
            $id,
        ];
        $cases = [
            ['tenant_id = ?', ['']],
            ['metric = ?', ['unknown']],
            ['metric = ?', ['123']],
            ['quantity = ?', [-1]],
            ['window_end = ?', ['2026-10-01T00:00:00.000001Z']],
            ['window_start = ?', ['2026-02-30T00:00:00.000001Z']],
            ['observed_at = ?', ['2026-10-01T00:30:00+00:00']],
            ['observed_at = ?', ['2026-09-30T23:59:59.999999Z']],
        ];

        foreach ($cases as [$assignment, $params]) {
            $manager->getConnection()->executeStatement(
                'UPDATE condor_commercial_usage_observation SET '
                .'tenant_id = ?, metric = ?, quantity = ?, window_start = ?, '
                .'window_end = ?, observed_at = ? WHERE id = ?',
                $valid,
            );
            $manager->getConnection()->executeStatement(
                'UPDATE condor_commercial_usage_observation SET '.$assignment.' WHERE id = ?',
                [...$params, $id],
            );
            $manager->clear();

            $corrupt = $manager->find(UsageObservation::class, $id);
            self::assertInstanceOf(UsageObservation::class, $corrupt);
            $this->assertRejected(static fn () => $corrupt->toRecord());
        }
    }

    public function testLedgerSemanticsSurviveRoundTrip(): void
    {
        self::bootKernel();
        $manager = $this->entityManager();
        $start = new DateTimeImmutable('2026-10-01T00:00:00.000001Z');
        $end = new DateTimeImmutable('2026-11-01T00:00:00.000001Z');

        foreach ([
            [UsageMetric::Users, 1, '2026-10-02T00:00:00.000001Z'],
            [UsageMetric::Users, 4, '2026-10-03T00:00:00.000001Z'],
            [UsageMetric::Orders, 2, '2026-10-04T00:00:00.000001Z'],
            [UsageMetric::Orders, 3, '2026-10-05T00:00:00.000001Z'],
            [UsageMetric::StorageMb, 0, '2026-10-06T00:00:00.000001Z'],
        ] as [$metric, $quantity, $observedAt]) {
            $manager->persist(UsageObservation::fromRecord(new UsageRecord(
                'tenant-ledger',
                $metric,
                $quantity,
                $start,
                $end,
                new DateTimeImmutable($observedAt),
            )));
        }
        $manager->persist(UsageObservation::fromRecord(new UsageRecord(
            'tenant-other',
            UsageMetric::Users,
            99,
            $start,
            $end,
            new DateTimeImmutable('2026-10-07T00:00:00.000001Z'),
        )));
        $manager->flush();
        $manager->clear();

        $loaded = $manager->getRepository(UsageObservation::class)
            ->findBy(['tenantId' => 'tenant-ledger']);
        self::assertCount(5, $loaded);

        $ledger = new UsageLedger('tenant-ledger');
        foreach ($loaded as $observation) {
            self::assertInstanceOf(UsageObservation::class, $observation);
            $ledger->add($observation->toRecord());
        }

        self::assertSame(4, $ledger->quantity(UsageMetric::Users, $start, $end));
        self::assertSame(5, $ledger->quantity(UsageMetric::Orders, $start, $end));
        self::assertSame(0, $ledger->quantity(UsageMetric::StorageMb, $start, $end));
        self::assertNull($ledger->quantity(UsageMetric::ApiCalls, $start, $end));
    }

    public function testPayloadBoundary(): void
    {
        self::bootKernel();
        $table = $this->entityManager()
            ->getConnection()
            ->createSchemaManager()
            ->introspectTable('condor_commercial_usage_observation');

        foreach ([
            'email',
            'phone',
            'name',
            'metadata',
            'payload',
            'billing',
            'user_id',
            'customer_id',
        ] as $forbidden) {
            self::assertFalse($table->hasColumn($forbidden), $forbidden);
        }

        $source = file_get_contents(
            dirname(__DIR__, 4).'/src/Domain/Commercial/Entity/UsageObservation.php',
        );
        self::assertIsString($source);
        foreach ([
            'EntitlementResolver',
            'PermissionCatalog',
            'Invoice',
            'Payment',
            'Billing',
        ] as $forbidden) {
            self::assertStringNotContainsString($forbidden, $source);
        }
    }

    private function entityManager(): EntityManagerInterface
    {
        $manager = static::getContainer()->get(EntityManagerInterface::class);
        self::assertInstanceOf(EntityManagerInterface::class, $manager);

        return $manager;
    }

    /** @param callable():mixed $operation */
    private function assertRejected(callable $operation): void
    {
        try {
            $operation();
            self::fail('La observación persistida inválida debía fallar.');
        } catch (DomainException) {
            self::assertTrue(true);
        }
    }
}
