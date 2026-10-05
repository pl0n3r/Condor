<?php

declare(strict_types=1);

namespace App\Tests\Infrastructure\Persistence;

use App\Application\Commercial\CommercialCatalogSeeder;
use App\Domain\Commercial\Entity\AddOn;
use App\Domain\Commercial\Entity\Plan;
use App\Domain\Commercial\Entity\PlanVersion;
use App\Domain\Commercial\Entity\Subscription;
use App\Domain\Commercial\Entity\SubscriptionConfiguration;
use App\Domain\Commercial\Entity\Vertical;
use App\Domain\Commercial\SubscriptionLifecycle;
use App\Domain\Commercial\SubscriptionState;
use DateTimeImmutable;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Tools\SchemaTool;
use DomainException;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

final class SubscriptionConfigurationPersistenceTest extends KernelTestCase
{
    private EntityManagerInterface $manager;

    protected function setUp(): void
    {
        self::bootKernel();
        $manager = static::getContainer()->get(EntityManagerInterface::class);
        self::assertInstanceOf(EntityManagerInterface::class, $manager);
        $this->manager = $manager;

        (new CommercialCatalogSeeder($manager))->seed();
    }

    public function testRoundTripPreservesVerticalQuantitiesAndCanonicalAddOnsOncePerSubscription(): void
    {
        $subscription = $this->subscription('tenant-config-round-trip');
        $vertical = $this->vertical('commerce');
        $production = $this->addOn('production-lite');
        $extraUser = $this->addOn('extra-user');
        $configuredAt = new DateTimeImmutable('2026-10-05T10:00:00Z');

        $configuration = new SubscriptionConfiguration(
            $subscription,
            $vertical,
            ['users' => 12, 'locations' => 3, 'companies' => 1],
            [$production, $extraUser],
            $configuredAt,
        );
        $this->manager->persist($subscription);
        $this->manager->persist($configuration);
        $this->manager->flush();

        $id = $configuration->id();
        $this->manager->clear();

        $loaded = $this->manager->find(SubscriptionConfiguration::class, $id);
        self::assertInstanceOf(SubscriptionConfiguration::class, $loaded);
        self::assertSame('tenant-config-round-trip', $loaded->subscription()->tenantId());
        self::assertSame('commerce', $loaded->vertical()->key());
        self::assertSame(
            ['companies' => 1, 'locations' => 3, 'users' => 12],
            $loaded->quantities(),
        );
        self::assertSame(
            ['extra-user', 'production-lite'],
            array_map(
                static fn (AddOn $addOn): string => $addOn->key(),
                $loaded->addOns(),
            ),
        );
        self::assertSame(1, $loaded->lockVersion());

        self::assertSame(
            1,
            $this->manager
                ->getRepository(SubscriptionConfiguration::class)
                ->count(['subscription' => $loaded->subscription()]),
        );
    }

    public function testRejectsIncompatibleInactiveDuplicateAddOnsOrNonCanonicalQuantities(): void
    {
        $subscription = $this->subscription('tenant-config-reject');
        $vertical = $this->vertical('commerce');
        $production = $this->addOn('production-lite');

        $this->assertRejected(fn () => new SubscriptionConfiguration(
            $subscription,
            $vertical,
            ['users' => 9, 'locations' => 3, 'companies' => 1],
            [$production],
            new DateTimeImmutable('2026-10-05T10:00:00Z'),
        ));
        $this->assertRejected(fn () => new SubscriptionConfiguration(
            $subscription,
            $vertical,
            ['users' => 10, 'locations' => 3],
            [$production],
            new DateTimeImmutable('2026-10-05T10:00:00Z'),
        ));
        $this->assertRejected(fn () => new SubscriptionConfiguration(
            $subscription,
            $vertical,
            ['users' => 10, 'locations' => 3, 'companies' => 1],
            [$production, $production],
            new DateTimeImmutable('2026-10-05T10:00:00Z'),
        ));

        $inactive = $this->addOn('premium-integration');
        $inactive->deactivate();
        $this->assertRejected(fn () => new SubscriptionConfiguration(
            $subscription,
            $vertical,
            ['users' => 10, 'locations' => 3, 'companies' => 1],
            [$inactive],
            new DateTimeImmutable('2026-10-05T10:00:00Z'),
        ));

        $zeroPlan = new Plan('zero-baseline', 'Zero baseline');
        $zeroVersion = new PlanVersion(
            $zeroPlan,
            1,
            1,
            1,
            false,
            ['users' => 0],
            new DateTimeImmutable('2026-01-01T00:00:00Z'),
        );
        $zeroVersion->addVertical($vertical);
        $zeroLifecycle = new SubscriptionLifecycle(
            'tenant-config-zero',
            $zeroVersion,
            SubscriptionState::Active,
            new DateTimeImmutable('2026-10-05T09:00:00Z'),
        );
        $zeroSubscription = Subscription::fromLifecycle(
            $zeroLifecycle,
            new DateTimeImmutable('2026-10-05T09:00:01Z'),
        );
        $this->assertRejected(fn () => new SubscriptionConfiguration(
            $zeroSubscription,
            $vertical,
            ['users' => 0],
            [],
            new DateTimeImmutable('2026-10-05T10:00:00Z'),
        ));

        $pro = $this->planVersion('pro');
        $proLifecycle = new SubscriptionLifecycle(
            'tenant-config-pro',
            $pro,
            SubscriptionState::Active,
            new DateTimeImmutable('2026-10-05T09:00:00Z'),
        );
        $proSubscription = Subscription::fromLifecycle(
            $proLifecycle,
            new DateTimeImmutable('2026-10-05T09:00:01Z'),
        );
        $this->assertRejected(fn () => new SubscriptionConfiguration(
            $proSubscription,
            $vertical,
            ['users' => 30, 'locations' => 10, 'companies' => 5],
            [$production],
            new DateTimeImmutable('2026-10-05T10:00:00Z'),
        ));
    }

    public function testSchemaIsAdditiveUniqueAndLegacySubscriptionCanExistWithoutConfiguration(): void
    {
        $generated = (new SchemaTool($this->manager))->getSchemaFromMetadata(
            $this->manager->getMetadataFactory()->getAllMetadata(),
        );
        $migrated = $this->manager
            ->getConnection()
            ->createSchemaManager()
            ->introspectSchema();

        foreach ([$generated, $migrated] as $schema) {
            self::assertTrue(
                $schema->hasTable('condor_commercial_subscription_configuration'),
            );
            self::assertTrue(
                $schema->hasTable('condor_commercial_subscription_configuration_addon'),
            );

            $configuration = $schema->getTable(
                'condor_commercial_subscription_configuration',
            );
            self::assertTrue(
                $configuration->hasIndex(
                    'uniq_commercial_subscription_configuration_subscription',
                ),
            );
            self::assertTrue($configuration->hasColumn('quantities'));
            self::assertTrue($configuration->hasColumn('lock_version'));
        }

        $legacy = $this->subscription('tenant-config-legacy');
        $this->manager->persist($legacy);
        $this->manager->flush();

        self::assertSame(
            0,
            $this->manager
                ->getRepository(SubscriptionConfiguration::class)
                ->count(['subscription' => $legacy]),
        );

        $migration = file_get_contents(
            dirname(__DIR__, 4).'/migrations/Version20261005100000.php',
        );
        self::assertIsString($migration);
        $up = explode('public function down', $migration, 2)[0];
        foreach ([
            'ALTER TABLE condor_commercial_subscription ',
            'UPDATE condor_commercial_subscription',
            'DROP TABLE',
            'DROP COLUMN',
            'DELETE FROM',
            'TRUNCATE',
            'RENAME TABLE',
        ] as $forbidden) {
            self::assertStringNotContainsString($forbidden, $up);
        }
    }

    private function subscription(string $tenantId): Subscription
    {
        $planVersion = $this->planVersion('business');
        $lifecycle = new SubscriptionLifecycle(
            $tenantId,
            $planVersion,
            SubscriptionState::Active,
            new DateTimeImmutable('2026-10-05T09:00:00Z'),
        );

        return Subscription::fromLifecycle(
            $lifecycle,
            new DateTimeImmutable('2026-10-05T09:00:01Z'),
        );
    }

    private function planVersion(string $key): PlanVersion
    {
        $plan = $this->manager
            ->getRepository(Plan::class)
            ->findOneBy(['key' => $key]);
        self::assertInstanceOf(Plan::class, $plan);

        $version = $this->manager
            ->getRepository(PlanVersion::class)
            ->findOneBy(['plan' => $plan, 'version' => 1]);
        self::assertInstanceOf(PlanVersion::class, $version);

        return $version;
    }

    private function vertical(string $key): Vertical
    {
        $vertical = $this->manager
            ->getRepository(Vertical::class)
            ->findOneBy(['key' => $key]);
        self::assertInstanceOf(Vertical::class, $vertical);

        return $vertical;
    }

    private function addOn(string $key): AddOn
    {
        $addOn = $this->manager
            ->getRepository(AddOn::class)
            ->findOneBy(['key' => $key]);
        self::assertInstanceOf(AddOn::class, $addOn);

        return $addOn;
    }

    private function assertRejected(callable $operation): void
    {
        try {
            $operation();
            self::fail('La configuración incompatible debía fallar cerrada.');
        } catch (DomainException) {
            self::assertTrue(true);
        }
    }
}
