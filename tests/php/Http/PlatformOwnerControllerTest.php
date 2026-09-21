<?php

declare(strict_types=1);

namespace App\Tests\Http;

use App\Domain\Identity\Entity\Membership;
use App\Domain\Identity\Entity\User;
use App\Domain\Organization\Entity\Branch;
use App\Domain\Organization\Entity\Tenant;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

final class PlatformOwnerControllerTest extends WebTestCase
{
    public function testNormalUserCannotAccessOwnerAdministration(): void
    {
        $client = static::createClient();
        $entityManager = static::getContainer()->get(EntityManagerInterface::class);
        self::assertInstanceOf(EntityManagerInterface::class, $entityManager);

        $user = new User(
            'normal-'.bin2hex(random_bytes(4)).'@example.test',
            'Usuario normal',
        );
        $entityManager->persist($user);
        $entityManager->flush();

        $client->loginUser($user);
        $client->request('GET', '/adminpl0n3r');

        self::assertResponseStatusCodeSame(403);
    }

    public function testNormalUserCannotAccessOwnerContextApi(): void
    {
        $client = static::createClient();
        $entityManager = static::getContainer()->get(EntityManagerInterface::class);
        self::assertInstanceOf(EntityManagerInterface::class, $entityManager);

        $user = new User(
            'normal-api-'.bin2hex(random_bytes(4)).'@example.test',
            'Usuario normal API',
        );
        $entityManager->persist($user);
        $entityManager->flush();

        $client->loginUser($user);
        $client->request('GET', '/adminpl0n3r/api/context');

        self::assertResponseStatusCodeSame(403);
    }

    public function testLegacySuperAdminCannotAccessOwnerAdministration(): void
    {
        $client = static::createClient();
        $entityManager = static::getContainer()->get(EntityManagerInterface::class);
        self::assertInstanceOf(EntityManagerInterface::class, $entityManager);

        $user = new User(
            'legacy-'.bin2hex(random_bytes(4)).'@example.test',
            'Administrador legado',
            [User::ROLE_LEGACY_SUPER_ADMIN],
        );
        $entityManager->persist($user);
        $entityManager->flush();

        $client->loginUser($user);
        $client->request('GET', '/adminpl0n3r');

        self::assertResponseStatusCodeSame(403);
    }

    public function testPlatformOwnerCanAccessWithoutTenantMembership(): void
    {
        $client = static::createClient();
        $entityManager = static::getContainer()->get(EntityManagerInterface::class);
        self::assertInstanceOf(EntityManagerInterface::class, $entityManager);

        $user = new User(
            'owner-'.bin2hex(random_bytes(4)).'@example.test',
            'Propietario',
            [User::ROLE_PLATFORM_OWNER],
        );
        $entityManager->persist($user);
        $entityManager->flush();

        $client->loginUser($user);
        $client->request('GET', '/adminpl0n3r');

        self::assertResponseIsSuccessful();
        self::assertSelectorExists('#condor-platform-root');
        self::assertSelectorTextContains('h1', 'Preparando centro de control');
        self::assertSelectorTextContains('.eyebrow', 'Propietario de plataforma');
    }

    public function testOwnerContextApiReturnsRealGlobalMetricsAndTenants(): void
    {
        $client = static::createClient();
        $entityManager = static::getContainer()->get(EntityManagerInterface::class);
        self::assertInstanceOf(EntityManagerInterface::class, $entityManager);

        $suffix = bin2hex(random_bytes(4));
        $owner = new User(
            'owner-context-'.$suffix.'@example.test',
            'Propietario Contexto',
            [User::ROLE_PLATFORM_OWNER],
        );
        $tenantUser = new User(
            'tenant-user-'.$suffix.'@example.test',
            'Usuario Tenant',
        );
        $tenant = new Tenant('Empresa Contexto '.$suffix, 'empresa-contexto-'.$suffix);
        $branch = new Branch($tenant, 'Principal', 'principal', default: true);
        $membership = new Membership($tenant, $tenantUser, Membership::ROLE_OWNER);

        foreach ([$owner, $tenantUser, $tenant, $branch, $membership] as $entity) {
            $entityManager->persist($entity);
        }
        $entityManager->flush();

        $client->loginUser($owner);
        $client->request('GET', '/adminpl0n3r/api/context');

        self::assertResponseIsSuccessful();

        $payload = json_decode((string) $client->getResponse()->getContent(), true);
        self::assertIsArray($payload);
        self::assertSame('global', $payload['mode']);
        self::assertSame($owner->id(), $payload['actor']['id']);
        self::assertSame('platform_owner', $payload['actor']['role']);
        self::assertGreaterThanOrEqual(1, $payload['metrics']['tenant_count']);
        self::assertGreaterThanOrEqual(1, $payload['metrics']['branch_count']);
        self::assertGreaterThanOrEqual(1, $payload['metrics']['active_membership_count']);

        $tenantPayload = null;
        foreach ($payload['tenants'] as $candidate) {
            if ($candidate['id'] === $tenant->id()) {
                $tenantPayload = $candidate;
                break;
            }
        }

        self::assertIsArray($tenantPayload);
        self::assertSame($tenant->name(), $tenantPayload['name']);
        self::assertSame(1, $tenantPayload['branch_count']);
        self::assertSame(1, $tenantPayload['active_membership_count']);
    }

    public function testOwnerCanSelectTenantContextWithoutChangingActorIdentity(): void
    {
        $client = static::createClient();
        $entityManager = static::getContainer()->get(EntityManagerInterface::class);
        self::assertInstanceOf(EntityManagerInterface::class, $entityManager);

        $suffix = bin2hex(random_bytes(4));
        $owner = new User(
            'owner-selected-'.$suffix.'@example.test',
            'Propietario Selección',
            [User::ROLE_PLATFORM_OWNER],
        );
        $tenant = new Tenant('Empresa Seleccionada '.$suffix, 'empresa-seleccionada-'.$suffix);
        $branch = new Branch($tenant, 'Sede Norte', 'sede-norte', default: true);

        foreach ([$owner, $tenant, $branch] as $entity) {
            $entityManager->persist($entity);
        }
        $entityManager->flush();

        $client->loginUser($owner);
        $client->request(
            'GET',
            '/adminpl0n3r/api/context?tenant='.urlencode($tenant->id()),
        );

        self::assertResponseIsSuccessful();

        $payload = json_decode((string) $client->getResponse()->getContent(), true);
        self::assertIsArray($payload);
        self::assertSame('tenant', $payload['mode']);
        self::assertSame($owner->id(), $payload['actor']['id']);
        self::assertSame($tenant->id(), $payload['selected_tenant']['id']);
        self::assertSame('Sede Norte', $payload['selected_tenant']['branches'][0]['name']);
        self::assertTrue($payload['selected_tenant']['branches'][0]['is_default']);
    }

    public function testUnknownTenantContextReturnsNotFound(): void
    {
        $client = static::createClient();
        $entityManager = static::getContainer()->get(EntityManagerInterface::class);
        self::assertInstanceOf(EntityManagerInterface::class, $entityManager);

        $owner = new User(
            'owner-missing-'.bin2hex(random_bytes(4)).'@example.test',
            'Propietario Missing',
            [User::ROLE_PLATFORM_OWNER],
        );
        $entityManager->persist($owner);
        $entityManager->flush();

        $client->loginUser($owner);
        $client->request('GET', '/adminpl0n3r/api/context?tenant=00000000000000000000000000');

        self::assertResponseStatusCodeSame(404);
    }

    public function testLegacySuperAdminRouteIsNotExposed(): void
    {
        $client = static::createClient();
        $entityManager = static::getContainer()->get(EntityManagerInterface::class);
        self::assertInstanceOf(EntityManagerInterface::class, $entityManager);

        $user = new User(
            'owner-'.bin2hex(random_bytes(4)).'@example.test',
            'Propietario',
            [User::ROLE_PLATFORM_OWNER],
        );
        $entityManager->persist($user);
        $entityManager->flush();

        $client->loginUser($user);
        $client->request('GET', '/superadmin');

        self::assertResponseStatusCodeSame(404);
    }

    public function testAdminRedirectsOnlyPlatformOwnerToPrivateSurface(): void
    {
        $client = static::createClient();
        $entityManager = static::getContainer()->get(EntityManagerInterface::class);
        self::assertInstanceOf(EntityManagerInterface::class, $entityManager);

        $user = new User(
            'owner-'.bin2hex(random_bytes(4)).'@example.test',
            'Propietario',
            [User::ROLE_PLATFORM_OWNER],
        );
        $entityManager->persist($user);
        $entityManager->flush();

        $client->loginUser($user);
        $client->request('GET', '/admin');

        self::assertResponseRedirects('/adminpl0n3r');
    }
}
