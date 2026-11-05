<?php

declare(strict_types=1);

namespace App\Tests\Http;

use App\Application\Observability\TenantOperationalSnapshot;
use App\Domain\Commercial\Entity\Plan;
use App\Domain\Commercial\Entity\PlanVersion;
use App\Domain\Commercial\Entity\Subscription;
use App\Domain\Commercial\Entity\SubscriptionConfiguration;
use App\Domain\Commercial\Entity\UsageObservation;
use App\Domain\Commercial\Entity\Vertical;
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

final class PlatformOperationalSnapshotControllerTest extends WebTestCase
{
    public function testOwnerSeesOnlyTheRequestedTenantAndNoOtherTenantData(): void
    {
        $client = static::createClient();
        $em = $this->entityManager();
        $now = new DateTimeImmutable('now', new DateTimeZone('UTC'));
        $owner = new User('ops-owner-'.bin2hex(random_bytes(4)).'@example.test', 'Ops Owner', [User::ROLE_PLATFORM_OWNER]);
        $em->persist($owner);
        $a = $this->seedTenant($em, $now, 'opsa', 4);
        $b = $this->seedTenant($em, $now, 'opsb', 77);
        $em->flush();

        $client->loginUser($owner);
        $client->request('GET', '/adminpl0n3r/api/tenants/'.$a->id().'/operational-snapshot');
        self::assertResponseIsSuccessful();
        self::assertStringContainsString('no-store', (string) $client->getResponse()->headers->get('Cache-Control'));
        $raw = (string) $client->getResponse()->getContent();
        $payload = json_decode($raw, true, 512, JSON_THROW_ON_ERROR);

        self::assertSame($a->id(), $payload['tenant_id']);
        self::assertStringStartsWith('ops-opsa', $payload['plan']['key']);
        self::assertSame([['metric' => 'users', 'used' => 4, 'limit' => 12]], $payload['limits_usage']);
        self::assertSame('observado', $payload['ledger']['status']);
        self::assertSame('no_observado', $payload['job_errors']['status']);
        self::assertSame('no_observado', $payload['diagnostics']['status']);
        // Aislamiento: nada del otro tenant ni de las cuentas de usuario.
        self::assertStringNotContainsString($b->id(), $raw);
        self::assertStringNotContainsString('ops-opsb', $raw);
        self::assertStringNotContainsString('"used":77', $raw);
        self::assertStringNotContainsString('ops-owner-'.bin2hex(random_bytes(4)).'@example.test', $raw);
    }

    public function testNonOwnerAnonymousAndUnknownTenantAreDenied(): void
    {
        $client = static::createClient();
        $em = $this->entityManager();
        $now = new DateTimeImmutable('now', new DateTimeZone('UTC'));
        $tenant = $this->seedTenant($em, $now, 'opsc', 1);
        $staff = new User('ops-staff-'.bin2hex(random_bytes(4)).'@example.test', 'Ops Staff', [User::ROLE_PLATFORM_STAFF]);
        $em->persist($staff);
        $owner = new User('ops-owner2-'.bin2hex(random_bytes(4)).'@example.test', 'Ops Owner 2', [User::ROLE_PLATFORM_OWNER]);
        $em->persist($owner);
        $em->flush();
        $url = '/adminpl0n3r/api/tenants/'.$tenant->id().'/operational-snapshot';

        $client->request('GET', $url);
        self::assertContains($client->getResponse()->getStatusCode(), [301, 302, 401, 403]);

        $client->loginUser($staff);
        $client->request('GET', $url);
        self::assertResponseStatusCodeSame(403);

        $client->loginUser($owner);
        $client->request('GET', '/adminpl0n3r/api/tenants/no-existe-ops/operational-snapshot');
        self::assertResponseStatusCodeSame(404);
        $client->request('GET', '/adminpl0n3r/api/tenants/bad%20id/operational-snapshot');
        self::assertResponseStatusCodeSame(404);
    }

    public function testScrubRedactsEmailsAndSecretShapedStrings(): void
    {
        $out = TenantOperationalSnapshot::scrub([
            'a' => 'contacto persona@example.com falló',
            'b' => ['x' => 'Bearer abc.def token=XYZ'],
            'n' => 5,
        ]);

        self::assertSame('contacto [REDACTED] falló', $out['a']);
        self::assertSame('[REDACTED] [REDACTED]', $out['b']['x']);
        self::assertSame(5, $out['n']);
    }

    private function seedTenant(EntityManagerInterface $em, DateTimeImmutable $now, string $slug, int $used): Tenant
    {
        $slug .= bin2hex(random_bytes(3));
        $tenant = new Tenant('Tenant '.$slug, 'ops-'.$slug);
        $plan = new Plan('ops-'.$slug.'-plan', 'Plan '.$slug);
        $vertical = new Vertical('ops-'.$slug.'-v', 'Vertical '.$slug);
        $version = new PlanVersion($plan, 1, 24000, null, false, ['users' => 10], $now->modify('-10 days'));
        $version->addVertical($vertical);
        $subscription = Subscription::fromLifecycle(
            new SubscriptionLifecycle($tenant->id(), $version, SubscriptionState::Active, $now->modify('-2 days')),
            $now->modify('-1 day'),
        );
        $config = new SubscriptionConfiguration($subscription, $vertical, ['users' => 12], [], $now->modify('-1 day'));
        $usage = UsageObservation::fromRecord(new UsageRecord(
            $tenant->id(), UsageMetric::Users, $used, $now->modify('-1 day'), $now->modify('+1 day'), $now->modify('-1 hour'),
        ));
        foreach ([$tenant, $plan, $vertical, $version, $subscription, $config, $usage] as $entity) {
            $em->persist($entity);
        }

        return $tenant;
    }

    private function entityManager(): EntityManagerInterface
    {
        $manager = static::getContainer()->get(EntityManagerInterface::class);
        self::assertInstanceOf(EntityManagerInterface::class, $manager);

        return $manager;
    }
}
