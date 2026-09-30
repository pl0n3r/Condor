<?php

declare(strict_types=1);

namespace App\Tests\Http;

use App\Domain\Commercial\Entity\{Plan, PlanVersion, Subscription};
use App\Domain\Commercial\{SubscriptionLifecycle, SubscriptionState};
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
        [$owner, $tenant] = $this->fixture('ok', SubscriptionState::Active);
        $client->loginUser($owner);
        $this->post($client, $tenant, 'past_due', $this->token($client));
        self::assertResponseIsSuccessful();
        self::assertSame('past_due', $this->payload($client)['subscription']['state']);
        $subscription = $this->subscription($tenant);
        self::assertSame(SubscriptionState::PastDue, $subscription->state());
        self::assertCount(2, $subscription->history());
    }

    public function testPayloadRejectsCallerTimestamp(): void
    {
        $client = static::createClient();
        [$owner, $tenant] = $this->fixture('timestamp', SubscriptionState::Active);
        $client->loginUser($owner);
        $before = $this->snapshot($tenant);
        $client->jsonRequest('POST', $this->path($tenant), [
            'target_state' => 'past_due',
            'changed_at' => '2099-01-01T00:00:00Z',
        ], ['HTTP_X_CSRF_TOKEN' => $this->token($client)]);
        self::assertResponseStatusCodeSame(422);
        self::assertSame($before, $this->snapshot($tenant));
    }

    public function testUnknownOrUnconfiguredTenantFailsClosed(): void
    {
        $client = static::createClient();
        [$owner] = $this->fixture('unknown', null);
        $client->loginUser($owner);
        $token = $this->token($client);
        $client->jsonRequest('POST', '/adminpl0n3r/api/tenants/00000000000000000000000000/commercial-subscription/state', ['target_state' => 'active'], ['HTTP_X_CSRF_TOKEN' => $token]);
        self::assertResponseStatusCodeSame(404);

        $tenant = new Tenant('Sin suscripción', 'sin-suscripcion-'.bin2hex(random_bytes(3)));
        $this->em()->persist($tenant);
        $this->em()->flush();
        $this->post($client, $tenant, 'active', $token);
        self::assertResponseStatusCodeSame(422);
        self::assertSame(0, $this->em()->getRepository(Subscription::class)->count(['tenantId' => $tenant->id()]));
    }

    public function testOwnerCsrfAndPostOnlyBoundary(): void
    {
        $client = static::createClient();
        [$owner, $tenant] = $this->fixture('boundary', SubscriptionState::Active);
        $user = new User('normal-'.bin2hex(random_bytes(4)).'@example.test', 'Normal');
        $this->em()->persist($user);
        $this->em()->flush();

        $client->loginUser($user);
        $this->post($client, $tenant, 'past_due');
        self::assertResponseStatusCodeSame(403);
        $client->loginUser($owner);
        $this->post($client, $tenant, 'past_due');
        self::assertResponseStatusCodeSame(403);
        $client->request('GET', $this->path($tenant));
        self::assertResponseStatusCodeSame(405);
        self::assertSame(SubscriptionState::Active, $this->subscription($tenant)->state());
    }

    public function testInvalidTransitionDoesNotMutateSubscription(): void
    {
        $client = static::createClient();
        [$owner, $tenant] = $this->fixture('invalid', SubscriptionState::Active);
        $client->loginUser($owner);
        $token = $this->token($client);
        $before = $this->snapshot($tenant);
        foreach (['active', 'trialing'] as $target) {
            $this->post($client, $tenant, $target, $token);
            self::assertResponseStatusCodeSame(422);
            self::assertSame($before, $this->snapshot($tenant));
        }

        [$cancelOwner, $cancelled] = $this->fixture('cancelled', SubscriptionState::Cancelled);
        $client->loginUser($cancelOwner);
        $before = $this->snapshot($cancelled);
        $this->post($client, $cancelled, 'active', $this->token($client));
        self::assertResponseStatusCodeSame(422);
        self::assertSame($before, $this->snapshot($cancelled));
    }

    public function testTenantScopePreservesOtherSubscription(): void
    {
        $client = static::createClient();
        [$owner, $first] = $this->fixture('scope-a', SubscriptionState::Active);
        [, $second] = $this->fixture('scope-b', SubscriptionState::Active);
        $client->loginUser($owner);
        $secondBefore = $this->snapshot($second);
        $this->post($client, $first, 'past_due', $this->token($client));
        self::assertResponseIsSuccessful();
        self::assertSame(SubscriptionState::PastDue, $this->subscription($first)->state());
        self::assertSame($secondBefore, $this->snapshot($second));
    }

    /** @return array{User,Tenant} */
    private function fixture(string $suffix, ?SubscriptionState $state): array
    {
        $id = bin2hex(random_bytes(3));
        $owner = new User("owner-$suffix-$id@example.test", 'Propietario', [User::ROLE_PLATFORM_OWNER]);
        $tenant = new Tenant("Empresa $suffix $id", "empresa-$suffix-$id");
        $this->em()->persist($owner);
        $this->em()->persist($tenant);
        if ($state !== null) {
            $plan = new Plan("plan-$suffix-$id", "Plan $suffix");
            $version = new PlanVersion($plan, 1, 149900, 1499000, false, ['users' => 5], new DateTimeImmutable('2025-01-01T00:00:00Z'));
            $lifecycle = new SubscriptionLifecycle($tenant->id(), $version, $state, new DateTimeImmutable('2026-01-01T00:00:00.000001Z'));
            $this->em()->persist($plan);
            $this->em()->persist($version);
            $this->em()->persist(Subscription::fromLifecycle($lifecycle, new DateTimeImmutable('2026-01-01T00:00:01.000001Z')));
        }
        $this->em()->flush();

        return [$owner, $tenant];
    }

    private function post($client, Tenant $tenant, string $state, ?string $token = null): void
    {
        $headers = $token === null ? [] : ['HTTP_X_CSRF_TOKEN' => $token];
        $client->jsonRequest('POST', $this->path($tenant), ['target_state' => $state], $headers);
    }

    private function token($client): string
    {
        $client->request('GET', '/adminpl0n3r');
        self::assertResponseIsSuccessful();

        return $this->csrfToken($client, 'platform_commercial_subscription_management');
    }

    /** @return array<string,mixed> */
    private function payload($client): array
    {
        $payload = json_decode((string) $client->getResponse()->getContent(), true, 512, JSON_THROW_ON_ERROR);
        self::assertIsArray($payload);

        return $payload;
    }

    /** @return array{state:string,history:array<mixed>,last:string,lock:int} */
    private function snapshot(Tenant $tenant): array
    {
        $subscription = $this->subscription($tenant);

        return [
            'state' => $subscription->state()->value,
            'history' => $subscription->history(),
            'last' => $subscription->lastChangedAt()->format('U.u'),
            'lock' => $subscription->lockVersion(),
        ];
    }

    private function subscription(Tenant $tenant): Subscription
    {
        $subscription = $this->em()->getRepository(Subscription::class)->findOneBy(['tenantId' => $tenant->id()]);
        self::assertInstanceOf(Subscription::class, $subscription);

        return $subscription;
    }

    private function path(Tenant $tenant): string
    {
        return '/adminpl0n3r/api/tenants/'.urlencode($tenant->id()).'/commercial-subscription/state';
    }

    private function em(): EntityManagerInterface
    {
        $manager = static::getContainer()->get(EntityManagerInterface::class);
        self::assertInstanceOf(EntityManagerInterface::class, $manager);

        return $manager;
    }
}
