<?php

declare(strict_types=1);

namespace App\Tests\Http;

use App\Domain\Commercial\Entity\Plan;
use App\Domain\Commercial\Entity\PlanVersion;
use App\Domain\Commercial\Entity\Subscription;
use App\Domain\Commercial\Entity\UsageObservation;
use App\Domain\Commercial\SubscriptionLifecycle;
use App\Domain\Commercial\SubscriptionState;
use App\Domain\Commercial\UsageMetric;
use App\Domain\Commercial\UsageRecord;
use App\Domain\Identity\Entity\User;
use App\Domain\Organization\Entity\Tenant;
use DateTimeImmutable;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

final class PlatformOwnerSaasControlCenterTest extends WebTestCase
{
    public function testOwnerReadsCanonicalTenantPlanSubscriptionAndUsage(): void
    {
        $client = static::createClient();
        $manager = $this->entityManager();

        $owner = new User(
            'owner-control-center@example.test',
            'Propietario Control Center',
            [User::ROLE_PLATFORM_OWNER],
        );
        $tenant = new Tenant('Cliente Control Center', 'cliente-control-center');
        $plan = new Plan('negocio-control', 'Negocio Control');
        $version = new PlanVersion(
            $plan,
            7,
            249900,
            2499000,
            false,
            ['users' => 20],
            new DateTimeImmutable('2026-01-01T00:00:00Z'),
        );
        $subscription = Subscription::fromLifecycle(
            new SubscriptionLifecycle(
                $tenant->id(),
                $version,
                SubscriptionState::Active,
                new DateTimeImmutable('2026-10-01T12:00:00.000001Z'),
            ),
            new DateTimeImmutable('2026-10-01T12:00:01.000001Z'),
        );

        $windowStart = new DateTimeImmutable('2026-10-01T00:00:00.000001Z');
        $windowEnd = new DateTimeImmutable('2026-11-01T00:00:00.000001Z');
        foreach ([
            [UsageMetric::Users, 3, '2026-10-01T12:05:00.000001Z'],
            [UsageMetric::Users, 8, '2026-10-01T12:06:00.000001Z'],
            [UsageMetric::Orders, 4, '2026-10-01T12:07:00.000001Z'],
            [UsageMetric::Orders, 6, '2026-10-01T12:08:00.000001Z'],
        ] as [$metric, $quantity, $observedAt]) {
            $manager->persist(UsageObservation::fromRecord(new UsageRecord(
                $tenant->id(),
                $metric,
                $quantity,
                $windowStart,
                $windowEnd,
                new DateTimeImmutable($observedAt),
            )));
        }

        foreach ([$owner, $tenant, $plan, $version, $subscription] as $entity) {
            $manager->persist($entity);
        }
        $manager->flush();

        $client->loginUser($owner);
        $client->request('GET', '/adminpl0n3r/api/context');
        self::assertResponseIsSuccessful();

        $payload = json_decode(
            (string) $client->getResponse()->getContent(),
            true,
            512,
            JSON_THROW_ON_ERROR,
        );
        self::assertIsArray($payload);
        self::assertArrayHasKey('commercial_portfolio', $payload);

        $entry = null;
        foreach ($payload['commercial_portfolio'] as $candidate) {
            if (($candidate['tenant']['id'] ?? null) === $tenant->id()) {
                $entry = $candidate;
                break;
            }
        }

        self::assertIsArray($entry);
        self::assertSame(
            [
                'id' => $tenant->id(),
                'name' => 'Cliente Control Center',
                'slug' => 'cliente-control-center',
            ],
            $entry['tenant'],
        );
        self::assertSame(
            'negocio-control',
            $entry['commercial_subscription']['subscription']['plan']['key'],
        );
        self::assertSame(
            'active',
            $entry['commercial_subscription']['subscription']['state'],
        );

        $usage = [];
        foreach ($entry['usage'] as $row) {
            $usage[$row['metric']] = $row;
        }
        self::assertSame(8, $usage['users']['quantity']);
        self::assertSame('max', $usage['users']['aggregation']);
        self::assertSame(10, $usage['orders']['quantity']);
        self::assertSame('sum', $usage['orders']['aggregation']);

        $encoded = strtolower(json_encode($entry, JSON_THROW_ON_ERROR));
        foreach (['email', 'phone', 'password', 'secret', 'billing'] as $forbidden) {
            self::assertStringNotContainsString($forbidden, $encoded);
        }
    }

    public function testNonOwnerAccessFailsClosed(): void
    {
        $client = static::createClient();
        $manager = $this->entityManager();
        $user = new User(
            'tenant-control-center@example.test',
            'Usuario tenant',
        );
        $manager->persist($user);
        $manager->flush();

        $client->loginUser($user);
        $client->request('GET', '/adminpl0n3r/api/context');

        self::assertResponseStatusCodeSame(403);
    }

    private function entityManager(): EntityManagerInterface
    {
        $manager = static::getContainer()->get(EntityManagerInterface::class);
        self::assertInstanceOf(EntityManagerInterface::class, $manager);

        return $manager;
    }
}
