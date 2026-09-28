<?php

declare(strict_types=1);

namespace App\Tests\Http;

use App\Domain\Identity\Entity\User;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\DomCrawler\Crawler;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

final class PasswordSecurityControllerTest extends WebTestCase
{
    public function testAnonymousRecoveryPagesArePrivateAndNavigable(): void
    {
        $client = static::createClient();

        foreach ([
            '/admin/recuperar-contrasena' => 'Recuperar contraseña',
            '/admin/restablecer-contrasena' => 'Restablecer contraseña',
        ] as $path => $heading) {
            $crawler = $client->request('GET', $path);

            self::assertResponseIsSuccessful();
            self::assertSelectorTextContains('h1', $heading);
            self::assertPrivateHeaders($client);
            self::assertSame(
                'no-referrer',
                $crawler->filter('meta[name="referrer"]')->attr('content'),
            );
        }
    }

    public function testResetRequestUsesCsrfAndGenericAntiEnumerationResponse(): void
    {
        $client = static::createClient();
        $crawler = $client->request('GET', '/admin/recuperar-contrasena');

        $client->request('POST', '/admin/recuperar-contrasena', [
            'email' => 'unknown-'.bin2hex(random_bytes(4)).'@example.test',
            '_csrf_token' => self::csrf($crawler),
        ]);

        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains(
            'output.alert',
            'Si el correo corresponde a una cuenta activa',
        );
        self::assertPrivateHeaders($client);
    }

    public function testAnonymousRecoveryPostsRejectInvalidCsrf(): void
    {
        $client = static::createClient();

        foreach ([
            '/admin/recuperar-contrasena' => [
                'email' => 'unknown@example.test',
            ],
            '/admin/restablecer-contrasena' => [
                'token' => str_repeat('a', 64),
                'password' => 'Valid-password-123!',
                'password_confirmation' => 'Valid-password-123!',
            ],
        ] as $path => $payload) {
            $client->request('POST', $path, $payload + [
                '_csrf_token' => 'invalid-csrf-token',
            ]);

            self::assertResponseStatusCodeSame(403);
            self::assertPrivateHeaders($client);
        }
    }

    public function testPasswordChangeRequiresAuthenticationAndValidCsrf(): void
    {
        $client = static::createClient();
        $client->request('GET', '/admin/seguridad/contrasena');
        self::assertResponseRedirects('/admin/login');

        $entityManager = static::getContainer()->get(EntityManagerInterface::class);
        $hasher = static::getContainer()->get(UserPasswordHasherInterface::class);
        self::assertInstanceOf(EntityManagerInterface::class, $entityManager);
        self::assertInstanceOf(UserPasswordHasherInterface::class, $hasher);

        $user = new User(
            'password-ui-'.bin2hex(random_bytes(4)).'@example.test',
            'Password UI',
        );
        $user->setPasswordHash(
            $hasher->hashPassword($user, 'Current-secure-password-123!'),
        );
        $entityManager->persist($user);
        $entityManager->flush();
        $connection = $entityManager->getConnection();

        try {
            $client->loginUser($user);
            $crawler = $client->request('GET', '/admin/seguridad/contrasena');
            self::assertResponseIsSuccessful();
            self::assertSelectorTextContains('h1', 'Cambiar contraseña');
            self::assertPrivateHeaders($client);

            $client->request('POST', '/admin/seguridad/contrasena', [
                'current_password' => 'Current-secure-password-123!',
                'new_password' => 'Changed-secure-password-456!',
                'password_confirmation' => 'Changed-secure-password-456!',
                '_csrf_token' => 'invalid-csrf-token',
            ]);
            self::assertResponseStatusCodeSame(403);
            self::assertPrivateHeaders($client);

            $client->request('POST', '/admin/seguridad/contrasena', [
                'current_password' => 'Wrong-current-password-123!',
                'new_password' => 'Changed-secure-password-456!',
                'password_confirmation' => 'Changed-secure-password-456!',
                '_csrf_token' => self::csrf($crawler),
            ]);
            self::assertResponseIsSuccessful();
            self::assertSelectorTextContains(
                '.alert-error',
                'La contraseña actual no es válida.',
            );
            self::assertPrivateHeaders($client);
        } finally {
            self::deleteUser($connection, $user->id());
        }
    }

    private static function csrf(Crawler $crawler): string
    {
        return (string) $crawler
            ->filter('input[name="_csrf_token"]')
            ->attr('value');
    }

    private static function assertPrivateHeaders(KernelBrowser $client): void
    {
        self::assertResponseHeaderSame('Referrer-Policy', 'no-referrer');
        self::assertTrue(
            $client->getResponse()->headers->hasCacheControlDirective('no-store'),
        );
        self::assertTrue(
            $client->getResponse()->headers->hasCacheControlDirective('private'),
        );
    }

    private static function deleteUser(Connection $connection, string $userId): void
    {
        $connection->executeStatement(
            'DELETE FROM condor_platform_audit_event WHERE actor_user_id = :id',
            ['id' => $userId],
        );
        $connection->executeStatement(
            'DELETE FROM condor_password_reset WHERE user_id = :id',
            ['id' => $userId],
        );
        $connection->executeStatement(
            'DELETE FROM condor_user WHERE id = :id',
            ['id' => $userId],
        );
    }
}
