<?php

declare(strict_types=1);

namespace App\Tests\Application\Commercial;

use App\Application\Commercial\CommercialCatalogSeeder;
use App\Application\Commercial\EntitlementContext;
use App\Application\Commercial\EntitlementResolver;
use App\Application\Commercial\SubscriptionEntitlementContextFactory;
use App\Domain\Commercial\Entity\AddOn;
use App\Domain\Commercial\Entity\Plan;
use App\Domain\Commercial\Entity\PlanVersion;
use App\Domain\Commercial\Entity\Subscription;
use App\Domain\Commercial\Entity\SubscriptionConfiguration;
use App\Domain\Commercial\Entity\Vertical;
use App\Domain\Commercial\EntitlementOverride;
use App\Domain\Commercial\SubscriptionLifecycle;
use App\Domain\Commercial\SubscriptionState;
use DateTimeImmutable;
use Doctrine\ORM\EntityManagerInterface;
use DomainException;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

final class SubscriptionEntitlementContextFactoryTest extends KernelTestCase
{
    private EntityManagerInterface $manager;
    private SubscriptionEntitlementContextFactory $factory;
    private EntitlementResolver $resolver;
    private DateTimeImmutable $at;

    protected function setUp(): void
    {
        self::bootKernel();

        $manager = static::getContainer()->get(EntityManagerInterface::class);
        self::assertInstanceOf(EntityManagerInterface::class, $manager);
        $this->manager = $manager;

        $seeder = static::getContainer()->get(CommercialCatalogSeeder::class);
        self::assertInstanceOf(CommercialCatalogSeeder::class, $seeder);
        $seeder->seed();

        $this->factory = new SubscriptionEntitlementContextFactory(
            $manager,
        );

        $resolver = static::getContainer()->get(EntitlementResolver::class);
        self::assertInstanceOf(EntitlementResolver::class, $resolver);
        $this->resolver = $resolver;
        $this->at = new DateTimeImmutable('2026-10-05T10:00:00Z');
    }

    public function testPersistedConfigurationResolvesVerticalAddOnsAndConfiguredLimitsDeterministically(): void
    {
        $tenantId = 'tenant-entitlement-context-'.bin2hex(random_bytes(4));
        $this->configuredSubscription(
            $tenantId,
            ['users' => 12, 'locations' => 3, 'companies' => 1],
            ['production-lite'],
        );

        $context = $this->factory->forTenant($tenantId, $this->at);
        $snapshot = $this->resolver->resolve($context);

        self::assertSame('commerce', $snapshot->verticalKey());
        self::assertTrue($snapshot->addOn('production-lite'));
        self::assertSame(
            ['companies' => 1, 'locations' => 3, 'users' => 12],
            $snapshot->limits(),
        );
        self::assertSame(
            ['companies' => 1, 'locations' => 3, 'users' => 12],
            $context->configuredLimits(),
        );
        self::assertSame('business', $snapshot->planKey());
        self::assertSame($tenantId, $snapshot->tenantId());
    }

    public function testMissingCrossTenantStaleOrIncompatibleConfigurationFailsClosed(): void
    {
        $this->assertDomainFailure(
            fn () => $this->factory->forTenant(
                'tenant-missing-'.bin2hex(random_bytes(4)),
                $this->at,
            ),
        );

        $legacyTenant = 'tenant-legacy-'.bin2hex(random_bytes(4));
        $legacy = $this->subscription(
            $legacyTenant,
            $this->planVersion('business'),
        );
        $this->manager->persist($legacy);
        $this->manager->flush();
        $this->assertDomainFailure(
            fn () => $this->factory->forTenant($legacyTenant, $this->at),
        );

        $crossTenant = 'tenant-cross-'.bin2hex(random_bytes(4));
        $this->configuredSubscription(
            $crossTenant,
            ['users' => 10, 'locations' => 3, 'companies' => 1],
            [],
        );
        $foreignOverride = new EntitlementOverride(
            'tenant-other-'.bin2hex(random_bytes(4)),
            'limit',
            'users',
            15,
            'Override de otro tenant',
            'owner',
            $this->at->modify('-1 minute'),
        );
        $this->assertDomainFailure(
            fn () => $this->factory->forTenant(
                $crossTenant,
                $this->at,
                [$foreignOverride],
            ),
        );

        $inactiveTenant = 'tenant-inactive-addon-'.bin2hex(random_bytes(4));
        $this->configuredSubscription(
            $inactiveTenant,
            ['users' => 10, 'locations' => 3, 'companies' => 1],
            ['production-lite'],
        );
        $production = $this->addOn('production-lite');
        $production->deactivate();
        $inactiveContext = $this->factory->forTenant(
            $inactiveTenant,
            $this->at,
        );
        $this->assertDomainFailure(
            fn () => $this->resolver->resolve($inactiveContext),
        );
        $this->manager->clear();

        $staleTenant = 'tenant-stale-plan-'.bin2hex(random_bytes(4));
        $vertical = $this->vertical('commerce');
        $plan = new Plan(
            'stale-'.bin2hex(random_bytes(4)),
            'Stale plan',
        );
        $version = new PlanVersion(
            $plan,
            1,
            1,
            1,
            false,
            ['users' => 1],
            new DateTimeImmutable('2026-01-01T00:00:00Z'),
        );
        $version->addVertical($vertical);
        $staleSubscription = $this->subscription($staleTenant, $version);
        $staleConfiguration = new SubscriptionConfiguration(
            $staleSubscription,
            $vertical,
            ['users' => 1],
            [],
            $this->at->modify('-5 minutes'),
        );
        $this->manager->persist($plan);
        $this->manager->persist($version);
        $this->manager->persist($staleSubscription);
        $this->manager->persist($staleConfiguration);
        $this->manager->flush();

        $staleContext = $this->factory->forTenant($staleTenant, $this->at);
        $this->assertDomainFailure(
            fn () => $this->resolver->resolve($staleContext),
        );
    }

    public function testConfiguredLimitsApplyBeforeExplicitOverridesWithoutRbacOrLifecyclePolicy(): void
    {
        $tenantId = 'tenant-entitlement-override-'.bin2hex(random_bytes(4));
        $this->configuredSubscription(
            $tenantId,
            ['users' => 12, 'locations' => 3, 'companies' => 1],
            ['production-lite'],
        );
        $override = new EntitlementOverride(
            $tenantId,
            'limit',
            'users',
            15,
            'Ajuste explícito posterior',
            'owner',
            $this->at->modify('-1 minute'),
        );

        $context = $this->factory->forTenant(
            $tenantId,
            $this->at,
            [$override],
        );
        $snapshot = $this->resolver->resolve($context);

        self::assertSame(12, $context->configuredLimits()['users']);
        self::assertSame(15, $snapshot->limit('users'));
        self::assertCount(1, $snapshot->overrideProvenance());

        $this->assertDomainFailure(
            fn () => new EntitlementContext(
                $tenantId,
                $this->planVersion('business'),
                $this->vertical('commerce'),
                [],
                [],
                $this->at,
                ['users' => 9],
            ),
        );
        $this->assertDomainFailure(
            fn () => new EntitlementContext(
                $tenantId,
                $this->planVersion('business'),
                $this->vertical('commerce'),
                [],
                [],
                $this->at,
                ['unknown' => 12],
            ),
        );

        $source = file_get_contents(
            dirname(__DIR__, 4)
            .'/src/Application/Commercial/SubscriptionEntitlementContextFactory.php',
        );
        self::assertIsString($source);
        self::assertStringNotContainsString('PermissionCatalog', $source);
        self::assertStringNotContainsString('TenantContext', $source);
        self::assertStringNotContainsString('->state()', $source);
        self::assertStringNotContainsString('SubscriptionState::', $source);
    }

    /**
     * @param array<string,int> $quantities
     * @param list<string> $addOnKeys
     */
    private function configuredSubscription(
        string $tenantId,
        array $quantities,
        array $addOnKeys,
    ): Subscription {
        $subscription = $this->subscription(
            $tenantId,
            $this->planVersion('business'),
        );
        $addOns = array_map(
            fn (string $key): AddOn => $this->addOn($key),
            $addOnKeys,
        );
        $configuration = new SubscriptionConfiguration(
            $subscription,
            $this->vertical('commerce'),
            $quantities,
            $addOns,
            $this->at->modify('-5 minutes'),
        );
        $this->manager->persist($subscription);
        $this->manager->persist($configuration);
        $this->manager->flush();

        return $subscription;
    }

    private function subscription(
        string $tenantId,
        PlanVersion $planVersion,
    ): Subscription {
        $lifecycle = new SubscriptionLifecycle(
            $tenantId,
            $planVersion,
            SubscriptionState::Active,
            $this->at->modify('-10 minutes'),
        );

        return Subscription::fromLifecycle(
            $lifecycle,
            $this->at->modify('-9 minutes'),
        );
    }

    private function planVersion(string $key): PlanVersion
    {
        $plan = $this->catalogEntity(Plan::class, $key);
        $matches = $this->manager
            ->getRepository(PlanVersion::class)
            ->findBy(['plan' => $plan, 'version' => 1], limit: 1);
        $version = $matches[0] ?? null;
        if (!$version instanceof PlanVersion) {
            self::fail('PlanVersion canónica no encontrada.');
        }

        return $version;
    }

    private function vertical(string $key): Vertical
    {
        return $this->catalogEntity(Vertical::class, $key);
    }

    private function addOn(string $key): AddOn
    {
        return $this->catalogEntity(AddOn::class, $key);
    }

    /**
     * @template T of Plan|Vertical|AddOn
     * @param class-string<T> $class
     * @return T
     */
    private function catalogEntity(string $class, string $key): object
    {
        $entity = $this->manager
            ->getRepository($class)
            ->findOneBy(['key' => $key]);
        if (!$entity instanceof $class) {
            self::fail('Entidad comercial canónica no encontrada.');
        }

        return $entity;
    }

    private function assertDomainFailure(callable $operation): void
    {
        try {
            $operation();
            self::fail('La operación debía fallar cerrado.');
        } catch (DomainException) {
            self::assertTrue(true);
        }
    }
}
