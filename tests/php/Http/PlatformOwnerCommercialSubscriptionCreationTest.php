<?php

declare(strict_types=1);

namespace App\Tests\Http;

use App\Domain\Commercial\Entity\{Plan, PlanVersion, Subscription};
use App\Domain\Commercial\{SubscriptionLifecycle, SubscriptionState};
use App\Domain\Identity\Entity\User;
use App\Domain\Organization\Entity\Tenant;
use App\Tests\Support\BrowserCsrfToken;
use DateTimeImmutable;
use DateTimeZone;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

final class PlatformOwnerCommercialSubscriptionCreationTest extends WebTestCase
{
    use BrowserCsrfToken;

    public function testOwnerCreatesInitialSubscriptionAndGetsUpdatedSummary(): void
    {
        $client = self::createClient();
        [$owner, $tenant, $version] = $this->fixture('create');
        $client->loginUser($owner);

        $this->post($client, $tenant, $version->id(), $this->token($client));

        self::assertResponseStatusCodeSame(201);
        $payload = $this->payload($client);
        self::assertSame('configured', $payload['status']);
        self::assertSame('active', $payload['subscription']['state']);
        self::assertSame($version->plan()->key(), $payload['subscription']['plan']['key']);
        self::assertSame(
            1,
            $this->em()->getRepository(Subscription::class)->count([
                'tenantId' => $tenant->id(),
            ]),
        );
    }

    public function testCreationUsesDomainLifecycleAndServerOwnedIdentity(): void
    {
        $client = self::createClient();
        [$owner, $tenant, $version] = $this->fixture('identity');
        $client->loginUser($owner);
        $before = new DateTimeImmutable('now', new DateTimeZone('UTC'));

        $this->post($client, $tenant, $version->id(), $this->token($client));

        self::assertResponseStatusCodeSame(201);
        $after = new DateTimeImmutable('now', new DateTimeZone('UTC'));
        $subscription = $this->subscription($tenant);
        self::assertSame($tenant->id(), $subscription->tenantId());
        self::assertSame(SubscriptionState::Active, $subscription->state());
        self::assertSame($version->id(), $subscription->planVersion()->id());
        self::assertCount(1, $subscription->history());
        self::assertGreaterThanOrEqual(
            (float) $before->format('U.u'),
            (float) $subscription->lastChangedAt()->format('U.u'),
        );
        self::assertLessThanOrEqual(
            (float) $after->format('U.u'),
            (float) $subscription->lastChangedAt()->format('U.u'),
        );
    }

    public function testMissingTenantPlanOrExistingSubscriptionFailClosed(): void
    {
        $client = self::createClient();
        [$owner, $tenant, $version] = $this->fixture('negative');
        $client->loginUser($owner);
        $token = $this->token($client);

        $client->jsonRequest(
            'POST',
            '/adminpl0n3r/api/tenants/00000000000000000000000000/commercial-subscription',
            ['plan_version_id' => $version->id()],
            ['HTTP_X_CSRF_TOKEN' => $token],
        );
        self::assertResponseStatusCodeSame(404);

        $this->post($client, $tenant, '00000000000000000000000000', $token);
        self::assertResponseStatusCodeSame(422);
        self::assertSame(0, $this->subscriptionCount($tenant));

        $future = $this->planVersion('future', new DateTimeImmutable('+1 day'));
        $this->post($client, $tenant, $future->id(), $token);
        self::assertResponseStatusCodeSame(422);
        self::assertSame(0, $this->subscriptionCount($tenant));

        $managedVersion = $this->em()
            ->getRepository(PlanVersion::class)
            ->find($version->id());
        self::assertInstanceOf(PlanVersion::class, $managedVersion);
        $existing = new SubscriptionLifecycle(
            $tenant->id(),
            $managedVersion,
            SubscriptionState::Active,
            new DateTimeImmutable('-1 minute'),
        );
        $this->em()->persist(Subscription::fromLifecycle(
            $existing,
            new DateTimeImmutable('-1 minute'),
        ));
        $this->em()->flush();

        $this->post($client, $tenant, $version->id(), $token);
        self::assertResponseStatusCodeSame(422);
        self::assertSame(1, $this->subscriptionCount($tenant));
    }

    public function testEndpointIsOwnerCsrfPostOnlyWithClosedPayload(): void
    {
        $client = self::createClient();
        [$owner, $tenant, $version] = $this->fixture('boundary');
        $user = new User(
            'normal-'.bin2hex(random_bytes(4)).'@example.test',
            'Normal',
        );
        $this->em()->persist($user);
        $this->em()->flush();

        $client->loginUser($user);
        $this->post($client, $tenant, $version->id(), 'invalid');
        self::assertResponseStatusCodeSame(403);

        $client->loginUser($owner);
        $this->post($client, $tenant, $version->id());
        self::assertResponseStatusCodeSame(403);

        $client->request('GET', $this->path($tenant));
        self::assertResponseStatusCodeSame(405);

        $client->jsonRequest('POST', $this->path($tenant), [
            'plan_version_id' => $version->id(),
            'state' => 'active',
        ], ['HTTP_X_CSRF_TOKEN' => $this->token($client)]);
        self::assertResponseStatusCodeSame(422);
        self::assertSame(0, $this->subscriptionCount($tenant));
    }

    public function testCreationIsTenantScoped(): void
    {
        $client = self::createClient();
        [$owner, $first, $version] = $this->fixture('scope-a');
        $second = new Tenant(
            'Empresa scope b',
            'empresa-scope-b-'.bin2hex(random_bytes(3)),
        );
        $this->em()->persist($second);
        $this->em()->flush();
        $client->loginUser($owner);

        $this->post($client, $first, $version->id(), $this->token($client));

        self::assertResponseStatusCodeSame(201);
        self::assertSame(1, $this->subscriptionCount($first));
        self::assertSame(0, $this->subscriptionCount($second));
    }

    /** @return array{User,Tenant,PlanVersion} */
    private function fixture(string $suffix): array
    {
        $id = bin2hex(random_bytes(3));
        $owner = new User(
            "owner-$suffix-$id@example.test",
            'Propietario',
            [User::ROLE_PLATFORM_OWNER],
        );
        $tenant = new Tenant("Empresa $suffix $id", "empresa-$suffix-$id");
        $version = $this->planVersion($suffix, new DateTimeImmutable('-1 day'));

        $this->em()->persist($owner);
        $this->em()->persist($tenant);
        $this->em()->flush();

        return [$owner, $tenant, $version];
    }

    private function planVersion(
        string $suffix,
        DateTimeImmutable $effectiveFrom,
    ): PlanVersion {
        $id = bin2hex(random_bytes(3));
        $plan = new Plan("plan-$suffix-$id", "Plan $suffix");
        $version = new PlanVersion(
            $plan,
            1,
            149900,
            1499000,
            false,
            ['users' => 5],
            $effectiveFrom,
        );
        $this->em()->persist($plan);
        $this->em()->persist($version);
        $this->em()->flush();

        return $version;
    }

    private function post(
        $client,
        Tenant $tenant,
        string $planVersionId,
        ?string $token = null,
    ): void {
        $headers = $token === null ? [] : ['HTTP_X_CSRF_TOKEN' => $token];
        $client->jsonRequest('POST', $this->path($tenant), [
            'plan_version_id' => $planVersionId,
        ], $headers);
    }

    private function token($client): string
    {
        $client->request('GET', '/adminpl0n3r');
        self::assertResponseIsSuccessful();

        return $this->csrfToken(
            $client,
            'platform_commercial_subscription_management',
        );
    }

    /** @return array<string,mixed> */
    private function payload($client): array
    {
        $payload = json_decode(
            (string) $client->getResponse()->getContent(),
            true,
            512,
            JSON_THROW_ON_ERROR,
        );
        self::assertIsArray($payload);

        return $payload;
    }

    private function subscriptionCount(Tenant $tenant): int
    {
        return $this->em()->getRepository(Subscription::class)->count([
            'tenantId' => $tenant->id(),
        ]);
    }

    private function subscription(Tenant $tenant): Subscription
    {
        $subscription = $this->em()
            ->getRepository(Subscription::class)
            ->findOneBy(['tenantId' => $tenant->id()]);
        self::assertInstanceOf(Subscription::class, $subscription);

        return $subscription;
    }

    private function path(Tenant $tenant): string
    {
        return '/adminpl0n3r/api/tenants/'
            .urlencode($tenant->id())
            .'/commercial-subscription';
    }

    private function em(): EntityManagerInterface
    {
        $manager = self::getContainer()->get(EntityManagerInterface::class);
        self::assertInstanceOf(EntityManagerInterface::class, $manager);

        return $manager;
    }
}
