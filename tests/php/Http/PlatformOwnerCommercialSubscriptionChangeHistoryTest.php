<?php

declare(strict_types=1);

namespace App\Tests\Http;

use App\Domain\Commercial\Entity\Plan;
use App\Domain\Commercial\Entity\PlanVersion;
use App\Domain\Commercial\Entity\SubscriptionChangeRecord;
use App\Domain\Commercial\SubscriptionChange;
use App\Domain\Identity\Entity\User;
use App\Domain\Organization\Entity\Tenant;
use DateTimeImmutable;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

final class PlatformOwnerCommercialSubscriptionChangeHistoryTest extends WebTestCase
{
    public function testOwnerGetsRecentTenantScopedHistory(): void
    {
        $client = self::createClient();
        [$owner, $tenant, $other, $current, $target] = $this->fixture('recent');
        $client->loginUser($owner);

        $ids = [];
        for ($second = 0; $second < 21; ++$second) {
            $ids[] = $this->persistChange(
                $tenant->id(),
                $current,
                $target,
                new DateTimeImmutable(sprintf('2026-10-01T00:00:%02d.000001Z', $second)),
            );
        }
        $otherId = $this->persistChange(
            $other->id(),
            $current,
            $target,
            new DateTimeImmutable('2026-10-01T00:01:00.000001Z'),
        );
        $this->em()->flush();

        $client->request('GET', $this->path($tenant));
        self::assertResponseIsSuccessful();
        $history = $this->payload($client)['selected_tenant']['commercial_subscription_changes'];
        self::assertCount(20, $history);
        self::assertSame(array_reverse(array_slice($ids, 1)), array_column($history, 'id'));
        self::assertNotContains($otherId, array_column($history, 'id'));
        self::assertSame('2026-10-01T00:00:20.000001Z', $history[0]['requested_at']);
        self::assertSame('upgrade', $history[0]['direction']);
        self::assertSame('effective', $history[0]['status']);
    }

    public function testCorruptRecordFailsClosed(): void
    {
        $client = self::createClient();
        [$owner, $tenant, , $current, $target] = $this->fixture('corrupt');
        $client->loginUser($owner);
        $id = $this->persistChange(
            $tenant->id(),
            $current,
            $target,
            new DateTimeImmutable('2026-10-01T00:00:00.000001Z'),
        );
        $this->em()->flush();
        $this->em()->getConnection()->executeStatement(
            'UPDATE condor_commercial_subscription_change SET direction = ? WHERE id = ?',
            ['sideways', $id],
        );
        $this->em()->clear();

        $client->request('GET', $this->path($tenant));
        self::assertResponseStatusCodeSame(500);
    }

    public function testPayloadIsMinimalAndReadOnly(): void
    {
        $client = self::createClient();
        [$owner, $tenant, , $current, $target] = $this->fixture('shape');
        $client->loginUser($owner);
        $this->persistChange(
            $tenant->id(),
            $current,
            $target,
            new DateTimeImmutable('2026-10-01T00:00:00.000001Z'),
        );
        $this->em()->flush();
        $before = $this->em()->getRepository(SubscriptionChangeRecord::class)->count([]);

        $client->request('GET', $this->path($tenant));
        self::assertResponseIsSuccessful();
        $row = $this->payload($client)['selected_tenant']['commercial_subscription_changes'][0];
        $keys = array_keys($row);
        sort($keys);
        self::assertSame([
            'blockers', 'current_plan', 'direction', 'effective_at',
            'id', 'requested_at', 'status', 'target_plan',
        ], $keys);
        foreach (['current_plan', 'target_plan'] as $planKey) {
            $planKeys = array_keys($row[$planKey]);
            sort($planKeys);
            self::assertSame(['key', 'name', 'version'], $planKeys);
        }
        self::assertSame(
            $before,
            $this->em()->getRepository(SubscriptionChangeRecord::class)->count([]),
        );
    }

    public function testEmptyHistoryAndUnknownTenantBoundaries(): void
    {
        $client = self::createClient();
        [$owner, $tenant] = $this->fixture('empty');
        $client->loginUser($owner);

        $client->request('GET', $this->path($tenant));
        self::assertResponseIsSuccessful();
        self::assertSame(
            [],
            $this->payload($client)['selected_tenant']['commercial_subscription_changes'],
        );

        $client->request(
            'GET',
            '/adminpl0n3r/api/context?tenant=00000000000000000000000000',
        );
        self::assertResponseStatusCodeSame(404);
    }

    /** @return array{User,Tenant,Tenant,PlanVersion,PlanVersion} */
    private function fixture(string $suffix): array
    {
        $id = bin2hex(random_bytes(3));
        $owner = new User(
            "owner-history-$suffix-$id@example.test",
            'Propietario',
            [User::ROLE_PLATFORM_OWNER],
        );
        $tenant = new Tenant("Empresa $suffix $id", "empresa-$suffix-$id");
        $other = new Tenant("Otra $suffix $id", "otra-$suffix-$id");
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
            199900,
            1999000,
            false,
            ['users' => 10],
            new DateTimeImmutable('2026-01-01T00:00:00Z'),
        );
        foreach ([$owner, $tenant, $other, $currentPlan, $targetPlan, $current, $target] as $entity) {
            $this->em()->persist($entity);
        }
        $this->em()->flush();

        return [$owner, $tenant, $other, $current, $target];
    }

    private function persistChange(
        string $tenantId,
        PlanVersion $current,
        PlanVersion $target,
        DateTimeImmutable $requestedAt,
    ): string {
        $record = SubscriptionChangeRecord::fromChange(SubscriptionChange::upgrade(
            $tenantId,
            $current,
            $target,
            $requestedAt,
        ));
        $this->em()->persist($record);

        return $record->id();
    }

    private function path(Tenant $tenant): string
    {
        return '/adminpl0n3r/api/context?tenant='.urlencode($tenant->id());
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

    private function em(): EntityManagerInterface
    {
        $manager = self::getContainer()->get(EntityManagerInterface::class);
        self::assertInstanceOf(EntityManagerInterface::class, $manager);

        return $manager;
    }
}
