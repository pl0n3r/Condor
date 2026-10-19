<?php

declare(strict_types=1);

namespace App\Tests\Http;

use App\Application\Commercial\EntitlementResolver;
use App\Application\Commercial\SubscriptionEntitlementContextFactory;
use App\Domain\Commercial\Entity\AddOn;
use App\Domain\Commercial\Entity\Capability;
use App\Domain\Commercial\Entity\Plan;
use App\Domain\Commercial\Entity\PlanVersion;
use App\Domain\Commercial\Entity\Subscription;
use App\Domain\Commercial\Entity\SubscriptionConfiguration;
use App\Domain\Commercial\Entity\UsageObservation;
use App\Domain\Commercial\Entity\Vertical;
use App\Domain\Commercial\Entity\VerticalCapability;
use App\Domain\Commercial\EntitlementOverride;
use App\Domain\Commercial\SubscriptionLifecycle;
use App\Domain\Commercial\SubscriptionState;
use App\Domain\Commercial\UsageMetric;
use App\Domain\Commercial\UsageRecord;
use App\Domain\Identity\Entity\User;
use App\Domain\Organization\Entity\Tenant;
use DateTimeImmutable;
use DateTimeZone;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/** Real Symfony HTTP and Doctrine fixtures; never mocks platform authorization. */
final class SaasControlCenterReadControllerTest extends WebTestCase
{
    public function testOwnerGetUsesSelectedAddOnsEffectiveLimitsAndCurrentWindow(): void
    {
        $client = static::createClient();
        $em = $this->entityManager();
        $now = new DateTimeImmutable('now', new DateTimeZone('UTC'));
        $owner = new User('saas-owner@example.test', 'SaaS Owner', [User::ROLE_PLATFORM_OWNER]);
        $tenant = new Tenant('Tenant SaaS integration', 'saas-integration');
        $plan = new Plan('saas-integrated', 'SaaS Integrated');
        $vertical = new Vertical('vertical-test', 'Vertical test');
        $capability = new Capability('analytics', 'Analytics');
        $addOn = new AddOn('exports', 'Exports', 1500);
        $version = new PlanVersion(
            $plan, 1, 24000, null, false, ['users' => 10], $now->modify('-10 days')
        );
        $version->addVertical($vertical);
        $version->addCapability($capability);
        $version->addAddOn($addOn);
        $relation = new VerticalCapability($vertical, $capability, 1);
        $subscription = Subscription::fromLifecycle(
            new SubscriptionLifecycle(
                $tenant->id(), $version, SubscriptionState::Active, $now->modify('-2 days')
            ),
            $now->modify('-1 day'),
        );
        // The optional add-on is in the catalog but NOT selected.
        // The configured limit differs from the advertised plan baseline.
        $configuration = new SubscriptionConfiguration(
            $subscription, $vertical, ['users' => 12], [], $now->modify('-1 day')
        );
        $active = UsageObservation::fromRecord(new UsageRecord(
            $tenant->id(),
            UsageMetric::Users,
            4,
            $now->modify('-1 day'),
            $now->modify('+1 day'),
            $now->modify('-1 hour'),
        ));
        $historic = UsageObservation::fromRecord(new UsageRecord(
            $tenant->id(),
            UsageMetric::Users,
            99,
            $now->modify('-10 days'),
            $now->modify('-9 days'),
            $now->modify('-9 days -1 hour'),
        ));
        foreach ([
            $owner, $tenant, $plan, $vertical, $capability, $addOn,
            $version, $relation, $subscription, $configuration, $active, $historic,
        ] as $entity) {
            $em->persist($entity);
        }
        $em->flush();

        $client->loginUser($owner);
        $client->request(
            'GET',
            '/adminpl0n3r/api/tenants/'.$tenant->id().'/saas-control-center'
        );
        self::assertResponseIsSuccessful();
        self::assertStringContainsString(
            'no-store', (string) $client->getResponse()->headers->get('Cache-Control')
        );
        $payload = json_decode(
            (string) $client->getResponse()->getContent(), true, 512, JSON_THROW_ON_ERROR
        );
        self::assertSame('ready', $payload['state']);
        self::assertSame($tenant->id(), $payload['tenant_id']);
        self::assertSame('saas-integrated', $payload['plan']['key']);
        self::assertSame(2400000, $payload['plan']['price_minor']);
        self::assertSame(
            [['key' => 'exports', 'price_minor' => 150000, 'active' => false]],
            $payload['add_ons'],
        );
        self::assertSame(
            [['metric' => 'users', 'used' => 4, 'limit' => 12]], $payload['usage']
        );
        self::assertSame(
            [['key' => 'analytics', 'allowed' => true]], $payload['entitlements']
        );

        // Canonical resolver, not the plan catalog, is the rights authority.
        $factory = static::getContainer()->get(SubscriptionEntitlementContextFactory::class);
        $resolver = static::getContainer()->get(EntitlementResolver::class);
        $override = new EntitlementOverride(
            $tenant->id(), 'capability', 'analytics', false,
            'Synthetic revocation', 'test', $now
        );
        $revoked = $resolver->resolve($factory->forTenant($tenant->id(), $now, [$override]));
        self::assertFalse($revoked->capability('analytics'));
        self::assertFalse($revoked->addOn('exports'));
        self::assertSame(12, $revoked->limit('users'));

        // Regression: a previously successful GET is not permission to reuse
        // commercial rights once the SAME persisted subscription is cancelled.
        // Exercise real Doctrine lifecycle persistence and another HTTP GET.
        $lifecycle = $subscription->toLifecycle();
        $lifecycle->transitionTo(SubscriptionState::Cancelled, $now->modify('-30 minutes'));
        $subscription->syncFromLifecycle($lifecycle, $now->modify('-15 minutes'));
        $em->flush();

        $client->request(
            'GET',
            '/adminpl0n3r/api/tenants/'.$tenant->id().'/saas-control-center'
        );
        self::assertResponseStatusCodeSame(503);
        self::assertStringContainsString(
            'no-store', (string) $client->getResponse()->headers->get('Cache-Control')
        );
        $raw = (string) $client->getResponse()->getContent();
        self::assertStringContainsString('Datos comerciales no disponibles.', $raw);
        self::assertStringNotContainsString($tenant->id(), $raw);
        self::assertStringNotContainsString('saas-integrated', $raw);
        self::assertStringNotContainsString('"allowed":true', $raw);
    }

    public function testInactiveSubscriptionDoesNotExposeCommercialRights(): void
    {
        $client = static::createClient();
        $em = $this->entityManager();
        $now = new DateTimeImmutable('now', new DateTimeZone('UTC'));
        $owner = new User('saas-inactive-owner@example.test', 'Owner', [User::ROLE_PLATFORM_OWNER]);
        $plan = new Plan('saas-inactive-plan', 'Inactive plan');
        $vertical = new Vertical('saas-inactive-vertical', 'Inactive vertical');
        $capability = new Capability('saas-inactive-analytics', 'Inactive analytics');
        $version = new PlanVersion(
            $plan, 1, 24000, null, false, ['users' => 10], $now->modify('-10 days')
        );
        $version->addVertical($vertical);
        $version->addCapability($capability);
        $relation = new VerticalCapability($vertical, $capability, 1);
        foreach ([$owner, $plan, $vertical, $capability, $version, $relation] as $entity) {
            $em->persist($entity);
        }

        $tenants = [];
        foreach ([SubscriptionState::Suspended, SubscriptionState::Cancelled] as $state) {
            $tenant = new Tenant('Inactive tenant '.$state->value, 'saas-inactive-'.$state->value);
            $subscription = Subscription::fromLifecycle(
                new SubscriptionLifecycle(
                    $tenant->id(), $version, $state, $now->modify('-2 days')
                ),
                $now->modify('-1 day'),
            );
            $configuration = new SubscriptionConfiguration(
                $subscription, $vertical, ['users' => 10], [], $now->modify('-1 day')
            );
            foreach ([$tenant, $subscription, $configuration] as $entity) {
                $em->persist($entity);
            }
            $tenants[] = $tenant;
        }
        $em->flush();

        $client->loginUser($owner);
        foreach ($tenants as $tenant) {
            $client->request(
                'GET', '/adminpl0n3r/api/tenants/'.$tenant->id().'/saas-control-center'
            );
            self::assertResponseStatusCodeSame(503);
            self::assertStringContainsString(
                'no-store', (string) $client->getResponse()->headers->get('Cache-Control')
            );
            $raw = (string) $client->getResponse()->getContent();
            self::assertStringContainsString('Datos comerciales no disponibles.', $raw);
            self::assertStringNotContainsString($tenant->id(), $raw);
            self::assertStringNotContainsString('saas-inactive-analytics', $raw);
            self::assertStringNotContainsString('"allowed":true', $raw);
        }
    }

    public function testTenantWithoutSubscriptionRemainsUnknownNotKnownZero(): void
    {
        $client = static::createClient();
        $em = $this->entityManager();
        $owner = new User('saas-empty-owner@example.test', 'Owner', [User::ROLE_PLATFORM_OWNER]);
        $tenant = new Tenant('Tenant without commercial data', 'saas-no-subscription');
        $em->persist($owner);
        $em->persist($tenant);
        $em->flush();

        $client->loginUser($owner);
        $client->request(
            'GET', '/adminpl0n3r/api/tenants/'.$tenant->id().'/saas-control-center'
        );
        self::assertResponseIsSuccessful();
        $payload = json_decode(
            (string) $client->getResponse()->getContent(), true, 512, JSON_THROW_ON_ERROR
        );
        self::assertSame('empty', $payload['state']);
        self::assertStringContainsString(
            'no-store', (string) $client->getResponse()->headers->get('Cache-Control')
        );
        self::assertSame($tenant->id(), $payload['tenant_id']);
        foreach (['plan', 'add_ons', 'usage', 'entitlements'] as $field) {
            self::assertArrayHasKey($field, $payload);
            self::assertNull($payload[$field], $field.' not observed, not known zero');
        }
    }

    public function testNonOwnerAndUnknownTenantFailClosed(): void
    {
        $client = static::createClient();
        $em = $this->entityManager();
        $now = new DateTimeImmutable('now', new DateTimeZone('UTC'));
        $user = new User('saas-reader@example.test', 'Reader');
        $owner = new User('saas-supervisor@example.test', 'Owner', [User::ROLE_PLATFORM_OWNER]);
        $protectedTenant = new Tenant('Protected SaaS tenant', 'saas-protected');
        $emptyTenant = new Tenant('Empty SaaS tenant', 'saas-empty-scope');
        $plan = new Plan('saas-protected-plan', 'Protected Plan');
        $version = new PlanVersion(
            $plan, 1, 24000, null, false, ['users' => 10], $now->modify('-2 days')
        );
        $subscription = Subscription::fromLifecycle(
            new SubscriptionLifecycle(
                $protectedTenant->id(), $version, SubscriptionState::Active,
                $now->modify('-1 day'),
            ),
            $now,
        );
        foreach ([
            $user, $owner, $protectedTenant, $emptyTenant,
            $plan, $version, $subscription,
        ] as $entity) {
            $em->persist($entity);
        }
        $em->flush();

        // A non-owner cannot read an existing tenant with commercial data.
        $client->loginUser($user);
        $client->request(
            'GET', '/adminpl0n3r/api/tenants/'.$protectedTenant->id().'/saas-control-center'
        );
        self::assertResponseStatusCodeSame(403);
        self::assertStringNotContainsString(
            'saas-protected-plan', (string) $client->getResponse()->getContent()
        );

        $client->loginUser($owner);
        $client->request('GET', '/adminpl0n3r/api/tenants/missing/saas-control-center');
        self::assertResponseStatusCodeSame(404);

        // A different tenant must not inherit the stored subscription or its plan.
        $client->request(
            'GET', '/adminpl0n3r/api/tenants/'.$emptyTenant->id().'/saas-control-center'
        );
        self::assertResponseIsSuccessful();
        $payload = json_decode(
            (string) $client->getResponse()->getContent(), true, 512, JSON_THROW_ON_ERROR
        );
        self::assertSame('empty', $payload['state']);
        self::assertSame($emptyTenant->id(), $payload['tenant_id']);
        foreach (['plan', 'add_ons', 'usage', 'entitlements'] as $field) {
            self::assertArrayHasKey($field, $payload);
            self::assertNull($payload[$field], $field.' must remain unobserved');
        }
    }

    public function testUnresolvedCommercialConfigurationIsNotReportedAsAllowed(): void
    {
        $client = static::createClient();
        $em = $this->entityManager();
        $now = new DateTimeImmutable('now', new DateTimeZone('UTC'));
        $owner = new User('saas-unresolved-owner@example.test', 'Owner', [User::ROLE_PLATFORM_OWNER]);
        $tenant = new Tenant('Tenant unresolved', 'saas-unresolved');
        $plan = new Plan('plan-unresolved', 'Plan unresolved');
        $version = new PlanVersion(
            $plan, 1, 24000, null, false, ['users' => 10], $now->modify('-1 day')
        );
        $subscription = Subscription::fromLifecycle(
            new SubscriptionLifecycle(
                $tenant->id(), $version, SubscriptionState::Active, $now->modify('-2 hours')
            ),
            $now->modify('-1 hour'),
        );
        // No SubscriptionConfiguration exists: do not infer full access from PlanVersion.
        foreach ([$owner, $tenant, $plan, $version, $subscription] as $entity) {
            $em->persist($entity);
        }
        $em->flush();
        $client->loginUser($owner);
        $client->request(
            'GET', '/adminpl0n3r/api/tenants/'.$tenant->id().'/saas-control-center'
        );
        self::assertResponseStatusCodeSame(503);
        $raw = (string) $client->getResponse()->getContent();
        self::assertStringContainsString('Datos comerciales no disponibles.', $raw);
        self::assertStringNotContainsString($tenant->id(), $raw);
    }
    private function entityManager(): EntityManagerInterface
    {
        $manager = static::getContainer()->get(EntityManagerInterface::class);
        self::assertInstanceOf(EntityManagerInterface::class, $manager);

        return $manager;
    }

}
