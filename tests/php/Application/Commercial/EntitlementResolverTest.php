<?php

declare(strict_types=1);

namespace App\Tests\Application\Commercial;

use App\Application\Commercial\CommercialCatalogReader;
use App\Application\Commercial\CommercialCatalogSeeder;
use App\Application\Commercial\EntitlementContext;
use App\Application\Commercial\EntitlementResolver;
use App\Application\Commercial\PlanConfiguratorCatalogReader;
use App\Domain\Commercial\Entity\AddOn;
use App\Domain\Commercial\Entity\Plan;
use App\Domain\Commercial\Entity\PlanVersion;
use App\Domain\Commercial\Entity\Vertical;
use App\Domain\Commercial\EntitlementOverride;
use App\Domain\Commercial\PlanVersionTimeline;
use DateTimeImmutable;
use Doctrine\ORM\EntityManagerInterface;
use DomainException;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

final class EntitlementResolverTest extends KernelTestCase
{
    private EntityManagerInterface $manager;
    private EntitlementResolver $resolver;
    private DateTimeImmutable $at;

    protected function setUp(): void
    {
        self::bootKernel();
        $manager = static::getContainer()->get(EntityManagerInterface::class);
        self::assertInstanceOf(EntityManagerInterface::class, $manager);
        $this->manager = $manager;
        (new CommercialCatalogSeeder($manager))->seed();
        $catalog = new CommercialCatalogReader($manager, new PlanVersionTimeline());
        $this->resolver = new EntitlementResolver(
            new PlanConfiguratorCatalogReader($catalog, $manager),
            $manager,
        );
        $this->at = new DateTimeImmutable('2026-09-30T10:00:00Z');
    }

    public function testResolvesEffectiveCapabilitiesAddOnsAndLimitsDeterministically(): void
    {
        $productionLite = $this->addOn('production-lite');
        $snapshot = $this->resolver->resolve($this->context(
            'tenant-a', 'business', 'commerce', [$productionLite],
            [new EntitlementOverride(
                'tenant-a', 'limit', 'users', 15, 'Upgrade temporal', 'owner',
                new DateTimeImmutable('2026-09-30T09:00:00Z'),
            )],
        ));

        self::assertTrue($snapshot->capability('inventory'));
        self::assertFalse($snapshot->capability('legal-cases'));
        self::assertTrue($snapshot->addOn('production-lite'));
        self::assertFalse($snapshot->addOn('premium-integration'));
        self::assertSame(['companies'=>1,'locations'=>3,'users'=>15], $snapshot->limits());
        self::assertSame(10, $this->planVersion('business')->limits()['users']);
        self::assertSame('tenant-a', $snapshot->tenantId());
    }

    public function testKeepsCommercialEntitlementsSeparateFromRbacAndTenantContext(): void
    {
        $source = file_get_contents(__DIR__.'/../../../../src/Application/Commercial/EntitlementResolver.php');
        self::assertIsString($source);
        self::assertStringNotContainsString('PermissionCatalog', $source);
        self::assertStringNotContainsString('TenantContext', $source);

        $legal = $this->resolver->resolve($this->context('tenant-a', 'pro', 'legal'));
        self::assertFalse($legal->capability('inventory'));
        self::assertTrue($legal->capability('legal-cases'));
    }

    public function testFailsClosedForUnknownInactiveOrIncompatibleCommercialState(): void
    {
        $this->assertDomainFailure(fn () => $this->resolver->resolve(
            $this->context('tenant-a', 'pro', 'legal', [$this->addOn('production-lite')]),
        ));

        $snapshot = $this->resolver->resolve($this->context('tenant-a', 'business', 'commerce'));
        $this->assertDomainFailure(fn () => $snapshot->capability('unknown-capability'));

        $inactive = $this->addOn('production-lite');
        $inactive->deactivate();
        $this->assertDomainFailure(fn () => $this->resolver->resolve(
            $this->context('tenant-a', 'business', 'commerce', [$inactive]),
        ));
    }

    public function testRejectsInactiveAddOnAndStalePlanVersion(): void
    {
        $addOn = $this->addOn('production-lite');
        $addOn->deactivate();
        try {
            $this->resolver->resolve($this->context('tenant-a', 'business', 'commerce', [$addOn]));
            self::fail('Un add-on inactivo no puede resolverse.');
        } catch (DomainException) {
            self::assertTrue(true);
        }

        $stale = new PlanVersion(
            new Plan('business', 'Negocio stale'), 99, 1, 1, false,
            ['users'=>10], new DateTimeImmutable('2026-01-01T00:00:00Z'),
        );
        $this->expectException(DomainException::class);
        $this->resolver->resolve(new EntitlementContext(
            'tenant-a', $stale, $this->vertical('commerce'), [], [], $this->at,
        ));
    }

    public function testCapabilityOverrideMustRemainCanonicalAndVerticalCompatible(): void
    {
        $valid = new EntitlementOverride(
            'tenant-a', 'capability', 'advanced-analytics', true,
            'Grant comercial', 'owner', new DateTimeImmutable('2026-09-30T09:00:00Z'),
        );
        $snapshot = $this->resolver->resolve($this->context(
            'tenant-a', 'business', 'commerce', [], [$valid],
        ));
        self::assertTrue($snapshot->capability('advanced-analytics'));

        $invalid = new EntitlementOverride(
            'tenant-a', 'capability', 'manufacturing', true,
            'Grant incompatible', 'owner', new DateTimeImmutable('2026-09-30T09:01:00Z'),
        );
        $this->expectException(DomainException::class);
        $this->resolver->resolve($this->context(
            'tenant-a', 'business', 'legal', [], [$invalid],
        ));
    }

    public function testBaseSecurityPrivacyBackupRecoveryAndIntegrityAreNotEntitlements(): void
    {
        $snapshot = $this->resolver->resolve($this->context('tenant-a', 'business', 'commerce'));
        $serialized = json_encode([
            array_keys($snapshot->capabilities()),
            array_keys($snapshot->addOns()),
            array_keys($snapshot->limits()),
        ], JSON_THROW_ON_ERROR);
        foreach (['security','privacy','backup','recovery','integrity'] as $key) {
            self::assertStringNotContainsString('"'.$key.'"', $serialized);
        }
    }

    public function testIsolatesTenantsAndReusesCanonicalCommercialCatalog(): void
    {
        $override = new EntitlementOverride(
            'tenant-a', 'limit', 'users', 15, 'Tenant A', 'owner',
            new DateTimeImmutable('2026-09-30T09:00:00Z'),
        );
        $a = $this->resolver->resolve($this->context(
            'tenant-a', 'business', 'commerce', [], [$override],
        ));
        $b = $this->resolver->resolve($this->context('tenant-b', 'business', 'commerce'));

        self::assertSame(15, $a->limit('users'));
        self::assertSame(10, $b->limit('users'));

        $this->expectException(DomainException::class);
        $this->resolver->resolve($this->context(
            'tenant-b', 'business', 'commerce', [], [$override],
        ));
    }

    public function testOverrideOrderIsDeterministicAndTimestampTiesFailClosed(): void
    {
        $early = new EntitlementOverride(
            'tenant-a', 'limit', 'users', 12, 'Primero', 'owner',
            new DateTimeImmutable('2026-09-30T08:00:00Z'),
        );
        $late = new EntitlementOverride(
            'tenant-a', 'limit', 'users', 15, 'Después', 'owner',
            new DateTimeImmutable('2026-09-30T09:00:00Z'),
        );
        self::assertSame(15, $this->resolver->resolve($this->context(
            'tenant-a', 'business', 'commerce', [], [$late, $early],
        ))->limit('users'));

        $tie = new EntitlementOverride(
            'tenant-a', 'limit', 'users', 20, 'Empate', 'owner',
            new DateTimeImmutable('2026-09-30T09:00:00Z'),
        );
        $this->expectException(DomainException::class);
        $this->resolver->resolve($this->context(
            'tenant-a', 'business', 'commerce', [], [$late, $tie],
        ));
    }

    /**
     * @param list<AddOn> $addOns
     * @param list<EntitlementOverride> $overrides
     */
    private function context(
        string $tenant,
        string $plan,
        string $vertical,
        array $addOns = [],
        array $overrides = [],
    ): EntitlementContext {
        return new EntitlementContext(
            $tenant,
            $this->planVersion($plan),
            $this->vertical($vertical),
            $addOns,
            $overrides,
            $this->at,
        );
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

    private function planVersion(string $key): PlanVersion
    {
        $plan = $this->manager->getRepository(Plan::class)->findOneBy(['key'=>$key]);
        self::assertInstanceOf(Plan::class, $plan);
        $version = $this->manager->getRepository(PlanVersion::class)
            ->findOneBy(['plan'=>$plan, 'version'=>1]);
        self::assertInstanceOf(PlanVersion::class, $version);
        return $version;
    }

    private function vertical(string $key): Vertical
    {
        $vertical = $this->manager->getRepository(Vertical::class)->findOneBy(['key'=>$key]);
        self::assertInstanceOf(Vertical::class, $vertical);
        return $vertical;
    }

    private function addOn(string $key): AddOn
    {
        $addOn = $this->manager->getRepository(AddOn::class)->findOneBy(['key'=>$key]);
        self::assertInstanceOf(AddOn::class, $addOn);
        return $addOn;
    }
}
