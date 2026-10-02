<?php

declare(strict_types=1);

namespace App\Tests\Http;

use App\Domain\Commercial\Entity\{AddOn, Plan, PlanVersion, Subscription, SubscriptionChangeRecord};
use App\Domain\Commercial\{SubscriptionLifecycle, SubscriptionState};
use App\Domain\Identity\Entity\User;
use App\Domain\Organization\Entity\Tenant;
use App\Tests\Support\BrowserCsrfToken;
use DateTimeImmutable;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

final class PlatformOwnerCommercialAdjustmentTest extends WebTestCase
{
    use BrowserCsrfToken;

    public function testOwnerAppliesPlanAddonPriceAndRenewalAdjustmentsWithAudit(): void
    {
        $client = self::createClient();
        [$owner, $tenant, $target, $addOn] = $this->fixture('owner');
        $client->loginUser($owner);
        $token = $this->token($client);

        $client->jsonRequest('POST', $this->path($tenant), [
            'target_plan_version_id' => $target->id(),
            'add_on_ids' => [$addOn->id()],
            'renews_at' => null,
            'reason' => 'Upgrade comercial aprobado',
        ], ['HTTP_X_CSRF_TOKEN' => $token]);

        self::assertResponseStatusCodeSame(201);
        $payload = $this->payload($client);
        self::assertSame('effective', $payload['status']);
        self::assertSame($target->plan()->key(), $payload['target_plan']['key']);
        self::assertSame(249900, $payload['price']['monthly_amount']);
        self::assertSame([$addOn->key()], array_column($payload['add_ons'], 'key'));
        self::assertSame($owner->id(), $payload['audit']['requested_by']);
        self::assertSame('Upgrade comercial aprobado', $payload['audit']['reason']);

        $records = $this->em()->getRepository(SubscriptionChangeRecord::class)
            ->findBy(['tenantId' => $tenant->id()], ['requestedAt' => 'ASC']);
        self::assertCount(1, $records);
        self::assertSame(
            ['requested_by' => $owner->id(), 'reason' => 'Upgrade comercial aprobado'],
            $records[0]->audit(),
        );
        self::assertSame($target->id(), $records[0]->toChange()->targetPlan()->id());

        $renewsAt = (new DateTimeImmutable('+30 days'))
            ->setTime(12, 0, 0, 123456)
            ->format('Y-m-d\\TH:i:s.u\\Z');
        $client->jsonRequest('POST', $this->path($tenant), [
            'target_plan_version_id' => $target->id(),
            'add_on_ids' => [],
            'renews_at' => $renewsAt,
            'reason' => 'Renovación comercial aprobada',
        ], ['HTTP_X_CSRF_TOKEN' => $token]);

        self::assertResponseStatusCodeSame(201);
        $scheduled = $this->payload($client);
        self::assertSame('scheduled', $scheduled['status']);
        self::assertSame($renewsAt, $scheduled['effective_at']);
        self::assertSame('Renovación comercial aprobada', $scheduled['audit']['reason']);
        self::assertSame(
            2,
            $this->em()->getRepository(SubscriptionChangeRecord::class)
                ->count(['tenantId' => $tenant->id()]),
        );
    }

    public function testInvalidOrUnauthorizedAdjustmentFailsClosed(): void
    {
        $client = self::createClient();
        [$owner, $tenant, $target, $addOn] = $this->fixture('negative');
        $normal = new User(
            'normal-adjustment-'.bin2hex(random_bytes(4)).'@example.test',
            'Normal',
        );
        $foreignAddOn = new AddOn(
            'foreign-'.bin2hex(random_bytes(3)),
            'Add-on fuera del plan',
            19000,
        );
        $this->em()->persist($normal);
        $this->em()->persist($foreignAddOn);
        $this->em()->flush();

        $client->loginUser($normal);
        $client->jsonRequest('POST', $this->path($tenant), [
            'target_plan_version_id' => $target->id(),
            'add_on_ids' => [$addOn->id()],
            'renews_at' => null,
            'reason' => 'No autorizado',
        ]);
        self::assertResponseStatusCodeSame(403);
        self::assertSame(0, $this->recordCount($tenant));

        $client->loginUser($owner);
        $token = $this->token($client);
        foreach ([
            [
                'target_plan_version_id' => '00000000000000000000000000',
                'add_on_ids' => [],
                'renews_at' => null,
                'reason' => 'Plan inexistente',
            ],
            [
                'target_plan_version_id' => $target->id(),
                'add_on_ids' => [$foreignAddOn->id()],
                'renews_at' => null,
                'reason' => 'Add-on incompatible',
            ],
            [
                'target_plan_version_id' => $target->id(),
                'add_on_ids' => [],
                'renews_at' => '2020-01-01T00:00:00.000001Z',
                'reason' => 'Renovación inválida',
            ],
            [
                'target_plan_version_id' => $target->id(),
                'add_on_ids' => [],
                'renews_at' => null,
                'reason' => '',
            ],
        ] as $payload) {
            $client->jsonRequest(
                'POST',
                $this->path($tenant),
                $payload,
                ['HTTP_X_CSRF_TOKEN' => $token],
            );
            self::assertResponseStatusCodeSame(422);
            self::assertSame(0, $this->recordCount($tenant));
        }

        $client->jsonRequest('POST', $this->path($tenant), [
            'target_plan_version_id' => $target->id(),
            'add_on_ids' => [],
            'renews_at' => null,
            'reason' => 'Payload cerrado',
            'monthly_amount' => 1,
        ], ['HTTP_X_CSRF_TOKEN' => $token]);
        self::assertResponseStatusCodeSame(422);
        self::assertSame(0, $this->recordCount($tenant));
    }

    /** @return array{User,Tenant,PlanVersion,AddOn} */
    private function fixture(string $suffix): array
    {
        $id = bin2hex(random_bytes(3));
        $owner = new User(
            "owner-adjustment-$suffix-$id@example.test",
            'Propietario',
            [User::ROLE_PLATFORM_OWNER],
        );
        $tenant = new Tenant("Empresa adjustment $suffix $id", "empresa-adjustment-$suffix-$id");
        $currentPlan = new Plan("current-$suffix-$id", "Actual $suffix");
        $targetPlan = new Plan("target-$suffix-$id", "Objetivo $suffix");
        $current = new PlanVersion(
            $currentPlan,
            1,
            149900,
            1499000,
            false,
            ['users' => 5],
            new DateTimeImmutable('2026-01-01T00:00:00Z'),
        );
        $target = new PlanVersion(
            $targetPlan,
            1,
            249900,
            2499000,
            false,
            ['users' => 20],
            new DateTimeImmutable('2026-01-01T00:00:00Z'),
        );
        $addOn = new AddOn(
            "analytics-$suffix-$id",
            'Analítica avanzada',
            49000,
        );
        $target->addAddOn($addOn);
        foreach ([$owner, $tenant, $currentPlan, $targetPlan, $current, $target, $addOn] as $entity) {
            $this->em()->persist($entity);
        }
        $lifecycle = new SubscriptionLifecycle(
            $tenant->id(),
            $current,
            SubscriptionState::Active,
            new DateTimeImmutable('-1 day'),
        );
        $this->em()->persist(Subscription::fromLifecycle(
            $lifecycle,
            new DateTimeImmutable('-1 day'),
        ));
        $this->em()->flush();

        return [$owner, $tenant, $target, $addOn];
    }

    private function path(Tenant $tenant): string
    {
        return '/adminpl0n3r/api/tenants/'
            .urlencode($tenant->id())
            .'/commercial-subscription/adjustments';
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

    private function recordCount(Tenant $tenant): int
    {
        return $this->em()->getRepository(SubscriptionChangeRecord::class)
            ->count(['tenantId' => $tenant->id()]);
    }

    private function em(): EntityManagerInterface
    {
        $manager = self::getContainer()->get(EntityManagerInterface::class);
        self::assertInstanceOf(EntityManagerInterface::class, $manager);

        return $manager;
    }
}
