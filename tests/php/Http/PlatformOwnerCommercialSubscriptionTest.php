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
        [$owner, $tenant] = $this->fixture('configured', true);
        $client->loginUser($owner);
        $commercial = $this->commercial($client, $tenant);

        self::assertSame('configured', $commercial['status']);
        self::assertSame('active', $commercial['subscription']['state']);
        self::assertSame('negocio-configured', $commercial['subscription']['plan']['key']);
        self::assertSame('Negocio configured', $commercial['subscription']['plan']['name']);
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
        [$owner, $tenant] = $this->fixture('missing');
        $client->loginUser($owner);

        self::assertSame(
            ['status' => 'not_configured', 'subscription' => null],
            $this->commercial($client, $tenant),
        );
    }

    public function testTenantScopeAndUnknownTenant(): void
    {
        $client = static::createClient();
        [$owner, $first] = $this->fixture('scope-a');
        $manager = $this->entityManager();
        $second = new Tenant('Empresa B', 'empresa-scope-b');
        $manager->persist($second);
        $this->persistSubscription($manager, $second, 'scope-b');
        $manager->flush();

        $client->loginUser($owner);
        self::assertSame('not_configured', $this->commercial($client, $first)['status']);

        $client->request(
            'GET',
            '/adminpl0n3r/api/context?tenant=00000000000000000000000000',
        );
        self::assertResponseStatusCodeSame(404);
    }

    public function testOwnerOnlyAndReadOnly(): void
    {
        $client = static::createClient();
        [$owner, $tenant] = $this->fixture('readonly');
        $manager = $this->entityManager();
        $tenantUser = new User('tenant-readonly@example.test', 'Usuario tenant');
        $manager->persist($tenantUser);
        $manager->flush();

        $client->loginUser($tenantUser);
        $client->request('GET', $this->path($tenant));
        self::assertResponseStatusCodeSame(403);

        $client->loginUser($owner);
        $client->request('POST', '/adminpl0n3r/api/context');
        self::assertResponseStatusCodeSame(405);
    }

    public function testPayloadBoundaryOmitsCommercialInternals(): void
    {
        $client = static::createClient();
        [$owner, $tenant] = $this->fixture('boundary', true);
        $client->loginUser($owner);
        $commercial = $this->commercial($client, $tenant);

        self::assertSame(['status', 'subscription'], array_keys($commercial));
        self::assertSame(
            ['state', 'plan', 'last_changed_at'],
            array_keys($commercial['subscription']),
        );
        self::assertSame(
            ['key', 'name', 'version', 'currency', 'monthly_amount', 'annual_amount', 'quote_required'],
            array_keys($commercial['subscription']['plan']),
        );

        $encoded = strtolower(json_encode($commercial, JSON_THROW_ON_ERROR));
        foreach (['history', 'lock_version', 'usage', 'billing', 'add_on', 'override', 'email', 'phone', 'password', 'secret'] as $forbidden) {
            self::assertStringNotContainsString($forbidden, $encoded);
        }
    }

    /** @return array{User,Tenant} */
    private function fixture(string $suffix, bool $subscribed = false): array
    {
        $manager = $this->entityManager();
        $owner = new User(
            'owner-'.$suffix.'@example.test',
            'Propietario comercial',
            [User::ROLE_PLATFORM_OWNER],
        );
        $tenant = new Tenant('Empresa '.$suffix, 'empresa-'.$suffix);
        $manager->persist($owner);
        $manager->persist($tenant);
        if ($subscribed) {
            $this->persistSubscription($manager, $tenant, $suffix);
        }
        $manager->flush();

        return [$owner, $tenant];
    }

    private function persistSubscription(
        EntityManagerInterface $manager,
        Tenant $tenant,
        string $suffix,
    ): void {
        $plan = new Plan('negocio-'.$suffix, 'Negocio '.$suffix);
        $version = new PlanVersion(
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
            $version,
            SubscriptionState::Active,
            new DateTimeImmutable('2026-09-30T12:34:56.123456Z'),
        );
        $manager->persist($plan);
        $manager->persist($version);
        $manager->persist(Subscription::fromLifecycle(
            $lifecycle,
            new DateTimeImmutable('2026-09-30T12:35:00.000001Z'),
        ));
    }

    /** @return array<string,mixed> */
    private function commercial($client, Tenant $tenant): array
    {
        $client->request('GET', $this->path($tenant));
        self::assertResponseIsSuccessful();
        $payload = json_decode(
            (string) $client->getResponse()->getContent(),
            true,
            512,
            JSON_THROW_ON_ERROR,
        );
        self::assertIsArray($payload);

        return $payload['selected_tenant']['commercial_subscription'];
    }

    private function path(Tenant $tenant): string
    {
        return '/adminpl0n3r/api/context?tenant='.urlencode($tenant->id());
    }

    private function entityManager(): EntityManagerInterface
    {
        $manager = static::getContainer()->get(EntityManagerInterface::class);
        self::assertInstanceOf(EntityManagerInterface::class, $manager);

        return $manager;
    }
}
