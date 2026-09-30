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
use App\Tests\Support\BrowserCsrfToken;
use DateTimeImmutable;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

final class PlatformOwnerCommercialSubscriptionStateTest extends WebTestCase
{
    use BrowserCsrfToken;

    public function testOwnerTransitionsPersistedSubscriptionState(): void
    {
        $client = static::createClient();
        [$owner, $tenant] = $this->fixture('transition', SubscriptionState::Active);
        $client->loginUser($owner);
        $token = $this->managementToken($client);

        $client->jsonRequest(
            'POST',
            $this->path($tenant),
            ['target_state' => 'past_due'],
            ['HTTP_X_CSRF_TOKEN' => $token],
        );

        self::assertResponseIsSuccessful();
        $payload = $this->responsePayload($client);
        self::assertSame('configured', $payload['status']);
        self::assertSame('past_due', $payload['subscription']['state']);

        $subscription = $this->subscription($tenant->id());
        self::assertSame(SubscriptionState::PastDue, $subscription->state());
        self::assertSame('past_due', $subscription->history()[1]['state']);
        self::assertCount(2, $subscription->history());
    }

    public function testPayloadRejectsCallerTimestamp(): void
    {
        $client = static::createClient();
        [$owner, $tenant] = $this->fixture('timestamp', SubscriptionState::Active);
        $before = $this->snapshot($tenant->id());
        $client->loginUser($owner);
        $token = $this->managementToken($client);

        $client->jsonRequest(
            'POST',
            $this->path($tenant),
            [
                'target_state' => 'past_due',
                'changed_at' => '2099-01-01T00:00:00Z',
            ],
            ['HTTP_X_CSRF_TOKEN' => $token],
        );

        self::assertResponseStatusCodeSame(422);
        self::assertSame($before, $this->snapshot($tenant->id()));
    }

    public function testUnknownOrUnconfiguredTenantFailsClosed(): void
    {
        $client = static::createClient();
        [$owner] = $this->fixture('unknown', null);
        $client->loginUser($owner);
        $token = $this->managementToken($client);

        $client->jsonRequest(
            'POST',
            '/adminpl0n3r/api/tenants/00000000000000000000000000/commercial-subscription/state',
            ['target_state' => 'active'],
            ['HTTP_X_CSRF_TOKEN' => $token],
        );
        self::assertResponseStatusCodeSame(404);

        $manager = $this->entityManager();
        $tenant = new Tenant('Sin suscripción', 'sin-suscripcion-'.bin2hex(random_bytes(3)));
        $manager->persist($tenant);
        $manager->flush();

        $client->jsonRequest(
            'POST',
            $this->path($tenant),
            ['target_state' => 'active'],
            ['HTTP_X_CSRF_TOKEN' => $token],
        );
        self::assertResponseStatusCodeSame(422);
        self::assertSame(
            0,
            $manager->getRepository(Subscription::class)
                ->count(['tenantId' => $tenant->id()]),
        );
    }

    public function testOwnerCsrfAndPostOnlyBoundary(): void
    {
        $client = static::createClient();
        [$owner, $tenant] = $this->fixture('boundary', SubscriptionState::Active);
        $manager = $this->entityManager();
        $user = new User(
            'normal-sub-state-'.bin2hex(random_bytes(4)).'@example.test',
            'Usuario normal',
        );
        $manager->persist($user);
        $manager->flush();

        $client->loginUser($user);
        $client->jsonRequest(
            'POST',
            $this->path($tenant),
            ['target_state' => 'past_due'],
        );
        self::assertResponseStatusCodeSame(403);

        $client->loginUser($owner);
        $client->jsonRequest(
            'POST',
            $this->path($tenant),
            ['target_state' => 'past_due'],
        );
        self::assertResponseStatusCodeSame(403);

        $client->request('GET', $this->path($tenant));
        self::assertResponseStatusCodeSame(405);
        self::assertSame(SubscriptionState::Active, $this->subscription($tenant->id())->state());
    }

    public function testInvalidTransitionDoesNotMutateSubscription(): void
    {
        $client = static::createClient();
        [$owner, $tenant] = $this->fixture('invalid', SubscriptionState::Active);
        $before = $this->snapshot($tenant->id());
        $client->loginUser($owner);
        $token = $this->managementToken($client);

        foreach (['active', 'trialing'] as $target) {
            $client->jsonRequest(
                'POST',
                $this->path($tenant),
                ['target_state' => $target],
                ['HTTP_X_CSRF_TOKEN' => $token],
            );
            self::assertResponseStatusCodeSame(422);
            self::assertSame($before, $this->snapshot($tenant->id()));
        }

        [$cancelOwner, $cancelled] = $this->fixture(
            'cancelled',
            SubscriptionState::Cancelled,
        );
        $client->loginUser($cancelOwner);
        $cancelToken = $this->managementToken($client);
        $cancelledBefore = $this->snapshot($cancelled->id());

        $client->jsonRequest(
            'POST',
            $this->path($cancelled),
            ['target_state' => 'active'],
            ['HTTP_X_CSRF_TOKEN' => $cancelToken],
        );
        self::assertResponseStatusCodeSame(422);
        self::assertSame($cancelledBefore, $this->snapshot($cancelled->id()));
    }

    public function testTenantScopePreservesOtherSubscription(): void
    {
        $client = static::createClient();
        [$owner, $first] = $this->fixture('scope-a', SubscriptionState::Active);
        [, $second] = $this->fixture('scope-b', SubscriptionState::Active);
        $secondBefore = $this->snapshot($second->id());

        $client->loginUser($owner);
        $token = $this->managementToken($client);
        $client->jsonRequest(
            'POST',
            $this->path($first),
            ['target_state' => 'past_due'],
            ['HTTP_X_CSRF_TOKEN' => $token],
        );

        self::assertResponseIsSuccessful();
        self::assertSame(SubscriptionState::PastDue, $this->subscription($first->id())->state());
        self::assertSame($secondBefore, $this->snapshot($second->id()));
    }

    /** @return array{User,Tenant} */
    private function fixture(
        string $suffix,
        ?SubscriptionState $state,
    ): array {
        $manager = $this->entityManager();
        $token = bin2hex(random_bytes(3));
        $owner = new User(
            'owner-sub-state-'.$suffix.'-'.$token.'@example.test',
            'Propietario comercial',
            [User::ROLE_PLATFORM_OWNER],
        );
        $tenant = new Tenant(
            'Empresa '.$suffix.' '.$token,
            'empresa-'.$suffix.'-'.$token,
        );
        $manager->persist($owner);
        $manager->persist($tenant);

        if ($state !== null) {
            $plan = new Plan(
                'plan-'.$suffix.'-'.$token,
                'Plan '.$suffix,
            );
            $version = new PlanVersion(
                $plan,
                1,
                149900,
                1499000,
                false,
                ['users' => 5],
                new DateTimeImmutable('2025-01-01T00:00:00Z'),
            );
            $lifecycle = new SubscriptionLifecycle(
                $tenant->id(),
                $version,
                $state,
                new DateTimeImmutable('2026-01-01T00:00:00.000001Z'),
            );
            $manager->persist($plan);
            $manager->persist($version);
            $manager->persist(Subscription::fromLifecycle(
                $lifecycle,
                new DateTimeImmutable('2026-01-01T00:00:01.000001Z'),
            ));
        }

        $manager->flush();

        return [$owner, $tenant];
    }

    private function managementToken($client): string
    {
        $client->request('GET', '/adminpl0n3r');
        self::assertResponseIsSuccessful();

        return $this->csrfToken(
            $client,
            'platform_commercial_subscription_management',
        );
    }

    /** @return array<string,mixed> */
    private function responsePayload($client): array
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

    /** @return array{state:string,history:array<mixed>,last_changed_at:string,lock_version:int} */
    private function snapshot(string $tenantId): array
    {
        $subscription = $this->subscription($tenantId);

        return [
            'state' => $subscription->state()->value,
            'history' => $subscription->history(),
            'last_changed_at' => $subscription->lastChangedAt()->format('U.u'),
            'lock_version' => $subscription->lockVersion(),
        ];
    }

    private function subscription(string $tenantId): Subscription
    {
        $subscription = $this->entityManager()
            ->getRepository(Subscription::class)
            ->findOneBy(['tenantId' => $tenantId]);
        self::assertInstanceOf(Subscription::class, $subscription);

        return $subscription;
    }

    private function path(Tenant $tenant): string
    {
        return '/adminpl0n3r/api/tenants/'
            .urlencode($tenant->id())
            .'/commercial-subscription/state';
    }

    private function entityManager(): EntityManagerInterface
    {
        $manager = static::getContainer()->get(EntityManagerInterface::class);
        self::assertInstanceOf(EntityManagerInterface::class, $manager);

        return $manager;
    }
}
