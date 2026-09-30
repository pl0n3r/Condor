<?php

declare(strict_types=1);

namespace App\Tests\Http;

use App\Domain\Commercial\Entity\Plan;
use App\Domain\Commercial\Entity\PlanVersion;
use App\Domain\Commercial\Entity\Subscription;
use App\Domain\Commercial\SubscriptionLifecycle;
use App\Domain\Commercial\SubscriptionState;
use App\Domain\Identity\Entity\User;
use App\Domain\Organization\Entity\Tenant;
use DateTimeImmutable;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

final class PlatformOwnerCommercialSubscriptionTest extends WebTestCase
{
    public function testOwnerReadsPersistedSubscriptionSummary(): void
    {
        $client = static::createClient();
        $manager = $this->entityManager();
        $suffix = bin2hex(random_bytes(4));
        $owner = $this->owner($suffix);
        $tenant = $this->tenant($suffix);
        $subscription = $this->subscription($tenant, $suffix);

        foreach ([$owner, $tenant, $subscription['plan'], $subscription['plan_version'], $subscription['entity']] as $entity) {
            $manager->persist($entity);
        }
        $manager->flush();

        $client->loginUser($owner);
        $client->request(
            'GET',
            '/adminpl0n3r/api/context?tenant='.urlencode($tenant->id()),
        );
        self::assertResponseIsSuccessful();

        $payload = $this->payload($client->getResponse()->getContent());
        $commercial = $payload['selected_tenant']['commercial_subscription'];

        self::assertSame('configured', $commercial['status']);
        self::assertSame('active', $commercial['subscription']['state']);
        self::assertSame('negocio-'.$suffix, $commercial['subscription']['plan']['key']);
        self::assertSame('Negocio '.$suffix, $commercial['subscription']['plan']['name']);
        self::assertSame(3, $commercial['subscription']['plan']['version']);
        self::assertSame('COP', $commercial['subscription']['plan']['currency']);
        self::assertSame(199900, $commercial['subscription']['plan']['monthly_amount']);
        self::assertSame(1999000, $commercial['subscription']['plan']['annual_amount']);
        self::assertFalse($commercial['subscription']['plan']['quote_required']);
        self::assertSame(
            '2026-09-30T12:34:56.123456Z',
            $commercial['subscription']['last_changed_at'],
        );
    }

    public function testMissingSubscriptionIsExplicit(): void
    {
        $client = static::createClient();
        $manager = $this->entityManager();
        $suffix = bin2hex(random_bytes(4));
        $owner = $this->owner('missing-'.$suffix);
        $tenant = $this->tenant('missing-'.$suffix);
        $manager->persist($owner);
        $manager->persist($tenant);
        $manager->flush();

        $client->loginUser($owner);
        $client->request(
            'GET',
            '/adminpl0n3r/api/context?tenant='.urlencode($tenant->id()),
        );
        self::assertResponseIsSuccessful();

        $commercial = $this->payload(
            $client->getResponse()->getContent(),
        )['selected_tenant']['commercial_subscription'];

        self::assertSame(
            ['status' => 'not_configured', 'subscription' => null],
            $commercial,
        );
    }

    public function testTenantScopeAndUnknownTenant(): void
    {
        $client = static::createClient();
        $manager = $this->entityManager();
        $suffix = bin2hex(random_bytes(4));
        $owner = $this->owner('scope-'.$suffix);
        $first = $this->tenant('scope-a-'.$suffix);
        $second = $this->tenant('scope-b-'.$suffix);
        $subscription = $this->subscription($second, 'scope-'.$suffix);

        foreach ([$owner, $first, $second, $subscription['plan'], $subscription['plan_version'], $subscription['entity']] as $entity) {
            $manager->persist($entity);
        }
        $manager->flush();

        $client->loginUser($owner);
        $client->request(
            'GET',
            '/adminpl0n3r/api/context?tenant='.urlencode($first->id()),
        );
        self::assertResponseIsSuccessful();
        $firstPayload = $this->payload($client->getResponse()->getContent());
        self::assertSame(
            'not_configured',
            $firstPayload['selected_tenant']['commercial_subscription']['status'],
        );

        $client->request(
            'GET',
            '/adminpl0n3r/api/context?tenant=00000000000000000000000000',
        );
        self::assertResponseStatusCodeSame(404);
    }

    public function testOwnerOnlyAndReadOnly(): void
    {
        $client = static::createClient();
        $manager = $this->entityManager();
        $suffix = bin2hex(random_bytes(4));
        $tenantUser = new User(
            'tenant-commercial-'.$suffix.'@example.test',
            'Usuario tenant',
        );
        $owner = $this->owner('readonly-'.$suffix);
        $tenant = $this->tenant('readonly-'.$suffix);

        foreach ([$tenantUser, $owner, $tenant] as $entity) {
            $manager->persist($entity);
        }
        $manager->flush();

        $client->loginUser($tenantUser);
        $client->request(
            'GET',
            '/adminpl0n3r/api/context?tenant='.urlencode($tenant->id()),
        );
        self::assertResponseStatusCodeSame(403);

        $client->loginUser($owner);
        $client->request('POST', '/adminpl0n3r/api/context');
        self::assertResponseStatusCodeSame(405);
    }

    public function testPayloadBoundaryOmitsCommercialInternals(): void
    {
        $client = static::createClient();
        $manager = $this->entityManager();
        $suffix = bin2hex(random_bytes(4));
        $owner = $this->owner('boundary-'.$suffix);
        $tenant = $this->tenant('boundary-'.$suffix);
        $subscription = $this->subscription($tenant, 'boundary-'.$suffix);

        foreach ([$owner, $tenant, $subscription['plan'], $subscription['plan_version'], $subscription['entity']] as $entity) {
            $manager->persist($entity);
        }
        $manager->flush();

        $client->loginUser($owner);
        $client->request(
            'GET',
            '/adminpl0n3r/api/context?tenant='.urlencode($tenant->id()),
        );
        self::assertResponseIsSuccessful();

        $commercial = $this->payload(
            $client->getResponse()->getContent(),
        )['selected_tenant']['commercial_subscription'];
        self::assertSame(['status', 'subscription'], array_keys($commercial));
        self::assertSame(
            ['state', 'plan', 'last_changed_at'],
            array_keys($commercial['subscription']),
        );
        self::assertSame(
            [
                'key',
                'name',
                'version',
                'currency',
                'monthly_amount',
                'annual_amount',
                'quote_required',
            ],
            array_keys($commercial['subscription']['plan']),
        );

        $encoded = strtolower(json_encode($commercial, JSON_THROW_ON_ERROR));
        foreach ([
            'history',
            'lock_version',
            'usage',
            'billing',
            'add_on',
            'override',
            'email',
            'phone',
            'password',
            'secret',
        ] as $forbidden) {
            self::assertStringNotContainsString($forbidden, $encoded);
        }
    }

    private function entityManager(): EntityManagerInterface
    {
        $manager = static::getContainer()->get(EntityManagerInterface::class);
        self::assertInstanceOf(EntityManagerInterface::class, $manager);

        return $manager;
    }

    private function owner(string $suffix): User
    {
        return new User(
            'owner-commercial-'.$suffix.'@example.test',
            'Propietario comercial',
            [User::ROLE_PLATFORM_OWNER],
        );
    }

    private function tenant(string $suffix): Tenant
    {
        return new Tenant(
            'Empresa comercial '.$suffix,
            'empresa-comercial-'.$suffix,
        );
    }

    /**
     * @return array{
     *   plan: Plan,
     *   plan_version: PlanVersion,
     *   entity: Subscription
     * }
     */
    private function subscription(Tenant $tenant, string $suffix): array
    {
        $plan = new Plan('negocio-'.$suffix, 'Negocio '.$suffix);
        $planVersion = new PlanVersion(
            $plan,
            3,
            199900,
            1999000,
            false,
            ['users' => 10],
            new DateTimeImmutable('2026-01-01T00:00:00Z'),
        );
        $lifecycle = new SubscriptionLifecycle(
            $tenant->id(),
            $planVersion,
            SubscriptionState::Active,
            new DateTimeImmutable('2026-09-30T12:34:56.123456Z'),
        );

        return [
            'plan' => $plan,
            'plan_version' => $planVersion,
            'entity' => Subscription::fromLifecycle(
                $lifecycle,
                new DateTimeImmutable('2026-09-30T12:35:00.000001Z'),
            ),
        ];
    }

    /** @return array<string, mixed> */
    private function payload(string|false $content): array
    {
        self::assertIsString($content);
        $payload = json_decode($content, true, 512, JSON_THROW_ON_ERROR);
        self::assertIsArray($payload);

        return $payload;
    }
}
