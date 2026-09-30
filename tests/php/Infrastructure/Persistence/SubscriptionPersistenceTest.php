<?php

declare(strict_types=1);

namespace App\Tests\Infrastructure\Persistence;

use App\Domain\Commercial\Entity\Plan;
use App\Domain\Commercial\Entity\PlanVersion;
use App\Domain\Commercial\Entity\Subscription;
use App\Domain\Commercial\SubscriptionLifecycle;
use App\Domain\Commercial\SubscriptionState;
use DateTimeImmutable;
use Doctrine\DBAL\Schema\Table;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\OptimisticLockException;
use Doctrine\ORM\Tools\SchemaTool;
use DomainException;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

final class SubscriptionPersistenceTest extends KernelTestCase
{
    public function testSchemaContract(): void
    {
        self::bootKernel();
        $manager = $this->entityManager();
        $generated = (new SchemaTool($manager))->getSchemaFromMetadata(
            $manager->getMetadataFactory()->getAllMetadata(),
        );
        $table = $generated->getTable('condor_commercial_subscription');
        $migrated = $manager->getConnection()
            ->createSchemaManager()
            ->introspectTable('condor_commercial_subscription');

        foreach ([
            'id',
            'tenant_id',
            'plan_version_id',
            'state',
            'history',
            'last_changed_at',
            'created_at',
            'updated_at',
            'last_changed_at_exact',
            'created_at_exact',
            'updated_at_exact',
            'lock_version',
        ] as $column) {
            self::assertTrue($table->hasColumn($column), $column);
            self::assertTrue($migrated->hasColumn($column), $column);
        }

        foreach (['last_changed_at_exact', 'created_at_exact', 'updated_at_exact'] as $column) {
            self::assertSame(32, $table->getColumn($column)->getLength());
            self::assertSame(32, $migrated->getColumn($column)->getLength());
        }

        self::assertTrue($table->hasIndex('uniq_commercial_subscription_tenant'));
        self::assertTrue($migrated->hasIndex('uniq_commercial_subscription_tenant'));
        self::assertTrue($table->hasIndex('idx_commercial_subscription_state'));
        self::assertTrue($migrated->hasIndex('idx_commercial_subscription_state'));
        $this->assertReferences($table, 'condor_commercial_plan_version');
        $this->assertReferences($migrated, 'condor_commercial_plan_version');

        $metadata = $manager->getClassMetadata(Subscription::class);
        self::assertTrue($metadata->isVersioned);
        self::assertSame('lockVersion', $metadata->versionField);

        foreach ([
            'Version20260930133500.php',
            'Version20260930143000.php',
        ] as $migrationName) {
            $migration = file_get_contents(
                dirname(__DIR__, 4).'/migrations/'.$migrationName,
            );
            self::assertIsString($migration);
            $up = explode('public function down', $migration, 2)[0];
            foreach (['DROP TABLE', 'DROP COLUMN', 'DELETE FROM', 'TRUNCATE', 'RENAME TABLE'] as $destructive) {
                self::assertStringNotContainsString($destructive, $up);
            }
        }
    }

    public function testRoundTrip(): void
    {
        self::bootKernel();
        $manager = $this->entityManager();
        $planVersion = $this->persistPlanVersion($manager, 'round-trip');
        $lifecycle = new SubscriptionLifecycle(
            'tenant-round-trip',
            $planVersion,
            SubscriptionState::Trialing,
            new DateTimeImmutable('2026-10-01T00:00:00.000001Z'),
        );
        $lifecycle->transitionTo(
            SubscriptionState::Active,
            new DateTimeImmutable('2026-10-02T12:00:00.123456Z'),
        );

        $recordedAt = new DateTimeImmutable('2026-10-02T12:01:00.654321Z');
        $subscription = Subscription::fromLifecycle($lifecycle, $recordedAt);
        $manager->persist($subscription);
        $manager->flush();

        $id = $subscription->id();
        $expectedPlanVersionId = $planVersion->id();
        $expectedHistory = $lifecycle->history();
        $expectedLastChanged = $lifecycle->lastChangedAt()->format('U.u');
        $manager->clear();

        $loaded = $manager->find(Subscription::class, $id);
        self::assertInstanceOf(Subscription::class, $loaded);
        $restored = $loaded->toLifecycle();

        self::assertSame('tenant-round-trip', $restored->tenantId());
        self::assertSame($expectedPlanVersionId, $restored->planVersion()->id());
        self::assertSame(SubscriptionState::Active, $restored->state());
        self::assertSame($expectedHistory, $restored->history());
        self::assertSame($expectedLastChanged, $restored->lastChangedAt()->format('U.u'));
        self::assertSame($expectedLastChanged, $loaded->lastChangedAt()->format('U.u'));
        self::assertSame($recordedAt->format('U.u'), $loaded->createdAt()->format('U.u'));
        self::assertSame($recordedAt->format('U.u'), $loaded->updatedAt()->format('U.u'));
        self::assertSame(
            '2026-10-02T12:01:00.654321+00:00',
            $loaded->createdAt()->format('Y-m-d\\TH:i:s.uP'),
        );
        self::assertSame(
            '2026-10-02T12:01:00.654321+00:00',
            $loaded->updatedAt()->format('Y-m-d\\TH:i:s.uP'),
        );

        // Simula una fila V0.1.91 y ejecuta exactamente el backfill del repair.
        $legacyHistory = array_map(
            static fn (array $entry): array => [
                'state' => $entry['state'],
                'at' => str_replace('Z', '+00:00', $entry['at']),
            ],
            $expectedHistory,
        );
        $manager->getConnection()->executeStatement(
            'UPDATE condor_commercial_subscription '
            .'SET history = ?, last_changed_at_exact = NULL, '
            .'created_at_exact = NULL, updated_at_exact = NULL WHERE id = ?',
            [json_encode($legacyHistory, JSON_THROW_ON_ERROR), $id],
        );

        $legacyBackfillSql = <<<'SQL'
UPDATE condor_commercial_subscription
SET last_changed_at_exact = JSON_UNQUOTE(
        JSON_EXTRACT(
            history,
            CONCAT('$[', JSON_LENGTH(history) - 1, '].at')
        )
    ),
    created_at_exact = CONCAT(
        DATE_FORMAT(created_at, '%Y-%m-%dT%H:%i:%s.'),
        LPAD(MICROSECOND(created_at), 6, '0'),
        'Z'
    ),
    updated_at_exact = CONCAT(
        DATE_FORMAT(updated_at, '%Y-%m-%dT%H:%i:%s.'),
        LPAD(MICROSECOND(updated_at), 6, '0'),
        'Z'
    )
WHERE JSON_LENGTH(history) > 0
  AND last_changed_at_exact IS NULL
SQL;
        $migration = file_get_contents(
            dirname(__DIR__, 4).'/migrations/Version20260930143000.php',
        );
        self::assertIsString($migration);
        self::assertStringContainsString($legacyBackfillSql, $migration);
        $manager->getConnection()->executeStatement($legacyBackfillSql);
        $manager->clear();

        $legacy = $manager->find(Subscription::class, $id);
        self::assertInstanceOf(Subscription::class, $legacy);
        self::assertSame(
            $expectedLastChanged,
            $legacy->lastChangedAt()->format('U.u'),
        );
        self::assertSame(
            $expectedLastChanged,
            $legacy->toLifecycle()->lastChangedAt()->format('U.u'),
        );
        self::assertSame(
            '2026-10-02T12:00:00.123456+00:00',
            $manager->getConnection()->fetchOne(
                'SELECT last_changed_at_exact '
                .'FROM condor_commercial_subscription WHERE id = ?',
                [$id],
            ),
        );
    }

    public function testIdentityIsolation(): void
    {
        self::bootKernel();
        $manager = $this->entityManager();
        $planVersion = $this->persistPlanVersion($manager, 'identity-a');
        $otherPlanVersion = $this->persistPlanVersion($manager, 'identity-b');
        $lifecycle = new SubscriptionLifecycle(
            'tenant-identity',
            $planVersion,
            SubscriptionState::Active,
            new DateTimeImmutable('2026-10-01T00:00:00Z'),
        );
        $subscription = Subscription::fromLifecycle(
            $lifecycle,
            new DateTimeImmutable('2026-10-01T00:00:01Z'),
        );
        $before = $subscription->history();

        $this->assertRejected(fn () => $subscription->syncFromLifecycle(
            new SubscriptionLifecycle(
                'tenant-other',
                $planVersion,
                SubscriptionState::Active,
                new DateTimeImmutable('2026-10-02T00:00:00Z'),
            ),
            new DateTimeImmutable('2026-10-02T00:00:01Z'),
        ));
        $this->assertRejected(fn () => $subscription->syncFromLifecycle(
            new SubscriptionLifecycle(
                'tenant-identity',
                $otherPlanVersion,
                SubscriptionState::Active,
                new DateTimeImmutable('2026-10-02T00:00:00Z'),
            ),
            new DateTimeImmutable('2026-10-02T00:00:01Z'),
        ));

        $this->assertRejected(fn () => Subscription::fromLifecycle(
            $lifecycle,
            new DateTimeImmutable('2026-09-30T23:59:59Z'),
        ));

        $lifecycle->transitionTo(
            SubscriptionState::PastDue,
            new DateTimeImmutable('2026-10-02T00:00:00Z'),
        );
        $this->assertRejected(fn () => $subscription->syncFromLifecycle(
            $lifecycle,
            new DateTimeImmutable('2026-10-01T12:00:00Z'),
        ));

        self::assertSame($before, $subscription->history());
        self::assertSame($planVersion->id(), $subscription->planVersion()->id());

        $this->assertRejected(fn () => Subscription::fromLifecycle(
            new SubscriptionLifecycle(
                'tenant-too-early',
                $planVersion,
                SubscriptionState::Active,
                new DateTimeImmutable('2026-10-03T00:00:00.123456Z'),
            ),
            new DateTimeImmutable('2026-10-03T00:00:00.123455Z'),
        ));

        $current = new SubscriptionLifecycle(
            'tenant-identity',
            $planVersion,
            SubscriptionState::Active,
            new DateTimeImmutable('2026-10-01T00:00:00.000001Z'),
        );
        $currentSubscription = Subscription::fromLifecycle(
            $current,
            new DateTimeImmutable('2026-10-01T00:00:01.000001Z'),
        );
        $current->transitionTo(
            SubscriptionState::PastDue,
            new DateTimeImmutable('2026-10-02T00:00:00.123456Z'),
        );
        $this->assertRejected(fn () => $currentSubscription->syncFromLifecycle(
            $current,
            new DateTimeImmutable('2026-10-02T00:00:00.123455Z'),
        ));
        self::assertSame(SubscriptionState::Active, $currentSubscription->state());
    }

    public function testOptimisticLocking(): void
    {
        self::bootKernel();
        $manager = $this->entityManager();
        $planVersion = $this->persistPlanVersion($manager, 'optimistic');
        $lifecycle = new SubscriptionLifecycle(
            'tenant-optimistic',
            $planVersion,
            SubscriptionState::Active,
            new DateTimeImmutable('2026-10-01T00:00:00Z'),
        );
        $subscription = Subscription::fromLifecycle(
            $lifecycle,
            new DateTimeImmutable('2026-10-01T00:00:01Z'),
        );
        $manager->persist($subscription);
        $manager->flush();

        $manager->getConnection()->executeStatement(
            'UPDATE condor_commercial_subscription '
            .'SET lock_version = lock_version + 1 WHERE id = ?',
            [$subscription->id()],
        );

        $lifecycle->transitionTo(
            SubscriptionState::PastDue,
            new DateTimeImmutable('2026-10-02T00:00:00Z'),
        );
        $subscription->syncFromLifecycle(
            $lifecycle,
            new DateTimeImmutable('2026-10-02T00:00:01Z'),
        );

        $this->expectException(OptimisticLockException::class);
        $manager->flush();
    }

    public function testPayloadBoundaryContract(): void
    {
        self::bootKernel();
        $table = $this->entityManager()
            ->getConnection()
            ->createSchemaManager()
            ->introspectTable('condor_commercial_subscription');

        foreach (['email', 'phone', 'name', 'metadata', 'payload', 'usage', 'billing'] as $forbidden) {
            self::assertFalse($table->hasColumn($forbidden), $forbidden);
        }

        $source = file_get_contents(
            dirname(__DIR__, 4).'/src/Domain/Commercial/Entity/Subscription.php',
        );
        self::assertIsString($source);
        foreach (['UsageRecord', 'UsageLedger', 'PermissionCatalog', 'EntitlementResolver'] as $forbidden) {
            self::assertStringNotContainsString($forbidden, $source);
        }
    }

    private function entityManager(): EntityManagerInterface
    {
        $manager = static::getContainer()->get(EntityManagerInterface::class);
        self::assertInstanceOf(EntityManagerInterface::class, $manager);

        return $manager;
    }

    private function persistPlanVersion(
        EntityManagerInterface $manager,
        string $suffix,
    ): PlanVersion {
        $plan = new Plan('subscription-'.$suffix, 'Subscription '.$suffix);
        $planVersion = new PlanVersion(
            $plan,
            1,
            499900,
            4999000,
            false,
            ['users' => 10],
            new DateTimeImmutable('2026-01-01T00:00:00Z'),
        );
        $manager->persist($plan);
        $manager->persist($planVersion);

        return $planVersion;
    }

    private function assertReferences(Table $table, string $expected): void
    {
        $actual = array_map(
            static fn ($foreignKey): string => $foreignKey
                ->getReferencedTableName()
                ->toString(),
            $table->getForeignKeys(),
        );

        self::assertContains($expected, $actual);
    }

    /** @param callable():mixed $operation */
    private function assertRejected(callable $operation): void
    {
        try {
            $operation();
            self::fail('La operación incompatible debía fallar.');
        } catch (DomainException) {
            self::assertTrue(true);
        }
    }
}
