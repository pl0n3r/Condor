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
            'lock_version',
        ] as $column) {
            self::assertTrue($table->hasColumn($column), $column);
            self::assertTrue($migrated->hasColumn($column), $column);
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

        $migration = file_get_contents(
            dirname(__DIR__, 4).'/migrations/Version20260930133500.php',
        );
        self::assertIsString($migration);
        $up = explode('public function down', $migration, 2)[0];
        foreach (['DROP TABLE', 'DROP COLUMN', 'DELETE FROM', 'TRUNCATE', 'RENAME TABLE'] as $destructive) {
            self::assertStringNotContainsString($destructive, $up);
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

        $subscription = Subscription::fromLifecycle(
            $lifecycle,
            new DateTimeImmutable('2026-10-02T12:01:00.654321Z'),
        );
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

        self::assertSame($before, $subscription->history());
        self::assertSame($planVersion->id(), $subscription->planVersion()->id());
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
