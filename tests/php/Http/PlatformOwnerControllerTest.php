<?php

declare(strict_types=1);

namespace App\Tests\Http;

use App\Domain\Identity\Entity\User;
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
        self::assertSelectorTextContains('h1', 'Administración global');
        self::assertSelectorTextContains('.eyebrow', 'Propietario de plataforma');
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
