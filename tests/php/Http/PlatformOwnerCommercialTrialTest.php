<?php

declare(strict_types=1);

namespace App\Tests\Http;

use App\Application\Commercial\CommercialCatalogSeeder;
use App\Domain\Commercial\Entity\PlanVersion;
use App\Domain\Commercial\Entity\Subscription;
use App\Domain\Commercial\SubscriptionLifecycle;
use App\Domain\Commercial\SubscriptionState;
use App\Domain\Identity\Entity\User;
use App\Domain\Organization\Entity\Tenant;
use App\Tests\Support\BrowserCsrfToken;
use DateInterval;
use DateTimeImmutable;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

final class PlatformOwnerCommercialTrialTest extends WebTestCase
{
    use BrowserCsrfToken;

    public function testTrialWindowIsExactAndNotFabricated(): void
    {
        self::bootKernel();
        $this->seedCatalog();
        $version = $this->businessVersion();
        $startedAt = new DateTimeImmutable('2026-10-01T12:34:56.123456Z');

        $trial = new SubscriptionLifecycle(
            'tenant-trial-window',
            $version,
            SubscriptionState::Trialing,
            $startedAt,
        );
        self::assertSame(
            $startedAt->format('U.u'),
            $trial->trialStartedAt()?->format('U.u'),
        );
        self::assertSame(
            $startedAt->add(new DateInterval('P14D'))->format('U.u'),
            $trial->trialEndsAt()?->format('U.u'),
        );

        $persisted = Subscription::fromLifecycle($trial, $startedAt);
        self::assertSame(
            $startedAt->format('U.u'),
            $persisted->toLifecycle()->trialStartedAt()?->format('U.u'),
        );
        self::assertSame(
            $startedAt->add(new DateInterval('P14D'))->format('U.u'),
            $persisted->toLifecycle()->trialEndsAt()?->format('U.u'),
        );

        $active = new SubscriptionLifecycle(
            'tenant-active-window',
            $version,
            SubscriptionState::Active,
            $startedAt,
        );
        self::assertNull($active->trialStartedAt());
        self::assertNull($active->trialEndsAt());
    }

    public function testOwnerStartsBusinessTrialAndDuplicateFailsClosed(): void
    {
        $client = self::createClient();
        [$owner, $tenant] = $this->fixture('create');
        $client->loginUser($owner);
        $token = $this->token($client);

        $client->jsonRequest(
            'POST',
            '/adminpl0n3r/api/tenants/'.urlencode($tenant->id()).'/commercial-subscription/trial',
            [],
            ['HTTP_X_CSRF_TOKEN' => $token],
        );

        self::assertResponseStatusCodeSame(201);
        $payload = json_decode(
            (string) $client->getResponse()->getContent(),
            true,
            512,
            JSON_THROW_ON_ERROR,
        );
        self::assertIsArray($payload);
        self::assertSame('configured', $payload['status']);
        self::assertSame('trialing', $payload['subscription']['state']);
        self::assertSame('business', $payload['subscription']['plan']['key']);

        $startedAt = new DateTimeImmutable(
            $payload['subscription']['trial_started_at'],
        );
        $endsAt = new DateTimeImmutable(
            $payload['subscription']['trial_ends_at'],
        );
        self::assertSame(
            $startedAt->add(new DateInterval('P14D'))->format('U.u'),
            $endsAt->format('U.u'),
        );
        self::assertSame(1, $this->em()->getRepository(Subscription::class)->count([
            'tenantId' => $tenant->id(),
        ]));

        $client->jsonRequest(
            'POST',
            '/adminpl0n3r/api/tenants/'.urlencode($tenant->id()).'/commercial-subscription/trial',
            [],
            ['HTTP_X_CSRF_TOKEN' => $token],
        );
        self::assertResponseStatusCodeSame(422);
        self::assertSame(1, $this->em()->getRepository(Subscription::class)->count([
            'tenantId' => $tenant->id(),
        ]));
    }

    public function testTrialEndpointIsOwnerCsrfAndServerAuthoritative(): void
    {
        $client = self::createClient();
        [$owner, $tenant] = $this->fixture('boundary');
        $normal = new User(
            'normal-trial-'.bin2hex(random_bytes(4)).'@example.test',
            'Normal',
        );
        $this->em()->persist($normal);
        $this->em()->flush();

        $client->loginUser($normal);
        $client->jsonRequest(
            'POST',
            '/adminpl0n3r/api/tenants/'.urlencode($tenant->id()).'/commercial-subscription/trial',
            [],
            ['HTTP_X_CSRF_TOKEN' => 'invalid'],
        );
        self::assertResponseStatusCodeSame(403);

        $client->loginUser($owner);
        $client->jsonRequest(
            'POST',
            '/adminpl0n3r/api/tenants/'.urlencode($tenant->id()).'/commercial-subscription/trial',
            [],
        );
        self::assertResponseStatusCodeSame(403);

        $client->request(
            'GET',
            '/adminpl0n3r/api/tenants/'.urlencode($tenant->id()).'/commercial-subscription/trial',
        );
        self::assertResponseStatusCodeSame(405);

        $token = $this->token($client);
        foreach ([
            ['plan_version_id' => $this->businessVersion()->id()],
            ['duration_days' => 30],
            ['state' => 'active'],
        ] as $payload) {
            $client->jsonRequest(
                'POST',
                '/adminpl0n3r/api/tenants/'.urlencode($tenant->id()).'/commercial-subscription/trial',
                $payload,
                ['HTTP_X_CSRF_TOKEN' => $token],
            );
            self::assertResponseStatusCodeSame(422);
            self::assertSame(0, $this->em()->getRepository(Subscription::class)->count([
            'tenantId' => $tenant->id(),
        ]));
        }

        $client->jsonRequest(
            'POST',
            '/adminpl0n3r/api/tenants/00000000000000000000000000/commercial-subscription/trial',
            [],
            ['HTTP_X_CSRF_TOKEN' => $token],
        );
        self::assertResponseStatusCodeSame(404);
    }

    public function testSummaryExposesTrialWindowOnlyWhileTrialing(): void
    {
        $client = self::createClient();
        [$owner, $tenant] = $this->fixture('summary');
        $client->loginUser($owner);
        $trialToken = $this->token($client);
        $client->jsonRequest(
            'POST',
            '/adminpl0n3r/api/tenants/'.urlencode($tenant->id()).'/commercial-subscription/trial',
            [],
            ['HTTP_X_CSRF_TOKEN' => $trialToken],
        );
        self::assertResponseStatusCodeSame(201);

        $trialResponse = json_decode(
            (string) $client->getResponse()->getContent(),
            true,
            512,
            JSON_THROW_ON_ERROR,
        );
        self::assertIsArray($trialResponse);
        $trialPayload = $trialResponse['subscription'];
        self::assertArrayHasKey('trial_started_at', $trialPayload);
        self::assertArrayHasKey('trial_ends_at', $trialPayload);

        $subscription = $this->em()
            ->getRepository(Subscription::class)
            ->findOneBy(['tenantId' => $tenant->id()]);
        self::assertInstanceOf(Subscription::class, $subscription);
        $lifecycle = $subscription->toLifecycle();
        $changedAt = $subscription->lastChangedAt()->modify('+1 second');
        $lifecycle->transitionTo(SubscriptionState::Active, $changedAt);
        $subscription->syncFromLifecycle($lifecycle, $changedAt);
        $this->em()->flush();

        $client->request(
            'GET',
            '/adminpl0n3r/api/context?tenant='.urlencode($tenant->id()),
        );
        self::assertResponseIsSuccessful();
        $context = json_decode(
            (string) $client->getResponse()->getContent(),
            true,
            512,
            JSON_THROW_ON_ERROR,
        );
        self::assertIsArray($context);
        $summary = $context['selected_tenant']['commercial_subscription'];
        self::assertSame('active', $summary['subscription']['state']);
        self::assertArrayNotHasKey('trial_started_at', $summary['subscription']);
        self::assertArrayNotHasKey('trial_ends_at', $summary['subscription']);
        self::assertSame(
            ['state', 'plan', 'last_changed_at'],
            array_keys($summary['subscription']),
        );
    }

    /** @return array{User,Tenant} */
    private function fixture(string $suffix): array
    {
        $this->seedCatalog();
        $id = bin2hex(random_bytes(3));
        $owner = new User(
            "owner-trial-$suffix-$id@example.test",
            'Propietario',
            [User::ROLE_PLATFORM_OWNER],
        );
        $tenant = new Tenant(
            "Empresa trial $suffix $id",
            "empresa-trial-$suffix-$id",
        );
        $this->em()->persist($owner);
        $this->em()->persist($tenant);
        $this->em()->flush();

        return [$owner, $tenant];
    }

    private function seedCatalog(): void
    {
        $seeder = self::getContainer()->get(CommercialCatalogSeeder::class);
        self::assertInstanceOf(CommercialCatalogSeeder::class, $seeder);
        $seeder->seed();
    }

    private function businessVersion(): PlanVersion
    {
        $versions = $this->em()->getRepository(PlanVersion::class)->findAll();
        foreach ($versions as $version) {
            if (
                $version instanceof PlanVersion
                && $version->plan()->key() === 'business'
                && $version->plan()->isActive()
                && $version->isEffectiveAt(new DateTimeImmutable('now'))
            ) {
                return $version;
            }
        }

        self::fail('PlanVersion vigente de business no encontrada.');
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

    private function em(): EntityManagerInterface
    {
        $manager = self::getContainer()->get(EntityManagerInterface::class);
        self::assertInstanceOf(EntityManagerInterface::class, $manager);

        return $manager;
    }
}