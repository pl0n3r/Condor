<?php

declare(strict_types=1);

namespace App\Tests\Infrastructure\Persistence;

use App\Domain\Commercial\EntitlementOverride;
use App\Domain\Commercial\Entity\AddOn;
use App\Domain\Commercial\Entity\Plan;
use App\Domain\Commercial\Entity\PlanVersion;
use App\Domain\Commercial\Entity\SubscriptionChangeRecord;
use App\Domain\Commercial\SubscriptionChange;
use App\Shared\Id\UlidFactory;
use DateTimeImmutable;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\Exception\AbortMigration;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Tools\SchemaTool;
use DoctrineMigrations\Version20260930204000;
use DomainException;
use Psr\Log\NullLogger;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

require_once dirname(__DIR__, 4).'/migrations/Version20260930204000.php';

final class SubscriptionChangePersistenceTest extends KernelTestCase
{
    public function testUpgradeRoundTripPreservesCanonicalChange(): void
    {
        self::bootKernel();
        $manager = $this->entityManager();
        [$current, $target] = $this->persistPlanPair($manager, 'upgrade');
        $fixtureSuffix = strtolower(UlidFactory::new());
        $alphaKey = 'alpha-upgrade-'.$fixtureSuffix;
        $zetaKey = 'zeta-upgrade-'.$fixtureSuffix;
        $alpha = new AddOn($alphaKey, 'Alpha upgrade', 10000);
        $zeta = new AddOn($zetaKey, 'Zeta upgrade', 20000);
        $manager->persist($alpha);
        $manager->persist($zeta);

        $requestedAt = new DateTimeImmutable('2026-10-01T12:00:00.123456-05:00');
        $change = SubscriptionChange::upgrade(
            'tenant-upgrade',
            $current,
            $target,
            $requestedAt,
            [$zeta, $alpha],
            [
                new EntitlementOverride(
                    'tenant-upgrade',
                    'limit',
                    'users',
                    25,
                    'Contrato anual',
                    'staff:commercial',
                    new DateTimeImmutable('2026-10-01T11:59:00.000001-05:00'),
                ),
                new EntitlementOverride(
                    'tenant-upgrade',
                    'capability',
                    'advanced-reports',
                    true,
                    'Add-on aprobado',
                    'staff:commercial',
                    new DateTimeImmutable('2026-10-01T11:58:00.000001-05:00'),
                ),
            ],
        );
        $record = SubscriptionChangeRecord::fromChange($change);
        $manager->persist($record);
        $manager->flush();
        $id = $record->id();
        $manager->clear();

        $loaded = $manager->find(SubscriptionChangeRecord::class, $id);
        self::assertInstanceOf(SubscriptionChangeRecord::class, $loaded);
        $restored = $loaded->toChange();

        self::assertSame(
            'tenant-upgrade',
            $restored->tenantId(),
        );
        self::assertSame(
            $current->id(),
            $restored->currentPlan()->id(),
        );
        self::assertSame(
            $target->id(),
            $restored->targetPlan()->id(),
        );
        self::assertSame(
            'upgrade',
            $restored->direction(),
        );
        self::assertSame(
            'effective',
            $restored->status(),
        );
        self::assertSame(
            $requestedAt->format('U.u'),
            $restored->requestedAt()->format('U.u'),
        );
        self::assertSame(
            $requestedAt->format('U.u'),
            $restored->effectiveAt()?->format('U.u'),
        );
        self::assertSame(
            [$alphaKey, $zetaKey],
            array_map(static fn (AddOn $addOn): string => $addOn->key(), $restored->addOns()),
        );
        self::assertSame(
            ['capability:advanced-reports', 'limit:users'],
            array_map(
                static fn (EntitlementOverride $override): string =>
                    $override->entitlementNamespace().':'.$override->key(),
                $restored->overrides(),
            ),
        );
        self::assertSame(
            [
                '2026-10-01T16:58:00.000001Z',
                '2026-10-01T16:59:00.000001Z',
            ],
            array_map(
                static fn (EntitlementOverride $override): string =>
                    $override->createdAt()->format('Y-m-d\\TH:i:s.u\\Z'),
                $restored->overrides(),
            ),
        );
        self::assertSame([], $restored->blockers());
    }

    public function testDowngradeRoundTripPreservesScheduleAndBlockers(): void
    {
        self::bootKernel();
        $manager = $this->entityManager();
        [$current, $target] = $this->persistPlanPair($manager, 'downgrade');
        $requestedAt = new DateTimeImmutable('2026-10-01T00:00:00.000001Z');
        $renewsAt = new DateTimeImmutable('2026-11-01T00:00:00.999999Z');

        $scheduled = SubscriptionChangeRecord::fromChange(SubscriptionChange::downgrade(
            'tenant-scheduled',
            $current,
            $target,
            $requestedAt,
            $renewsAt,
            true,
        ));
        $blocked = SubscriptionChangeRecord::fromChange(SubscriptionChange::downgrade(
            'tenant-blocked',
            $current,
            $target,
            $requestedAt,
            $renewsAt,
            false,
            [],
            [],
            ['users', 'active_addons'],
        ));
        $manager->persist($scheduled);
        $manager->persist($blocked);
        $manager->flush();
        $scheduledId = $scheduled->id();
        $blockedId = $blocked->id();
        $manager->clear();

        $scheduledLoaded = $manager->find(SubscriptionChangeRecord::class, $scheduledId);
        $blockedLoaded = $manager->find(SubscriptionChangeRecord::class, $blockedId);
        self::assertInstanceOf(SubscriptionChangeRecord::class, $scheduledLoaded);
        self::assertInstanceOf(SubscriptionChangeRecord::class, $blockedLoaded);
        $scheduledChange = $scheduledLoaded->toChange();
        $blockedChange = $blockedLoaded->toChange();

        self::assertSame('scheduled', $scheduledChange->status());
        self::assertSame($renewsAt->format('U.u'), $scheduledChange->effectiveAt()?->format('U.u'));
        self::assertSame([], $scheduledChange->blockers());
        self::assertSame('pending_resolution', $blockedChange->status());
        self::assertNull($blockedChange->effectiveAt());
        self::assertSame(['active_addons', 'users'], $blockedChange->blockers());
    }

    public function testCorruptOrCrossTenantPayloadFailsClosed(): void
    {
        self::bootKernel();
        $manager = $this->entityManager();
        [$current, $target] = $this->persistPlanPair($manager, 'corrupt');
        $this->assertRejected(fn () => SubscriptionChange::upgrade(
            'tenant-corrupt',
            $current,
            $target,
            new DateTimeImmutable('2026-10-01T00:00:00.000001Z'),
            [],
            [new EntitlementOverride(
                'tenant-other',
                'limit',
                'users',
                20,
                'Prueba',
                'staff:test',
                new DateTimeImmutable('2026-09-30T23:59:00.000001Z'),
            )],
        ));

        $change = SubscriptionChange::upgrade(
            'tenant-corrupt',
            $current,
            $target,
            new DateTimeImmutable('2026-10-01T00:00:00.000001Z'),
            [],
            [new EntitlementOverride(
                'tenant-corrupt',
                'limit',
                'users',
                20,
                'Prueba',
                'staff:test',
                new DateTimeImmutable('2026-09-30T23:59:00.000001Z'),
            )],
        );
        $record = SubscriptionChangeRecord::fromChange($change);
        $manager->persist($record);
        $manager->flush();
        $id = $record->id();
        $connection = $manager->getConnection();

        $validOverrides = $connection->fetchOne(
            'SELECT override_snapshots FROM condor_commercial_subscription_change WHERE id = ?',
            [$id],
        );
        self::assertIsString($validOverrides);

        $cases = [
            ['direction = ?', ['sideways']],
            ['status = ?', ['scheduled']],
            ['requested_at = ?', ['2026-10-01T00:00:00+00:00']],
            ['effective_at = ?', [null]],
            ['blockers = ?', [json_encode(['unknown'], JSON_THROW_ON_ERROR)]],
            ['blockers = ?', [json_encode(['users', 'users'], JSON_THROW_ON_ERROR)]],
            ['override_snapshots = ?', [json_encode([
                [
                    'tenant_id' => 'tenant-corrupt',
                    'namespace' => 'limit',
                    'key' => 'users',
                    'value' => 20,
                    'reason' => 'Prueba',
                    'actor' => 'staff:test',
                    'created_at' => '2026-09-30T23:59:00.000001Z',
                ],
                [
                    'tenant_id' => 'tenant-corrupt',
                    'namespace' => 'limit',
                    'key' => 'users',
                    'value' => 20,
                    'reason' => 'Prueba duplicada',
                    'actor' => 'staff:test',
                    'created_at' => '2026-09-30T23:59:00.000002Z',
                ],
            ], JSON_THROW_ON_ERROR)]],
            ['override_snapshots = ?', [json_encode([[
                'tenant_id' => 'tenant-other',
                'namespace' => 'limit',
                'key' => 'users',
                'value' => 20,
                'reason' => 'Prueba',
                'actor' => 'staff:test',
                'created_at' => '2026-09-30T23:59:00.000001Z',
            ]], JSON_THROW_ON_ERROR)]],
        ];

        foreach ($cases as [$assignment, $params]) {
            $connection->executeStatement(
                'UPDATE condor_commercial_subscription_change SET '
                .'direction = ?, status = ?, requested_at = ?, effective_at = ?, '
                .'blockers = ?, override_snapshots = ? WHERE id = ?',
                [
                    'upgrade',
                    'effective',
                    '2026-10-01T00:00:00.000001Z',
                    '2026-10-01T00:00:00.000001Z',
                    json_encode([], JSON_THROW_ON_ERROR),
                    $validOverrides,
                    $id,
                ],
            );
            $connection->executeStatement(
                'UPDATE condor_commercial_subscription_change SET '.$assignment.' WHERE id = ?',
                [...$params, $id],
            );
            $manager->clear();
            $corrupt = $manager->find(SubscriptionChangeRecord::class, $id);
            self::assertInstanceOf(SubscriptionChangeRecord::class, $corrupt);
            $this->assertRejected(static fn () => $corrupt->toChange());
        }
    }

    public function testDoctrinePersistsAuditableChangesWithoutMutatingSubscription(): void
    {
        self::bootKernel();
        $manager = $this->entityManager();
        [$current, $target] = $this->persistPlanPair($manager, 'audit');

        $auditTenant = 'tenant-audit-'.strtolower(UlidFactory::new());
        foreach ([
            [$auditTenant, '2026-10-01T00:00:00.000001Z'],
            [$auditTenant, '2026-10-02T00:00:00.000001Z'],
            ['tenant-other', '2026-10-03T00:00:00.000001Z'],
        ] as [$tenant, $at]) {
            $manager->persist(SubscriptionChangeRecord::fromChange(
                SubscriptionChange::upgrade(
                    $tenant,
                    $current,
                    $target,
                    new DateTimeImmutable($at),
                ),
            ));
        }
        $manager->flush();
        $manager->clear();

        $tenantChanges = $manager->getRepository(SubscriptionChangeRecord::class)
            ->findBy(['tenantId' => $auditTenant]);
        self::assertCount(2, $tenantChanges);

        $table = $manager->getConnection()
            ->createSchemaManager()
            ->introspectTable('condor_commercial_subscription_change');
        foreach ([
            'idx_commercial_sub_change_tenant_requested',
            'idx_commercial_sub_change_tenant_status_effective',
        ] as $index) {
            self::assertTrue($table->hasIndex($index), $index);
        }

        $foreignTables = array_map(
            static fn ($foreignKey): string =>
                $foreignKey->getReferencedTableName()->toString(),
            $table->getForeignKeys(),
        );
        self::assertSame(
            ['condor_commercial_plan_version', 'condor_commercial_plan_version'],
            array_values(array_filter(
                $foreignTables,
                static fn (string $tableName): bool =>
                    $tableName === 'condor_commercial_plan_version',
            )),
        );

        $source = file_get_contents(
            dirname(__DIR__, 4).'/src/Domain/Commercial/Entity/SubscriptionChangeRecord.php',
        );
        self::assertIsString($source);
        self::assertStringNotContainsString('syncFromLifecycle', $source);
        self::assertStringNotContainsString('Subscription::', $source);
    }

    public function testMigrationIsExpandCompatibleAndGuarded(): void
    {
        self::bootKernel();
        $manager = $this->entityManager();
        $connection = $manager->getConnection();
        $migrationSource = file_get_contents(
            dirname(__DIR__, 4).'/migrations/Version20260930204000.php',
        );
        self::assertIsString($migrationSource);
        $up = explode('public function down', $migrationSource, 2)[0];
        foreach ([
            'DROP TABLE ',
            'DROP COLUMN ',
            'DELETE FROM ',
            'TRUNCATE ',
            'RENAME TABLE ',
            'MODIFY COLUMN ',
            'CHANGE COLUMN ',
            'UPDATE condor_',
        ] as $destructive) {
            self::assertStringNotContainsString($destructive, $up);
        }

        [$current, $target] = $this->persistPlanPair($manager, 'rollback');
        $record = SubscriptionChangeRecord::fromChange(SubscriptionChange::upgrade(
            'tenant-rollback',
            $current,
            $target,
            new DateTimeImmutable('2026-10-01T00:00:00.000001Z'),
        ));
        $manager->persist($record);
        $manager->flush();

        $blocked = new Version20260930204000($connection, new NullLogger());
        try {
            $blocked->down(new Schema());
            self::fail('El rollback debe abortar mientras existan cambios.');
        } catch (AbortMigration) {
            self::assertContains(
                'condor_commercial_subscription_change',
                $connection->createSchemaManager()->listTableNames(),
            );
        }
    }

    public function testSchemaMatchesDoctrineAndRelationsAreCanonical(): void
    {
        self::bootKernel();
        $manager = $this->entityManager();
        $metadata = $manager->getClassMetadata(SubscriptionChangeRecord::class);
        $schema = new SchemaTool($manager)->getSchemaFromMetadata([$metadata]);
        $generated = $schema->getTable('condor_commercial_subscription_change');
        $migrated = $manager->getConnection()
            ->createSchemaManager()
            ->introspectTable('condor_commercial_subscription_change');

        foreach ([
            'id',
            'tenant_id',
            'current_plan_version_id',
            'target_plan_version_id',
            'direction',
            'status',
            'requested_at',
            'effective_at',
            'blockers',
            'override_snapshots',
        ] as $column) {
            self::assertTrue($generated->hasColumn($column), $column);
            self::assertTrue($migrated->hasColumn($column), $column);
        }
        self::assertTrue($schema->hasTable('condor_commercial_subscription_change_addon'));
        self::assertTrue($manager->getConnection()->createSchemaManager()
            ->tablesExist(['condor_commercial_subscription_change_addon']));

        $joinTable = $manager->getConnection()
            ->createSchemaManager()
            ->introspectTable('condor_commercial_subscription_change_addon');
        $foreignKeyRules = $manager->getConnection()->fetchAllAssociative(
            'SELECT REFERENCED_TABLE_NAME, DELETE_RULE '
            .'FROM information_schema.REFERENTIAL_CONSTRAINTS '
            .'WHERE CONSTRAINT_SCHEMA = DATABASE() AND TABLE_NAME = ?',
            ['condor_commercial_subscription_change_addon'],
        );
        $deleteRules = [];
        foreach ($foreignKeyRules as $foreignKeyRule) {
            $deleteRules[$foreignKeyRule['REFERENCED_TABLE_NAME']] = $foreignKeyRule['DELETE_RULE'];
        }
        self::assertSame(
            'RESTRICT',
            $deleteRules['condor_commercial_addon'] ?? null,
        );
        self::assertSame(
            'CASCADE',
            $deleteRules['condor_commercial_subscription_change'] ?? null,
        );
    }

    private function entityManager(): EntityManagerInterface
    {
        $manager = self::getContainer()->get(EntityManagerInterface::class);
        self::assertInstanceOf(EntityManagerInterface::class, $manager);

        return $manager;
    }

    /** @return array{PlanVersion,PlanVersion} */
    private function persistPlanPair(
        EntityManagerInterface $manager,
        string $suffix,
    ): array {
        $suffix .= '-'.strtolower(UlidFactory::new());
        $currentPlan = new Plan('current-'.$suffix, 'Current '.$suffix);
        $targetPlan = new Plan('target-'.$suffix, 'Target '.$suffix);
        $current = new PlanVersion(
            $currentPlan,
            1,
            499900,
            4999000,
            false,
            ['users' => 10],
            new DateTimeImmutable('2026-01-01T00:00:00Z'),
        );
        $target = new PlanVersion(
            $targetPlan,
            1,
            699900,
            6999000,
            false,
            ['users' => 25],
            new DateTimeImmutable('2026-01-01T00:00:00Z'),
        );
        foreach ([$currentPlan, $targetPlan, $current, $target] as $entity) {
            $manager->persist($entity);
        }

        return [$current, $target];
    }

    /** @param callable():mixed $operation */
    private function assertRejected(callable $operation): void
    {
        try {
            $operation();
            self::fail('La fila persistida incompatible debía fallar.');
        } catch (DomainException) {
            self::assertTrue(true);
        }
    }
}
